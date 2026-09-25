<?php
declare(strict_types=1);

namespace Modules\Squad\Domain;

use App\Kernel;

/**
 * Командный счёт недели (Р-08, § 09 досье).
 *
 *   очки = медиана(выполнение) × (0,6 + 0,4 × минимум / 100)
 *                              × (1 + 0,15 × доля вернувшихся)
 *
 *   выполнение = % от ЛИЧНОЙ недельной нормы, не больше 100.
 *
 * Сильный не может «вытянуть» команду объёмом: выше 100% не бывает, а
 * отстающий режет множитель всем. Помочь ему выгоднее, чем стараться
 * самому. Килограммы и шаги в счёт не входят вовсе.
 *
 * Данные чек-инов принадлежат модулю Checkin — мы спрашиваем их событием
 * squad.member_weeks. Если модуль чек-ина выключен, неделю просто нечем
 * посчитать, и счёт не пишется.
 */
final class Scoring
{
    public function __construct(private Kernel $kernel)
    {
    }

    /**
     * @param array<int, float> $pcts выполнение каждого участника, 0..100
     * @return array{median: float, min: float, coef: float, returned_share: float, score: float}
     */
    public static function score(array $pcts, int $returned = 0): array
    {
        if ($pcts === []) {
            return ['median' => 0.0, 'min' => 0.0, 'coef' => 0.6, 'returned_share' => 0.0, 'score' => 0.0];
        }
        $pcts   = array_map(static fn($p) => max(0.0, min(100.0, (float) $p)), $pcts);
        $median = Matcher::median($pcts);
        $min    = min($pcts);
        $coef   = 0.6 + 0.4 * ($min / 100);
        $share  = min(1.0, $returned / count($pcts));
        $score  = $median * $coef * (1 + 0.15 * $share);

        return [
            'median'         => round($median, 1),
            'min'            => round($min, 1),
            'coef'           => round($coef, 3),
            'returned_share' => round($share, 3),
            'score'          => round($score, 1),
        ];
    }

    /** Номер недели сквада и её первый день для даты. */
    public static function weekOf(string $startedOn, string $date): array
    {
        $start = strtotime($startedOn . ' 00:00:00 UTC');
        $point = strtotime($date . ' 00:00:00 UTC');
        $idx   = (int) floor(($point - $start) / 86400 / 7);
        return ['no' => $idx + 1, 'start' => gmdate('Y-m-d', $start + $idx * 7 * 86400)];
    }

    /**
     * Досчитывает закрытые недели сквада, которые ещё не посчитаны.
     * Ленивый пересчёт при открытии экрана: планировщику задач на дешёвом
     * хостинге мы не доверяем (как и в модуле чек-ина).
     *
     * @return array<int, array> посчитанные сейчас недели
     */
    public function scoreClosedWeeks(array $squad, ?string $today = null): array
    {
        if (empty($squad['started_on'])) {
            return [];
        }
        $today   = $today ?? gmdate('Y-m-d');
        $current = self::weekOf((string) $squad['started_on'], $today);
        $done    = [];

        for ($no = 1; $no < $current['no']; $no++) {
            $exists = $this->kernel->db()->value(
                'SELECT 1 FROM squad_week_scores WHERE squad_id = ? AND week_no = ?',
                [(int) $squad['id'], $no]
            );
            if ($exists !== null) {
                continue;
            }

            $from = gmdate('Y-m-d', strtotime($squad['started_on'] . ' 00:00:00 UTC') + ($no - 1) * 7 * 86400);
            $to   = gmdate('Y-m-d', strtotime($from . ' 00:00:00 UTC') + 6 * 86400);

            // В неделе считаются те, кто был в скваде хотя бы её часть.
            $userIds = array_map('intval', array_column($this->kernel->db()->all(
                'SELECT user_id FROM squad_members
                 WHERE squad_id = ? AND joined_on <= ? AND (left_on IS NULL OR left_on > ?)',
                [(int) $squad['id'], $to, $from]
            ), 'user_id'));
            if ($userIds === []) {
                continue;
            }

            $answer = $this->kernel->events->emit('squad.member_weeks', [
                'user_ids'   => $userIds,
                'week_start' => $from,
                'week_end'   => $to,
                'stats'      => [],
            ]);
            $stats = (array) ($answer['stats'] ?? []);
            if ($stats === []) {
                break;   // чек-инов нет (модуль выключен) — считать нечем
            }

            $pcts     = [];
            $returned = 0;
            foreach ($userIds as $uid) {
                $s    = $stats[$uid] ?? ['done_days' => 0, 'norm_days' => 4, 'returned' => false];
                $norm = max(1, (int) ($s['norm_days'] ?? 4));
                $pcts[] = min(100.0, 100.0 * (int) ($s['done_days'] ?? 0) / $norm);
                if (!empty($s['returned'])) {
                    $returned++;
                }
            }

            $r = self::score($pcts, $returned);
            $this->kernel->db()->run(
                'INSERT OR IGNORE INTO squad_week_scores
                   (squad_id, week_no, week_start, members, median_pct, min_pct, returned_share, score, computed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [(int) $squad['id'], $no, $from, count($pcts), $r['median'], $r['min'], $r['returned_share'], $r['score'], gmdate('c')]
            );

            $this->kernel->events->emit('squad.week_scored', [
                'squad_id' => (int) $squad['id'], 'week_no' => $no, 'week_start' => $from,
                'score' => $r['score'], 'user_ids' => $userIds,
            ]);
            $done[] = ['week_no' => $no] + $r;
        }

        return $done;
    }

    /** Живой счёт текущей недели — показывается как «пока». */
    public function currentWeek(array $squad, ?string $today = null): ?array
    {
        if (empty($squad['started_on'])) {
            return null;
        }
        $today = $today ?? gmdate('Y-m-d');
        if ($today < $squad['started_on']) {
            return null;
        }
        $week = self::weekOf((string) $squad['started_on'], $today);

        $userIds = array_map('intval', array_column($this->kernel->db()->all(
            "SELECT user_id FROM squad_members WHERE squad_id = ? AND status <> 'left'",
            [(int) $squad['id']]
        ), 'user_id'));
        if ($userIds === []) {
            return null;
        }

        $answer = $this->kernel->events->emit('squad.member_weeks', [
            'user_ids'   => $userIds,
            'week_start' => $week['start'],
            'week_end'   => gmdate('Y-m-d', strtotime($week['start'] . ' 00:00:00 UTC') + 6 * 86400),
            'stats'      => [],
        ]);
        $stats = (array) ($answer['stats'] ?? []);
        if ($stats === []) {
            return null;
        }

        $pcts     = [];
        $returned = 0;
        foreach ($userIds as $uid) {
            $s      = $stats[$uid] ?? ['done_days' => 0, 'norm_days' => 4];
            $pcts[] = min(100.0, 100.0 * (int) ($s['done_days'] ?? 0) / max(1, (int) ($s['norm_days'] ?? 4)));
            if (!empty($s['returned'])) {
                $returned++;
            }
        }

        return ['week_no' => $week['no'], 'week_start' => $week['start']] + self::score($pcts, $returned);
    }

    public function history(int $squadId): array
    {
        return $this->kernel->db()->all(
            'SELECT week_no, week_start, members, median_pct, min_pct, returned_share, score
             FROM squad_week_scores WHERE squad_id = ? ORDER BY week_no',
            [$squadId]
        );
    }
}
