<?php
declare(strict_types=1);

namespace Modules\Feed;

use App\BaseModule;
use App\Container;
use App\Kernel;
use Modules\Feed\Domain\Feed;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(Feed::class, static fn() => new Feed($kernel), 'feed');
    }

    public function boot(Kernel $kernel): void
    {
        $feed = static fn(): Feed => $kernel->container->get(Feed::class);

        // Сквады (§ 13): отвечает ли команда на фото друг друга; жалобы — в ноль.
        $kernel->events->on('analytics.collect', static function (array $p) use ($feed): array {
            $s = $feed()->supportedShare((string) ($p['today'] ?? gmdate('Y-m-d')));
            $p['metrics'][] = ['group' => 'squads', 'key' => 'feed_supported', 'value' => $s['value'], 'target' => 80, 'n' => $s['n']];
            $p['metrics'][] = ['group' => 'safety', 'key' => 'feed_reports_open', 'value' => (float) $feed()->openReports(), 'target' => 0, 'unit' => '', 'better' => 'lower', 'n' => 1];
            return $p;
        }, 'feed');

        // Права на данные (Р-19). Фото стирает хранилище, строки — мы.
        // Поддержки и жалобы человека уходят вместе с ним; его посты — тоже.
        \App\UserData::register($kernel, 'feed', 'feed_', [
            'feed_support' => ['where' => 'user_id = ? OR post_id IN (SELECT id FROM feed_posts WHERE user_id = ?)'],
            'feed_reports' => ['where' => 'user_id = ? OR post_id IN (SELECT id FROM feed_posts WHERE user_id = ?)'],
            'feed_posts'   => ['before' => static fn(int $userId) => $feed()->eraseMedia($userId)],
        ]);
    }
}
