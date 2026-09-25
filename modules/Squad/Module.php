<?php
declare(strict_types=1);

namespace Modules\Squad;

use App\BaseModule;
use App\Container;
use App\Kernel;
use Modules\Squad\Domain\Chat;
use Modules\Squad\Domain\Leadership;
use Modules\Squad\Domain\Pool;
use Modules\Squad\Domain\Scoring;
use Modules\Squad\Domain\Squads;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(Pool::class, static fn() => new Pool($kernel), 'squad');
        $container->singleton(Leadership::class, static fn() => new Leadership($kernel), 'squad');
        $container->singleton(Scoring::class, static fn() => new Scoring($kernel), 'squad');
        $container->singleton(Chat::class, static fn() => new Chat($kernel), 'squad');
        $container->singleton(Squads::class, static fn(Container $c) => new Squads(
            $kernel,
            $c->get(Pool::class),
            $c->get(Leadership::class),
            $c->get(Scoring::class),
            $c->get(Chat::class),
        ), 'squad');
    }

    public function boot(Kernel $kernel): void
    {
        // Онбординг завершён — человек сам попадает в пул ближайшей волны.
        // Онбординг про сквады ничего не знает: он просто сообщил, что готово.
        $kernel->events->on('onboarding.completed', static function (array $p) use ($kernel): array {
            $userId = (int) ($p['user_id'] ?? 0);
            if ($userId > 0) {
                $kernel->container->get(Pool::class)->join(
                    $userId,
                    (array) ($p['profile'] ?? []),
                    (array) ($p['baseline'] ?? [])
                );
            }
            return $p;
        }, 'squad');

        // Вернулся после срыва — место в скваде ещё за ним, снимаем паузу сразу.
        $kernel->events->on('user.returned', static function (array $p) use ($kernel): array {
            $userId = (int) ($p['user_id'] ?? 0);
            if ($userId > 0) {
                $kernel->container->get(Squads::class)->onUserReturned($userId, (string) ($p['date'] ?? gmdate('Y-m-d')));
            }
            return $p;
        }, 'squad');

        // Сообщение в группе сквада. Модуль Telegram разобрал апдейт и узнал
        // человека; мы считаем активность и отвечаем на /bind.
        $kernel->events->on('telegram.group_message', static function (array $p) use ($kernel): array {
            $reply = $kernel->container->get(Chat::class)->onMessage(
                (string) ($p['chat_id'] ?? ''),
                isset($p['user_id']) ? (int) $p['user_id'] : null,
                (string) ($p['text'] ?? ''),
                isset($p['at']) ? (string) $p['at'] : null
            );
            if ($reply !== null) {
                $p['reply'] = $reply;
            }
            return $p;
        }, 'squad');

        // Коллеги и родня (например, «сезон вдвоём» из модуля оплаты) —
        // в разные сквады.
        $kernel->events->on('people.related', static function (array $p) use ($kernel): array {
            $kernel->container->get(Pool::class)->relate((int) ($p['a'] ?? 0), (int) ($p['b'] ?? 0), (string) ($p['kind'] ?? 'known'));
            return $p;
        }, 'squad');
    }
}
