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

        // Тренеру — отметки за окно, прогресс недели, серия и помехи.
        $kernel->events->on('coach.context', static function (array $p) use ($kernel): array {
            $userId = (int) ($p['user_id'] ?? 0);
            $from   = (string) ($p['from'] ?? '');
            $to     = (string) ($p['to'] ?? gmdate('Y-m-d'));
            if ($userId <= 0 || $from === '') {
                return $p;
            }
            $p['checkins'] = $kernel->db()->all(
                'SELECT date, done, energy, mood, skip_reason FROM checkin_days WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date',
                [$userId, $from, $to]
            );

            /** @var Week $week */
            $week     = $kernel->container->get(Week::class);
            $streak   = $kernel->container->get(Streak::class);
            $progress = $streak->recompute($userId, $week->startFor($userId, $to));
            $p['week']   = ['done_days' => $progress['done_days'], 'norm_days' => $progress['norm']['days'], 'checkins' => $progress['checkins']];
            $p['streak'] = $streak->current($userId);

            $weekAgo = gmdate('Y-m-d', strtotime($to . ' 00:00:00 UTC') - 6 * 86400);
            $events  = $kernel->db()->all(
                'SELECT type, date_from, date_to FROM checkin_events WHERE user_id = ? AND date_to >= ? AND date_from <= ?',
                [$userId, $weekAgo, $to]
            );
            $p['event_active'] = $events !== [];
            foreach ($events as $e) {
                if ($e['date_from'] <= $to && $e['date_to'] >= $to) {
                    $p['event_type'] = $e['type'];
                }
            }
            return $p;
        }, 'checkin');


        // Главная метрика продукта — Return Rate (Р-21). Считаем мы: это наши данные.
        $kernel->events->on('analytics.collect', static function (array $p) use ($kernel): array {
            $rr = $kernel->container->get(Recovery::class)->returnRate();
            $p['metrics'][] = ['group' => 'core', 'key' => 'return_rate', 'value' => $rr['gaps'] > 0 ? (float) $rr['rate'] : null, 'target' => 55, 'n' => (int) $rr['gaps']];
            return $p;
        }, 'checkin');

        // Права на данные (Р-19): выгрузка и удаление — только своих таблиц.
        \App\UserData::register($kernel, 'checkin', 'checkin_', [
            'checkin_days'    => [],
            'checkin_events'  => [],
            'checkin_weeks'   => [],
            'checkin_shields' => [],
            'checkin_returns' => [],
            'checkin_state'   => [],
        ]);
    }
}
