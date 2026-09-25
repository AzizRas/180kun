<?php
declare(strict_types=1);

namespace Modules\Checkin;

use App\BaseModule;
use App\Container;
use App\Kernel;
use Modules\Checkin\Domain\Checkins;
use Modules\Checkin\Domain\Recovery;
use Modules\Checkin\Domain\Streak;
use Modules\Checkin\Domain\Week;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(Week::class, static fn() => new Week($kernel), 'checkin');
        $container->singleton(
            Streak::class,
            static fn(Container $c) => new Streak($kernel, $c->get(Week::class)),
            'checkin'
        );
        $container->singleton(Recovery::class, static fn() => new Recovery($kernel), 'checkin');
        $container->singleton(
            Checkins::class,
            static fn(Container $c) => new Checkins(
                $kernel,
                $c->get(Week::class),
                $c->get(Streak::class),
                $c->get(Recovery::class)
            ),
            'checkin'
        );
    }

    public function boot(Kernel $kernel): void
    {
        // Модуль очков не знает про наши таблицы и спрашивает событием.
        $kernel->events->on('gamification.extras', static function (array $p) use ($kernel): array {
            $userId = (int) ($p['user_id'] ?? 0);
            if ($userId === 0) {
                return $p;
            }

            $row = $kernel->db()->first(
                'SELECT streak, best_streak FROM checkin_state WHERE user_id = ?',
                [$userId]
            );

            $p['streak']      = (int) ($row['streak'] ?? 0);
            $p['best_streak'] = (int) ($row['best_streak'] ?? 0);
            $p['shields']     = $kernel->container->get(Streak::class)->shieldsLeft($userId);

            return $p;
        }, 'checkin');

        // Сквадам нужно знать, кто когда отмечался последний раз (пауза,
        // восстановление, бездействие лидера). Наши таблицы они не читают —
        // спрашивают, мы отвечаем.
        $kernel->events->on('squad.activity', static function (array $p) use ($kernel): array {
            $ids = array_values(array_filter(array_map('intval', (array) ($p['user_ids'] ?? []))));
            $out = [];
            foreach ($ids as $id) {
                $out[$id] = null;
            }
            if ($ids !== []) {
                $rows = $kernel->db()->all(
                    'SELECT user_id, MAX(date) AS last FROM checkin_days WHERE user_id IN (' . implode(',', $ids) . ') GROUP BY user_id'
                );
                foreach ($rows as $r) {
                    $out[(int) $r['user_id']] = $r['last'];
                }
            }
            $p['last_dates'] = $out;
            return $p;
        }, 'checkin');

        // Командный счёт недели: сколько дней сделал каждый, какая у него
        // личная норма и был ли возврат после перерыва внутри недели.
        $kernel->events->on('squad.member_weeks', static function (array $p) use ($kernel): array {
            $from = (string) ($p['week_start'] ?? '');
            $to   = (string) ($p['week_end'] ?? '');
            $ids  = array_values(array_filter(array_map('intval', (array) ($p['user_ids'] ?? []))));
            if ($from === '' || $to === '' || $ids === []) {
                return $p;
            }

            /** @var Week $week */
            $week  = $kernel->container->get(Week::class);
            $stats = [];
            foreach ($ids as $id) {
                $done = (int) $kernel->db()->value(
                    "SELECT COUNT(*) FROM checkin_days WHERE user_id = ? AND date BETWEEN ? AND ? AND done IN ('yes', 'partial')",
                    [$id, $from, $to],
                    0
                );
                $returned = $kernel->db()->value(
                    "SELECT 1 FROM checkin_returns
                     WHERE user_id = ? AND date(gap_from, '+' || gap_days || ' days') BETWEEN ? AND ?",
                    [$id, $from, $to]
                ) !== null;

                $stats[$id] = [
                    'done_days' => $done,
                    'norm_days' => $week->norm($id, $from)['days'],
                    'returned'  => $returned,
                ];
            }
            $p['stats'] = $stats;
            return $p;
        }, 'checkin');
    }
}
