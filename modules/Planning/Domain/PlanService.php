<?php
declare(strict_types=1);

namespace Modules\Planning\Domain;

use App\Contracts\Planner;
use App\Kernel;

/**
 * Реализация контракта Planner: построение, хранение и чтение плана.
 *
 * План версионируется: пересборка не затирает старый, а создаёт новую
 * версию и гасит предыдущую. Это нужно, чтобы на срезе 3 можно было
 * честно ответить, по какому именно плану человек работал в тот день.
 */
final class PlanService implements Planner
{
    public function __construct(private Kernel $kernel)
    {
    }

    public function hasPlan(int $userId): bool
    {
        return $this->currentPlan($userId) !== null;
    }

    public function currentPlan(int $userId): ?array
    {
        $plan = $this->kernel->db()->first(
            'SELECT * FROM planning_plans WHERE user_id = ? AND active = 1 ORDER BY version DESC LIMIT 1',
            [$userId]
        );
        if ($plan === null) {
            return null;
        }

        $plan['meta']     = json_decode((string) $plan['meta'], true) ?: [];
        $plan['chapters'] = $this->kernel->db()->all(
            'SELECT * FROM planning_chapters WHERE plan_id = ? ORDER BY n',
            [$plan['id']]
        );
        $plan['weeks'] = $this->kernel->db()->all(
            'SELECT * FROM planning_weeks WHERE plan_id = ? ORDER BY n',
            [$plan['id']]
        );
        $plan['current_day'] = $this->dayNumber($plan);

        return $plan;
    }

    public function build(int $userId, array $profile, array $baseline): ?array
    {
        $computed = Calculator::build($profile, $baseline);

        // Ограничения по нагрузке: из анкеты противопоказаний или из
        // свободного поля «нельзя бегать». Влияет на выбор действий.
        $limited = $this->hasLoadLimits($userId, $profile);

        return $this->kernel->db()->transaction(function () use ($userId, $computed, $profile, $limited) {
            $version = (int) $this->kernel->db()->value(
                'SELECT COALESCE(MAX(version), 0) + 1 FROM planning_plans WHERE user_id = ?',
                [$userId],
                1
            );

            $this->kernel->db()->run('UPDATE planning_plans SET active = 0 WHERE user_id = ?', [$userId]);

            $planId = $this->kernel->db()->insert('planning_plans', [
                'user_id'    => $userId,
                'version'    => $version,
                'active'     => 1,
                'goal_dir'   => $computed['meta']['goal_dir'],
                'tier'       => $computed['meta']['tier'],
                'start_date' => gmdate('Y-m-d'),
                'limited'    => $limited ? 1 : 0,
                'meta'       => json_encode($computed['meta'], JSON_UNESCAPED_UNICODE),
                'created_at' => gmdate('c'),
            ]);

            foreach ($computed['chapters'] as $c) {
                $this->kernel->db()->insert('planning_chapters', [
                    'plan_id'       => $planId,
                    'n'             => $c['n'],
                    'theme'         => $c['theme'],
                    'from_day'      => $c['from_day'],
                    'to_day'        => $c['to_day'],
                    'weight_target' => $c['weight_target'],
                    'steps_target'  => $c['steps_target'],
                    'minutes'       => $c['minutes'],
                ]);
            }

            foreach ($computed['weeks'] as $w) {
                $this->kernel->db()->insert('planning_weeks', [
                    'plan_id'       => $planId,
                    'n'             => $w['n'],
                    'chapter'       => $w['chapter'],
                    'from_day'      => $w['from_day'],
                    'to_day'        => $w['to_day'],
                    'deload'        => $w['deload'] ? 1 : 0,
                    'steps_target'  => $w['steps_target'],
                    'minutes'       => $w['minutes'],
                    'strength'      => $w['strength'],
                    'weight_target' => $w['weight_target'],
                    'norm_days'     => $w['norm_days'],
                    'norm_checkins' => $w['norm_checkins'],
                ]);
            }

            $this->kernel->events->emit('plan.built', [
                'user_id' => $userId,
                'plan_id' => $planId,
                'version' => $version,
                'meta'    => $computed['meta'],
            ]);

            return $this->currentPlan($userId);
        });
    }

