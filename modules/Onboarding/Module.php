<?php
declare(strict_types=1);

namespace Modules\Onboarding;

use App\BaseModule;
use App\Container;
use App\Kernel;
use Modules\Onboarding\Domain\Profiles;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(Profiles::class, static fn() => new Profiles($kernel), 'onboarding');
    }

    public function boot(Kernel $kernel): void
    {
        // Планировщику нужно знать, есть ли ограничения по нагрузке.
        // Он не лезет в таблицу скрининга — спрашивает событием,
        // а мы отвечаем, потому что этой таблицей владеем мы.
        $kernel->events->on('planning.load_limits', static function (array $p) use ($kernel): array {
            $row = $kernel->db()->first(
                'SELECT needs_doctor FROM onboarding_screening WHERE user_id = ?',
                [(int) ($p['user_id'] ?? 0)]
            );
            if ($row !== null && (int) $row['needs_doctor'] === 1) {
                $p['limited'] = true;
            }
            return $p;
        }, 'onboarding');
    }
}
