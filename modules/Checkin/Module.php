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
    }
}