    public function today(int $userId, ?string $date = null): ?array
    {
        $plan = $this->currentPlan($userId);
        if ($plan === null) {
            return null;
        }

        $day = $this->dayNumber($plan, $date);
        if ($day < 1 || $day > Calculator::DAYS) {
            return [
                'day'      => $day,
                'chapter'  => null,
                'week'     => null,
                'action'   => null,
                'finished' => $day > Calculator::DAYS,
            ];
        }

        $weekNumber = (int) ceil($day / 7);
        $week       = null;
        foreach ($plan['weeks'] as $row) {
            if ((int) $row['n'] === $weekNumber) {
                $week = $row;
                break;
            }
        }
        if ($week === null) {
            return null;
        }

        $action = Library::actionFor($day, $week, $plan['meta'], (int) $plan['limited'] === 1);
        $adjust = $this->adjustmentFor($userId, $date ?? gmdate('Y-m-d'));
        $factor = $adjust['factor'] ?? 1.0;

        // Облегчение: цель действия и нормы недели умножаются на коэффициент.
        // Силовая и кардио в такие дни — «половина по времени».
        if ($adjust !== null) {
            if (isset($action['target']) && is_int($action['target'])) {
                $action['target'] = max(1, (int) round($action['target'] * $factor));
            }
            $action['lighter'] = true;
        }

        return [
            'day'      => $day,
            'chapter'  => (int) $week['chapter'],
            'theme'    => Calculator::THEMES[(int) $week['chapter']] ?? 'base',
            'week'     => (int) $week['n'],
            'deload'   => (int) $week['deload'] === 1,
            'action'   => $action,
            'norm'     => [
                'days'     => (int) $week['norm_days'],
                'checkins' => (int) $week['norm_checkins'],
                'steps'    => (int) round((int) $week['steps_target'] * $factor),
                'minutes'  => (int) round((int) $week['minutes'] * $factor),
                'strength' => (int) $week['strength'],
            ],
            'adjustment' => $adjust,
            'finished' => false,
        ];
    }

    /**
     * Временное облегчение (Р-13: легче — без спроса). Повторный сигнал
     * не складывается с действующим: берётся самый сильный коэффициент,
     * а срок продлевается.
     */
    public function adjust(int $userId, float $factor, string $from, int $days, string $level, array $reasons = []): bool
    {
        if ($factor <= 0 || $factor >= 1 || $days < 1) {
            return false;   // сделать тяжелее этим путём нельзя
        }
        $to     = gmdate('Y-m-d', strtotime($from . ' 00:00:00 UTC') + ($days - 1) * 86400);
        $active = $this->kernel->db()->first(
            'SELECT * FROM planning_adjustments WHERE user_id = ? AND date_from <= ? AND date_to >= ? AND level = ? ORDER BY factor ASC LIMIT 1',
            [$userId, $from, $from, $level]
        );
        if ($active !== null && (float) $active['factor'] <= $factor && $active['date_to'] >= $to) {
            return false;   // уже действует такое же или сильнее
        }

        $this->kernel->db()->insert('planning_adjustments', [
            'user_id'    => $userId,
            'date_from'  => $from,
            'date_to'    => $to,
            'factor'     => round($factor, 2),
            'level'      => $level,
            'reasons'    => json_encode(array_values($reasons)),
            'created_at' => gmdate('c'),
        ]);
        $this->kernel->events->emit('plan.adjusted', [
            'user_id' => $userId, 'factor' => $factor, 'from' => $from, 'to' => $to, 'level' => $level, 'reasons' => $reasons,
        ]);
        return true;
    }

    /**
     * Возврат после паузы больше 7 дней (Р-14): старт с 60% последнего
     * объёма и +15% в неделю, пока не догонит план.
     */
    public function rampAfterPause(int $userId, string $from): void
    {
        foreach ([0.6, 0.75, 0.9] as $i => $f) {
            $start = gmdate('Y-m-d', strtotime($from . ' 00:00:00 UTC') + $i * 7 * 86400);
            $this->adjust($userId, $f, $start, 7, 'ramp', ['return_after_pause']);
        }
    }

    /** @return ?array{factor: float, level: string, until: string, reasons: array} */
    public function adjustmentFor(int $userId, string $date): ?array
    {
        $row = $this->kernel->db()->first(
            'SELECT * FROM planning_adjustments WHERE user_id = ? AND date_from <= ? AND date_to >= ? ORDER BY factor ASC, date_to DESC LIMIT 1',
            [$userId, $date, $date]
        );
        if ($row === null) {
            return null;
        }
        $until = (string) $this->kernel->db()->value(
            'SELECT MAX(date_to) FROM planning_adjustments WHERE user_id = ? AND date_from <= ? AND date_to >= ? AND level = ?',
            [$userId, $date, $date, $row['level']]
        );
        return [
            'factor'  => (float) $row['factor'],
            'level'   => (string) $row['level'],
            'until'   => $until,
            'reasons' => json_decode((string) $row['reasons'], true) ?: [],
        ];
    }

    /** Номер дня плана: 1 в день старта. */
    private function dayNumber(array $plan, ?string $date = null): int
    {
        $start = strtotime((string) $plan['start_date'] . ' 00:00:00 UTC');
        $now   = strtotime(($date ?? gmdate('Y-m-d')) . ' 00:00:00 UTC');

        if ($start === false || $now === false) {
            return 1;
        }
        return (int) floor(($now - $start) / 86400) + 1;
    }

    private function hasLoadLimits(int $userId, array $profile): bool
    {
        if (trim((string) ($profile['constraints'] ?? '')) !== '') {
            return true;
        }

        // Модуль Onboarding владеет таблицей скрининга, но флаг «нужен врач»
        // напрямую влияет на нагрузку, поэтому читаем его через событие,
        // а не запросом к чужой таблице.
        $result = $this->kernel->events->emit('planning.load_limits', [
            'user_id' => $userId,
            'limited' => false,
        ]);

        return (bool) ($result['limited'] ?? false);
    }
}
