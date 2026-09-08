<?php
declare(strict_types=1);

namespace Modules\Sms;

use App\BaseModule;
use App\Container;
use App\Kernel;
use Modules\Sms\Domain\Eskiz;
use Modules\Sms\Domain\Gateway;
use Modules\Sms\Domain\LogGateway;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        // Выбор шлюза: настоящий провайдер, иначе лог при отладке.
        $container->singleton(Gateway::class, static function () use ($kernel): Gateway {
            $eskiz = new Eskiz($kernel);
            return $eskiz->isConfigured() ? $eskiz : new LogGateway($kernel);
        }, 'sms');
    }

    public function boot(Kernel $kernel): void
    {
        $kernel->events->on('notify.channels', static function (array $p) use ($kernel): array {
            if ($kernel->container->get(Gateway::class)->isConfigured()) {
                $p['channels'][] = 'sms';
            }
            return $p;
        }, 'sms');

        $kernel->events->on('notify.code', static function (array $p) use ($kernel): array {
            if (!empty($p['delivered'])) {
                return $p;
            }
            if (!in_array($p['channel'] ?? 'auto', ['auto', 'sms'], true)) {
                return $p;
            }

            $address = (string) ($p['address'] ?? '');
            if (!str_starts_with($address, '+998')) {
                return $p;   // это не номер телефона
            }

            /** @var Gateway $gateway */
            $gateway = $kernel->container->get(Gateway::class);
            $text    = $kernel->i18n->t('sms.code_text', ['code' => (string) $p['code']]);

            if ($gateway->send($address, $text)) {
                $p['delivered'] = true;
                $p['via']       = $gateway->name();
            }
            return $p;
        }, 'sms', 200);   // приоритет ниже Telegram: сначала пробуем бота
    }
}
