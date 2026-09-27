<?php
declare(strict_types=1);

namespace Modules\Wearable;

use App\BaseModule;
use App\Container;
use App\Contracts;
use App\Kernel;
use Modules\Wearable\Domain\Metrics;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(Metrics::class, static fn() => new Metrics($kernel), 'wearable');
        $container->singleton(Contracts\Wearable::class, static fn(Container $c) => $c->get(Metrics::class), 'wearable');
    }

    public function boot(Kernel $kernel): void
    {
        // Дни браслета тренер берёт через контракт Wearable; недельного
        // тренда веса в контракте нет — отдаём его по запросу контекста.
        $kernel->events->on('coach.context', static function (array $p) use ($kernel): array {
            if (!empty($p['user_id'])) {
                $p['weights'] = $kernel->container->get(Metrics::class)->weightTrend((int) $p['user_id']);
            }
            return $p;
        }, 'wearable');

        // Права на данные (Р-19): выгрузка и удаление — только своих таблиц.
        \App\UserData::register($kernel, 'wearable', 'wear_', [
            'wear_days'    => [],
            'wear_weights' => [],
        ]);
    }
}
