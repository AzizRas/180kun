<?php
declare(strict_types=1);

namespace Modules\Coach\Domain;

use App\Contracts\Auth;
use App\Contracts\Planner;
use App\Contracts\Wearable;
use App\Kernel;

/**
 * Сборка контекста для тренера.
 *
 * Тренер не читает чужие таблицы. Профиль, чек-ины и вес ему
 * присылают владельцы данных по событию coach.context; браслет и план —
 * через контракты. Всё сводится в три вещи:
 *
 *  - window  — 14 дней для классификатора и флагов нагрузки;
 *  - facts   — числа из данных человека, которыми обязан оперировать текст;
 *  - profile — обезличенный профиль для модели (Р-19): псевдоним вместо
 *              id, ни имени, ни телефона, ни Telegram ID, ни свободного текста.
 */
final class Context
{
    public const WINDOW = 14;

    public function __construct(private Kernel $kernel)
    {
    }

    public function build(int $userId, ?string $today = null): array
    {
        $today = $today ?? gmdate('Y-m-d');
        $from  = self::shift($today, -(self::WINDOW - 1));

        $asked = $this->kernel->events->emit('coach.context', [
            'user_id'      => $userId,
            'from'         => $from,
            'to'           => $today,
            'profile'      => null,
            'baseline'     => null,
            'checkins'     => [],
            'week'         => null,
            'streak'       => 0,
            'event_active' => false,
            'event_type'   => null,
            'weights'      => [],
        ]);

        /** @var Wearable $wear */
        $wear = $this->kernel->container->get(Wearable::class);
        $byDate = [];
        foreach ((array) $asked['checkins'] as $c) {
            $byDate[$c['date']] = $c;
        }

        $days = [];
        for ($i = self::WINDOW - 1; $i >= 0; $i--) {
            $d = self::shift($today, -$i);
            $c = $byDate[$d] ?? null;
            $m = $wear->dayMetrics($userId, $d);
            $days[] = [
                'date'       => $d,
                'checked'    => $c !== null,
                'done'       => $c['done'] ?? null,
                'energy'     => isset($c['energy']) ? (int) $c['energy'] : null,
                'mood'       => isset($c['mood']) ? (int) $c['mood'] : null,
                'steps'      => $m['steps'],
                'active_min' => $m['active_min'],
                'rhr'        => $m['rhr'],
                'sleep_min'  => $m['sleep_min'],
            ];
        }

        $wearBase = $wear->baseline($userId, 30);
        $onbBase  = (array) ($asked['baseline'] ?? []);
        $rhrBase  = isset($onbBase['rhr']) && $onbBase['rhr'] !== null ? (int) $onbBase['rhr'] : ($wearBase['rhr'] ?? null);

        /** @var Planner $planner */
        $planner = $this->kernel->container->get(Planner::class);
        $plan    = $planner->today($userId, $today);

        $window = [
            'today'        => $today,
            'days'         => $days,
            'event_active' => (bool) $asked['event_active'],
            'event_type'   => $asked['event_type'],
            'rhr_base'     => $rhrBase !== null ? (int) $rhrBase : null,
            'weights'      => (array) $asked['weights'],
        ];

        return [
            'window'  => $window,
            'facts'   => $this->facts($days, (array) ($asked['week'] ?? []), (int) $asked['streak'], $plan, $window, $wearBase),
            'profile' => $this->anonymous($userId, (array) ($asked['profile'] ?? []), $onbBase, $plan),
            'plan'    => $plan,
            'lang'    => $this->lang($userId),
        ];
    }

