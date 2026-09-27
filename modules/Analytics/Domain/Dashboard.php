<?php
declare(strict_types=1);

namespace Modules\Analytics\Domain;

use App\Kernel;

/**
 * Панель § 13: у каждой метрики есть цель и понятно, что делать, если ниже.
 * Всё, что не влияет на решения, не отслеживается. DAU и время в
 * приложении не считаем вовсе — их рост для нас скорее плохой знак.
 */
final class Dashboard
{
    public function __construct(private Kernel $kernel)
    {
    }

    public function build(?string $today = null): array
    {
        $today   = $today ?? gmdate('Y-m-d');
        $metrics = [];

        $add = static function (string $group, string $key, ?float $value, float $target, string $unit = '%', string $better = 'higher', int $n = 0) use (&$metrics): void {
            $metrics[] = [
                'group'  => $group, 'key' => $key, 'value' => $value === null ? null : round($value, 1),
                'target' => $target, 'unit' => $unit, 'better' => $better, 'n' => $n,
                'status' => $value === null || $n === 0 ? 'none' : (($better === 'higher' ? $value >= $target : $value <= $target) ? 'ok' : 'bad'),
            ];
        };

        // ---------- активация ----------
        $registered = $this->users('registered');
        $completed  = $this->users('onboarding_completed');
        $plans      = $this->firstDays('plan_built');
        $add('activation', 'onboarding_done', self::pct(count(array_intersect_key($completed, $registered)), count($registered)), 70, n: count($registered));
        $add('activation', 'plan_built', self::pct(count(array_intersect_key($plans, $completed)), count($completed)), 90, n: count($completed));

        $checkins = $this->checkinDays();   // user_id => [day => value]
        $first24 = 0; $week3 = 0; $weekBase = 0; $ch1 = 0; $ch1Base = 0;
        foreach ($plans as $uid => $planDay) {
            $days = $checkins[$uid] ?? [];
            $d1   = self::shift($planDay, 1);
            foreach ($days as $day => $v) {
                if ($day <= $d1 && $v > 0) { $first24++; break; }
            }
            if (self::shift($planDay, 7) <= $today) {
                $weekBase++;
                $n = count(array_filter(array_keys($days), static fn($d) => $d >= $planDay && $d <= self::shift($planDay, 6)));
                if ($n >= 3) { $week3++; }
            }
            if (self::shift($planDay, 30) <= $today) {
                $ch1Base++;
                $n = count(array_filter(array_keys($days), static fn($d) => $d >= $planDay && $d <= self::shift($planDay, 29)));
                if ($n >= 18) { $ch1++; }
            }
        }
        $add('activation', 'first_action_24h', self::pct($first24, count($plans)), 60, n: count($plans));
        $add('activation', 'three_checkins_week1', self::pct($week3, $weekBase), 55, n: $weekBase);

        // ---------- удержание ----------
        foreach ([7 => 75, 30 => 55, 90 => 40, 180 => 30] as $n => $target) {
            $base = 0; $kept = 0;
            foreach ($plans as $uid => $planDay) {
                $mark = self::shift($planDay, $n);
                if ($mark > $today) { continue; }
                $base++;
                foreach (array_keys($checkins[$uid] ?? []) as $day) {
                    if ($day >= $mark) { $kept++; break; }
                }
            }
            $add('retention', 'd' . $n, self::pct($kept, $base), $target, n: $base);
        }
        $add('retention', 'chapter1_done', self::pct($ch1, $ch1Base), 55, n: $ch1Base);

        // ---------- главное: Kept Week ----------
        $weeks = $this->kernel->db()->all(
            "SELECT value FROM analytics_events WHERE name = 'week_closed' AND created_at >= ?",
            [gmdate('c', strtotime($today . ' 00:00:00 UTC') - 7 * 86400)]
        );
        $keptN = count(array_filter($weeks, static fn($w) => (float) $w['value'] >= 1));
        $add('core', 'kept_week', self::pct($keptN, count($weeks)), 60, n: count($weeks));

        // ---------- деньги: конверсия из Нулевого цикла ----------
        $paid = $this->users('season_activated');
        $old  = array_filter($registered, static fn($day) => self::shift($day, 14) <= $today);
        $add('business', 'trial_to_paid', self::pct(count(array_intersect_key($paid, $old)), count($old)), 25, n: count($old));

        // ---------- то, чем владеют другие модули ----------
        $collected = $this->kernel->events->emit('analytics.collect', ['today' => $today, 'metrics' => []]);
        foreach ((array) $collected['metrics'] as $m) {
            $add((string) $m['group'], (string) $m['key'], isset($m['value']) ? (float) $m['value'] : null,
                (float) $m['target'], (string) ($m['unit'] ?? '%'), (string) ($m['better'] ?? 'higher'), (int) ($m['n'] ?? 0));
        }

        $order = ['core' => 0, 'activation' => 1, 'retention' => 2, 'squads' => 3, 'coach' => 4, 'business' => 5, 'safety' => 6];
        usort($metrics, static fn($a, $b) => [$order[$a['group']] ?? 9] <=> [$order[$b['group']] ?? 9]);

        return ['today' => $today, 'metrics' => $metrics, 'cohorts' => $this->cohorts($registered, $completed, $paid, $plans, $checkins, $today)];
    }

    /** Когорты по неделе регистрации: сколько дошло до плана, до оплаты и до D7. */
    private function cohorts(array $registered, array $completed, array $paid, array $plans, array $checkins, string $today): array
    {
        $out = [];
        foreach ($registered as $uid => $day) {
            $week = gmdate('o-\WW', strtotime($day . ' 00:00:00 UTC'));
            $out[$week] ??= ['week' => $week, 'size' => 0, 'onboarded' => 0, 'paid' => 0, 'd7' => 0, 'd7_base' => 0];
            $out[$week]['size']++;
            if (isset($completed[$uid])) { $out[$week]['onboarded']++; }
            if (isset($paid[$uid]))      { $out[$week]['paid']++; }
            if (isset($plans[$uid]) && self::shift($plans[$uid], 7) <= $today) {
                $out[$week]['d7_base']++;
                foreach (array_keys($checkins[$uid] ?? []) as $d) {
                    if ($d >= self::shift($plans[$uid], 7)) { $out[$week]['d7']++; break; }
                }
            }
        }
        krsort($out);
        return array_values(array_slice($out, 0, 12));
    }

    /** @return array<int, string> user_id => день первого события */
    private function users(string $name): array
    {
        $out = [];
        foreach ($this->kernel->db()->all(
            'SELECT user_id, MIN(day) AS day FROM analytics_events WHERE name = ? AND user_id IS NOT NULL GROUP BY user_id',
            [$name]
        ) as $r) {
            $out[(int) $r['user_id']] = (string) $r['day'];
        }
        return $out;
    }

    private function firstDays(string $name): array
    {
        return $this->users($name);
    }

    /** @return array<int, array<string, float>> */
    private function checkinDays(): array
    {
        $out = [];
        foreach ($this->kernel->db()->all("SELECT user_id, day, value FROM analytics_events WHERE name = 'checkin' AND user_id IS NOT NULL") as $r) {
            $out[(int) $r['user_id']][(string) $r['day']] = (float) $r['value'];
        }
        return $out;
    }

    public static function pct(int $part, int $whole): ?float
    {
        return $whole > 0 ? 100.0 * $part / $whole : null;
    }

    public static function shift(string $day, int $n): string
    {
        return gmdate('Y-m-d', strtotime($day . ' 00:00:00 UTC') + $n * 86400);
    }
}
