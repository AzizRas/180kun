<?php
declare(strict_types=1);

namespace Modules\Gamification;

use App\BaseModule;
use App\Container;
use App\Contracts;
use App\Kernel;
use Modules\Gamification\Domain\Ledger;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(Ledger::class, static fn() => new Ledger($kernel), 'gamification');
        $container->singleton(
            Contracts\Gamification::class,
            static fn(Container $c) => $c->get(Ledger::class),
            'gamification'
        );
    }

    public function boot(Kernel $kernel): void
    {
        $ledger = static fn(): Ledger => $kernel->container->get(Ledger::class);

        // Чек-ин: очки за сам факт отметки и отдельно за выполненное действие.
        // Честный «не сделал» тоже оплачивается — это принципиально:
        // молчание хуже неудачи, потому что без данных не работает всё остальное.
        $kernel->events->on('checkin.recorded', static function (array $p) use ($ledger): array {
            $userId = (int) ($p['user_id'] ?? 0);
            $date   = (string) ($p['date'] ?? '');
            if ($userId === 0 || $date === '') {
                return $p;
            }

            $l = $ledger();
            $l->give($userId, 'checkin', $date);

            if (($p['done'] ?? '') === 'yes') {
                $l->give($userId, 'action', $date);
            } elseif (($p['done'] ?? '') === 'partial') {
                $l->give($userId, 'action', $date, Ledger::RATES['action_half']);
            }

            return $p;
        }, 'gamification');

        // Возврат после перерыва — самое дорогое действие в системе.
        $kernel->events->on('user.returned', static function (array $p) use ($ledger): array {
            $userId = (int) ($p['user_id'] ?? 0);
            $date   = (string) ($p['date'] ?? gmdate('Y-m-d'));
            if ($userId > 0) {
                $ledger()->give($userId, 'comeback', $date);
            }
            return $p;
        }, 'gamification');

        // Закрытая недельная норма весит больше суммы дней:
        // так награждается стабильность, а не рывок.
        $kernel->events->on('week.closed', static function (array $p) use ($ledger): array {
            $userId = (int) ($p['user_id'] ?? 0);
            if ($userId > 0 && !empty($p['kept'])) {
                $ledger()->give($userId, 'week_kept', (string) ($p['week_start'] ?? ''));
            }
            return $p;
        }, 'gamification');

        $kernel->events->on('onboarding.completed', static function (array $p) use ($ledger): array {
            $userId = (int) ($p['user_id'] ?? 0);
            if ($userId > 0) {
                $ledger()->give($userId, 'onboarding', 'once');
            }
            return $p;
        }, 'gamification');
    }
}