    /**
     * Числа, на которые текст обязан опираться (правило двух чисел).
     *
     * @return array<string, int|float>
     */
    private function facts(array $days, array $week, int $streak, ?array $plan, array $window, array $wearBase): array
    {
        $last7 = array_slice($days, -7);
        $prev7 = array_slice($days, 0, 7);
        $avg   = static function (array $rows, string $f): ?float {
            $v = array_values(array_filter(array_column($rows, $f), static fn($x) => $x !== null));
            return $v ? array_sum($v) / count($v) : null;
        };
        $checks7 = array_values(array_filter($last7, static fn($d) => $d['checked']));

        $f = [
            'done'      => (int) ($week['done_days'] ?? count(array_filter($checks7, static fn($d) => in_array($d['done'], ['yes', 'partial'], true)))),
            'norm'      => (int) ($week['norm_days'] ?? 4),
            'checkins7' => count($checks7),
            'done7'     => count(array_filter($checks7, static fn($d) => in_array($d['done'], ['yes', 'partial'], true))),
            'streak'    => $streak,
        ];
        $f['left'] = max(0, $f['norm'] - $f['done']);

        if ($plan !== null && isset($plan['day']) && $plan['day'] >= 1) {
            $f['day'] = (int) $plan['day'];
        }
        if (($e = $avg($checks7, 'energy')) !== null) {
            $f['energy_avg'] = round($e, 1);
        }

        $stepsWeek = $avg($last7, 'steps');
        $stepsPrev = $avg($prev7, 'steps');
        if ($stepsWeek !== null) { $f['steps_week'] = (int) round($stepsWeek); }
        if ($stepsPrev !== null) { $f['steps_prev'] = (int) round($stepsPrev); }
        if (($wearBase['steps_med'] ?? null) !== null) { $f['steps_med'] = (int) $wearBase['steps_med']; }

        $yesterday = $days[count($days) - 2] ?? null;
        if ($yesterday !== null && $yesterday['steps'] !== null) {
            $f['steps_yesterday'] = (int) $yesterday['steps'];
        }

        $sleep = array_values(array_filter(array_column($last7, 'sleep_min'), static fn($x) => $x !== null));
        if ($sleep) {
            $f['sleep_last_h'] = round(end($sleep) / 60, 1);
            $f['sleep_avg_h']  = round(array_sum($sleep) / count($sleep) / 60, 1);
        }

        $rhr = array_values(array_filter(array_column($last7, 'rhr'), static fn($x) => $x !== null));
        if ($rhr) {
            $f['rhr_last'] = (int) end($rhr);
        }
        if ($window['rhr_base'] !== null) {
            $f['rhr_base'] = (int) $window['rhr_base'];
        }

        $w = array_values((array) $window['weights']);
        if (count($w) >= 2) {
            $f['weight_delta'] = round((float) $w[count($w) - 1]['weight_kg'] - (float) $w[0]['weight_kg'], 1);
            $f['weight_weeks'] = count($w) - 1;
        }

        if (!empty($plan['adjustment'])) {
            $f['adj_pct']  = (int) round((1 - (float) $plan['adjustment']['factor']) * 100);
            $f['adj_days'] = max(1, (int) floor((strtotime($plan['adjustment']['until'] . ' 00:00:00 UTC') - strtotime($window['today'] . ' 00:00:00 UTC')) / 86400) + 1);
        }
        if (!empty($plan['norm']['steps'])) {
            $f['steps_target'] = (int) $plan['norm']['steps'];
        }

        return $f;
    }

    /** Обезличенный профиль: только то, что нужно для формулировки. */
    private function anonymous(int $userId, array $profile, array $baseline, ?array $plan): array
    {
        return [
            'pid'         => substr(hash_hmac('sha256', 'coach:' . $userId, $this->kernel->secret()), 0, 12),
            'age'         => $profile['age'] ?? null,
            'sex'         => $profile['sex'] ?? null,
            'height_cm'   => $profile['height_cm'] ?? null,
            'weight_kg'   => $profile['weight_kg'] ?? null,
            'goal_dir'    => $profile['goal_dir'] ?? null,
            'tier'        => $profile['tier'] ?? null,
            'time_budget' => $profile['time_budget'] ?? null,
            'window'      => $profile['window'] ?? null,
            'social'      => $profile['social'] ?? null,
            'experience'  => $profile['experience'] ?? null,     // код варианта, не свободный текст
            'fasting'     => (bool) ($profile['fasting'] ?? false),
            'limited'     => (bool) ($plan !== null && !empty($plan['action']['lighter'])),
            'baseline_steps' => isset($baseline['steps_med']) ? (int) $baseline['steps_med'] : null,
        ];
    }

    private function lang(int $userId): string
    {
        $u = $this->kernel->container->get(Auth::class)->userById($userId);
        return in_array($u['lang'] ?? 'ru', ['ru', 'uz'], true) ? (string) $u['lang'] : 'ru';
    }

    public static function shift(string $date, int $days): string
    {
        return gmdate('Y-m-d', strtotime($date . ' 00:00:00 UTC') + $days * 86400);
    }
}
