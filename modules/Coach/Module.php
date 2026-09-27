<?php
declare(strict_types=1);

namespace Modules\Coach;

use App\BaseModule;
use App\Container;
use App\Contracts;
use App\Kernel;
use Modules\Coach\Domain\CoachService;
use Modules\Coach\Domain\Context;
use Modules\Coach\Domain\LlmClient;
use Modules\Coach\Domain\OpenAiClient;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(LlmClient::class, static fn() => new OpenAiClient($kernel), 'coach');
        $container->singleton(Context::class, static fn() => new Context($kernel), 'coach');
        $container->singleton(CoachService::class, static fn(Container $c) => new CoachService($kernel, $c->get(Context::class)), 'coach');
        $container->singleton(Contracts\Coach::class, static fn(Container $c) => $c->get(CoachService::class), 'coach');
    }

    public function boot(Kernel $kernel): void
    {
        // Тренер ничего не слушает: его вызывают через контракт (чек-ин)
        // и через свои маршруты (недельный обзор). Данные он спрашивает
        // событием coach.context — отвечают их владельцы.


        // Тренер в панели метрик: открывают ли обзор и полезен ли он (§ 13).
        $kernel->events->on('analytics.collect', static function (array $p) use ($kernel): array {
            $since = gmdate('c', time() - 14 * 86400);
            $rated = $kernel->db()->first("SELECT COUNT(*) AS n, COALESCE(SUM(useful), 0) AS yes FROM coach_msgs WHERE kind = 'week' AND useful IS NOT NULL AND created_at >= ?", [$since]);
            $n = (int) ($rated['n'] ?? 0);
            $p['metrics'][] = ['group' => 'coach', 'key' => 'review_useful', 'value' => $n > 0 ? 100.0 * (int) $rated['yes'] / $n : null, 'target' => 60, 'n' => $n];
            $active = (int) $kernel->db()->value("SELECT COUNT(DISTINCT user_id) FROM coach_msgs WHERE kind = 'checkin' AND created_at >= ?", [gmdate('c', time() - 7 * 86400)], 0);
            $opened = (int) $kernel->db()->value("SELECT COUNT(DISTINCT user_id) FROM coach_msgs WHERE kind = 'week' AND created_at >= ?", [gmdate('c', time() - 7 * 86400)], 0);
            $p['metrics'][] = ['group' => 'coach', 'key' => 'review_opened', 'value' => $active > 0 ? 100.0 * min($opened, $active) / $active : null, 'target' => 65, 'n' => $active];
            return $p;
        }, 'coach');

        // Права на данные (Р-19): выгрузка и удаление — только своих таблиц.
        \App\UserData::register($kernel, 'coach', 'coach_', [
            'coach_consent' => [],
            'coach_msgs'    => [],
        ]);
    }
}
