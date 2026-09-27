<?php
declare(strict_types=1);

namespace Modules\Calls;

use App\BaseModule;
use App\Container;
use App\Contracts;
use App\Kernel;
use Modules\Calls\Domain\Calls;
use Modules\Calls\Domain\JitsiRoom;
use Modules\Calls\Domain\TelegramRoom;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(Calls::class, static fn() => new Calls($kernel), 'calls');

        // Где проходит звонок. Модуль, объявленный позже и требующий calls
        // (например, своя видеокомната), может перекрыть эту реализацию.
        $container->singleton(Contracts\CallProvider::class, static function () use ($kernel): Contracts\CallProvider {
            $url = trim((string) $kernel->config->get('calls.jitsi_url', ''));
            if ($kernel->config->get('calls.provider', 'telegram') === 'jitsi' && str_starts_with($url, 'https://')) {
                return new JitsiRoom($url, $kernel->secret());
            }
            return new TelegramRoom();
        }, 'calls');
    }

    public function boot(Kernel $kernel): void
    {
        $calls = static fn(): Calls => $kernel->container->get(Calls::class);

        $kernel->events->on('system.tick', static function (array $p) use ($calls): array {
            $n = $calls()->remindDue();
            if ($n > 0) {
                $p['done'][] = 'calls: напоминаний ' . $n;
            }
            return $p;
        }, 'calls');

        $kernel->events->on('analytics.collect', static function (array $p) use ($calls): array {
            $a = $calls()->attendance((string) ($p['today'] ?? gmdate('Y-m-d')));
            $p['metrics'][] = ['group' => 'squads', 'key' => 'calls_attendance', 'value' => $a['value'], 'target' => 3, 'unit' => '', 'n' => $a['n']];
            return $p;
        }, 'calls');

        // Права на данные (Р-19). Созвон принадлежит скваду: удалённый
        // лидер превращается в «0», расписание у остальных остаётся.
        \App\UserData::register($kernel, 'calls', 'calls_', [
            'calls_rsvp'     => [],
            'calls_sessions' => ['column' => 'created_by', 'erase' => 'UPDATE calls_sessions SET created_by = 0 WHERE created_by = ?'],
        ]);
    }
}
