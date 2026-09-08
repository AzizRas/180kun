<?php
declare(strict_types=1);

namespace Modules\Telegram;

use App\BaseModule;
use App\Container;
use App\Kernel;
use Modules\Telegram\Domain\BotApi;
use Modules\Telegram\Domain\InitData;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(
            BotApi::class,
            static fn() => new BotApi($kernel, (string) $kernel->config->get('telegram.bot_token', '')),
            'telegram'
        );
        $container->singleton(
            InitData::class,
            static fn() => new InitData((string) $kernel->config->get('telegram.bot_token', '')),
            'telegram'
        );
    }

    public function boot(Kernel $kernel): void
    {
        // Модуль не реализует контракт Notifier целиком, а подключается
        // как один из каналов доставки. Так SMS и Telegram сосуществуют.

        $kernel->events->on('notify.channels', static function (array $p) use ($kernel): array {
            if ($kernel->container->get(BotApi::class)->isConfigured()) {
                $p['channels'][] = 'telegram';
            }
            return $p;
        }, 'telegram');

        $kernel->events->on('notify.send', static function (array $p) use ($kernel): array {
            if (!empty($p['delivered'])) {
                return $p;   // уже доставлено другим каналом
            }
            if (!in_array($p['channel'] ?? 'auto', ['auto', 'telegram'], true)) {
                return $p;
            }

            $user = $kernel->db()->first(
                'SELECT tg_id FROM identity_users WHERE id = ?',
                [$p['user_id'] ?? 0]
            );
            if ($user === null || empty($user['tg_id'])) {
                return $p;
            }

            if ($kernel->container->get(BotApi::class)->sendMessage((string) $user['tg_id'], (string) $p['text'])) {
                $p['delivered'] = true;
                $p['via']       = 'telegram';
            }
            return $p;
        }, 'telegram');

        $kernel->events->on('notify.code', static function (array $p) use ($kernel): array {
            if (!empty($p['delivered'])) {
                return $p;
            }
            if (($p['channel'] ?? 'auto') !== 'telegram') {
                return $p;   // код в Telegram шлём только по явному запросу
            }

            $user = $kernel->db()->first(
                'SELECT tg_id FROM identity_users WHERE phone = ?',
                [$p['address'] ?? '']
            );
            if ($user === null || empty($user['tg_id'])) {
                return $p;
            }

            $text = 'Код подтверждения LEVEL 180: <b>' . (string) $p['code'] . '</b>';
            if ($kernel->container->get(BotApi::class)->sendMessage((string) $user['tg_id'], $text)) {
                $p['delivered'] = true;
                $p['via']       = 'telegram';
            }
            return $p;
        }, 'telegram');
    }
}
