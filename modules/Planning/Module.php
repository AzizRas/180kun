<?php
declare(strict_types=1);

namespace Modules\Planning;

use App\BaseModule;
use App\Container;
use App\Contracts;
use App\Kernel;
use Modules\Planning\Domain\PlanService;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(PlanService::class, static fn() => new PlanService($kernel), 'planning');
        $container->singleton(
            Contracts\Planner::class,
            static fn(Container $c) => $c->get(PlanService::class),
            'planning'
        );
    }

    public function boot(Kernel $kernel): void
    {
        // План строится сам, как только онбординг завершён.
        // Модуль Onboarding при этом ничего не знает про планирование:
        // выключите Planning — онбординг продолжит работать, просто
        // плана не будет.
        $kernel->events->on('onboarding.completed', static function (array $p) use ($kernel): array {
            $userId = (int) ($p['user_id'] ?? 0);
            if ($userId === 0) {
                return $p;
            }

            /** @var PlanService $planner */
            $planner = $kernel->container->get(PlanService::class);
            $plan    = $planner->build($userId, (array) ($p['profile'] ?? []), (array) ($p['baseline'] ?? []));

            $p['plan_built'] = $plan !== null;
            return $p;
        }, 'planning');
    }
}
