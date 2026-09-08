<?php
declare(strict_types=1);

namespace Modules\Checkin\Domain;

use App\Kernel;

/**
 * Недельная серия и щиты.
 *
 * Серия считается неделями, а не днями (решение Р-09). Дневная серия
 * создаёт эффект «всё или ничего»: один пропуск обесценивает результат,
 * и человек уходит. Недельная позволяет пропустить и всё равно закрыть
 * неделю победой.
 *
 * Щит спасает неделю, в которой не хватило ровно одного дня. Два щита
 * в календарный месяц (§ 06 досье). Больше — и норма перестаёт что-либо
 * значить; меньше — и одна командировка рвёт трёхмесячную серию.
 */
final class Streak
{
    public const SHIELDS_PER_MONTH = 2;

    public function __construct(private Kernel $kernel, private Week $week)
    {
    }

    public function shieldsLeft(int $userId, ?string $date = null): int
    {
        $month = substr($date ?? gmdate('Y-m-d'), 0, 7);
        $used  = (int) $this->kernel->db()->value(
            'SELECT used FROM checkin_shields WHERE user_id = ? AND month = ?',
            [$userId, $month],
            0
        );
        return max(0, self::SHIELDS_PER_MONTH - $used);
    }

    private function consumeShield(int $userId, string $date): bool
    {
        if ($this->shieldsLeft($userId, $date) <= 0) {
            return false;
        }

        $month = substr($date, 0, 7);
        $row   = $this->kernel->db()->first(
            'SELECT used FROM checkin_shields WHERE user_id = ? AND month = ?',
            [$userId, $month]
        );

        if ($row === null) {
            $this->kernel->db()->insert('checkin_shields', [
                'user_id' => $userId, 'month' => $month, 'used' => 1,
            ]);
        } else {
            $this->kernel->db()->run(
                'UPDATE checkin_shields SET used = used + 1 WHERE user_id = ? AND month = ?',
                [$userId, $month]
            );
        }
        return true;
    }

    /**
     * Пересчёт недели. Вызывается после каждого чек-ина, поэтому должен
     * быть идемпотентным: повторный вызов не должен второй раз тратить щит.
     *
     * @return array{done_days:int,checkins:int,norm:array,kept:bool,shield_used:bool,closed:bool}
     */
    public function recompute(int $userId, string $weekStart, bool $closing = false): array
    {
        $days = $this->week->days($weekStart);
        $norm = $this->week->norm($userId, $weekStart);

        $rows = $this->kernel->db()->all(
            'SELECT done FROM checkin_days WHERE user_id = ? AND date >= ? AND date <= ?',
            [$userId, $days[0], $days[6]]
        );

        $checkins = count($rows);
        $doneDays = 0;
        foreach ($rows as $r) {
            // Частично выполненный день засчитывается: продукт награждает
            // возвращение и честность, а не идеальность.
            if ($r['done'] === 'yes' || $r['done'] === 'partial') {
                $doneDays++;
            }
        }

        $existing   = $this->kernel->db()->first(
            'SELECT * FROM checkin_weeks WHERE user_id = ? AND week_start = ?',
            [$userId, $weekStart]
        );
        $shieldUsed = $existing !== null && (int) $existing['shield_used'] === 1;

        $kept = $doneDays >= $norm['days'] && $checkins >= $norm['checkins'];

        // Неделя, уже спасённая щитом, остаётся выполненной при любом
        // повторном пересчёте. Без этого второй вызов recompute() снимал
        // бы зачёт, потому что дней по-прежнему не хватает, а щит
        // повторно не тратится.
        if ($shieldUsed) {
            $kept = true;
        }

        // Щит тратим только при закрытии недели и только если не хватило
        // ровно одного дня — иначе он превращается в способ не работать.
        if (!$kept && $closing && !$shieldUsed
            && $doneDays === $norm['days'] - 1
            && $checkins >= $norm['checkins']
            && $this->consumeShield($userId, $weekStart)) {
            $kept       = true;
            $shieldUsed = true;
        }

        $data = [
            'done_days'   => $doneDays,
            'checkins'    => $checkins,
            'norm_days'   => $norm['days'],
            'kept'        => $kept ? 1 : 0,
            'shield_used' => $shieldUsed ? 1 : 0,
            'closed_at'   => $closing ? gmdate('c') : ($existing['closed_at'] ?? null),
        ];

        if ($existing === null) {
            $this->kernel->db()->insert('checkin_weeks', $data + [
                'user_id' => $userId, 'week_start' => $weekStart,
            ]);
        } else {
            $this->kernel->db()->update(
                'checkin_weeks', $data,
                'user_id = :uid AND week_start = :ws',
                ['uid' => $userId, 'ws' => $weekStart]
            );
        }

        return [
            'done_days'   => $doneDays,
            'checkins'    => $checkins,
            'norm'        => $norm,
            'kept'        => $kept,
            'shield_used' => $shieldUsed,
            'closed'      => $closing,
        ];
    }

    /** Число подряд идущих недель с выполненной нормой, считая от последней закрытой. */
    public function current(int $userId): int
    {
        $weeks = $this->kernel->db()->all(
            'SELECT week_start, kept FROM checkin_weeks
             WHERE user_id = ? AND closed_at IS NOT NULL
             ORDER BY week_start DESC',
            [$userId]
        );

        $streak = 0;
        foreach ($weeks as $w) {
            if ((int) $w['kept'] !== 1) {
                break;
            }
            $streak++;
        }
        return $streak;
    }

    /**
     * Закрывает все недели, которые уже завершились, но ещё не закрыты.
     * Вызывается при любом обращении пользователя — отдельный планировщик
     * на дешёвом хостинге ненадёжен.
     *
     * @return array<int, array<string, mixed>> закрытые недели
     */
    public function closeFinishedWeeks(int $userId, ?string $today = null): array
    {
        $today       = $today ?? gmdate('Y-m-d');
        $currentWeek = $this->week->startFor($userId, $today);

        $open = $this->kernel->db()->all(
            'SELECT week_start FROM checkin_weeks
             WHERE user_id = ? AND closed_at IS NULL AND week_start < ?
             ORDER BY week_start',
            [$userId, $currentWeek]
        );

        $closed = [];
        foreach ($open as $row) {
            $result                = $this->recompute($userId, (string) $row['week_start'], true);
            $result['week_start']  = (string) $row['week_start'];
            $closed[]              = $result;
        }
        return $closed;
    }
}
