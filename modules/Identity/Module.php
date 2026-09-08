<?php
declare(strict_types=1);

namespace Modules\Identity;

use App\BaseModule;
use App\Container;
use App\Contracts;
use App\Kernel;
use Modules\Identity\Domain\AuthService;
use Modules\Identity\Domain\Codes;
use Modules\Identity\Domain\Users;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(Users::class, static fn() => new Users($kernel), 'identity');
        $container->singleton(Codes::class, static fn() => new Codes($kernel), 'identity');

        // Перекрываем заглушку NullAuth настоящей реализацией.
        $container->singleton(
            Contracts\Auth::class,
            static fn(Container $c) => new AuthService($kernel, $c->get(Users::class)),
            'identity'
        );
    }

    public function boot(Kernel $kernel): void
    {
        // Раз в сутки чистим протухшие сессии и старые записи о попытках входа.
        if (random_int(1, 50) === 1) {
            try {
                $db = $kernel->db();
                $db->run('DELETE FROM identity_sessions WHERE expires_at < ?', [gmdate('c')]);
                $db->run('DELETE FROM identity_attempts WHERE created_at < ?', [gmdate('c', time() - 86400)]);
                $db->run('DELETE FROM identity_codes    WHERE expires_at < ?', [gmdate('c', time() - 3600)]);
            } catch (\Throwable) {
                // Уборка не критична — молча пропускаем.
            }
        }
    }
}
