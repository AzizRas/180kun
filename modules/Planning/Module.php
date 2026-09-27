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

        // Облегчение по флагам нагрузки (Р-14). Кто решил облегчить — нам
        // не важно (сейчас это тренер); важно, что сделать тяжелее так нельзя.
        $kernel->events->on('plan.load_adjust', static function (array $p) use ($kernel): array {
            $p['applied'] = $kernel->container->get(PlanService::class)->adjust(
                (int) ($p['user_id'] ?? 0),
                (float) ($p['factor'] ?? 1),
                (string) ($p['from'] ?? gmdate('Y-m-d')),
                (int) ($p['days'] ?? 3),
                (string) ($p['level'] ?? 'yellow'),
                (array) ($p['reasons'] ?? [])
            );
            return $p;
        }, 'planning');

        // Вернулся после паузы больше недели — не с того места, где бросил,
        // а с 60% и плавным догоном.
        $kernel->events->on('user.returned', static function (array $p) use ($kernel): array {
            if ((int) ($p['gap_days'] ?? 0) > 7) {
                $kernel->container->get(PlanService::class)->rampAfterPause((int) $p['user_id'], (string) ($p['date'] ?? gmdate('Y-m-d')));
            }
            return $p;
        }, 'planning');

        // Синхронный старт (Р-07): кто-то (сейчас — модуль сквадов) назначил
        // людям общий день 1. План сдвигается целиком, содержимое не меняется.
        $kernel->events->on('season.scheduled', static function (array $p) use ($kernel): array {
            $date = (string) ($p['start_date'] ?? '');
            if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) {
                return $p;
            }
            foreach ((array) ($p['user_ids'] ?? []) as $userId) {
                $kernel->db()->run(
                    'UPDATE planning_plans SET start_date = ? WHERE user_id = ? AND active = 1',
                    [$date, (int) $userId]
                );
            }
            return $p;
        }, 'planning');

        // Права на данные (Р-19): выгрузка и удаление — только своих таблиц.
        \App\UserData::register($kernel, 'planning', 'planning_', [
            'planning_chapters'    => ['where' => 'plan_id IN (SELECT id FROM planning_plans WHERE user_id = ?)'],
            'planning_weeks'       => ['where' => 'plan_id IN (SELECT id FROM planning_plans WHERE user_id = ?)'],
            'planning_adjustments' => [],
            'planning_plans'       => [],
        ]);
    }
}
