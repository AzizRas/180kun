<?php
declare(strict_types=1);

namespace Modules\Safety;

use App\BaseModule;
use App\Container;
use App\Kernel;
use Modules\Safety\Domain\Protocol;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(Protocol::class, static fn() => new Protocol($kernel), 'safety');
    }

    public function boot(Kernel $kernel): void
    {
        $protocol = static fn(): Protocol => $kernel->container->get(Protocol::class);

        // Заметка к чек-ину — единственное место, где человек пишет в
        // приложении свободным текстом. Помощь возвращаем прямо в ответ.
        $kernel->events->on('checkin.recorded', static function (array $p) use ($protocol): array {
            $help = $protocol()->check((int) ($p['user_id'] ?? 0), (string) ($p['note'] ?? ''), 'checkin_note');
            if ($help !== null) {
                $p['safety'] = $help;
            }
            return $p;
        }, 'safety', 10);

        // Чат сквада. Отвечаем лично, не в группу.
        $kernel->events->on('telegram.group_message', static function (array $p) use ($protocol): array {
            if (!empty($p['user_id'])) {
                $protocol()->check((int) $p['user_id'], (string) ($p['text'] ?? ''), 'group');
            }
            return $p;
        }, 'safety', 10);

        // Любой другой модуль, принявший свободный текст, может спросить.
        $kernel->events->on('safety.check_text', static function (array $p) use ($protocol): array {
            $help = $protocol()->check((int) ($p['user_id'] ?? 0), (string) ($p['text'] ?? ''), (string) ($p['source'] ?? 'other'), (bool) ($p['on_screen'] ?? false));
            if ($help !== null) {
                $p['safety'] = $help;
            }
            return $p;
        }, 'safety', 10);

        // Режим тишины скрывает очки: модуль очков спрашивает «добавки»
        // к профилю, и мы отвечаем флагом.
        $kernel->events->on('gamification.extras', static function (array $p) use ($protocol): array {
            if (!empty($p['user_id']) && $protocol()->isQuiet((int) $p['user_id'])) {
                $p['quiet'] = true;
            }
            return $p;
        }, 'safety');


        // Открытые сигналы — должно быть ноль: на каждый реакция за 2 часа.
        $kernel->events->on('analytics.collect', static function (array $p) use ($kernel): array {
            $open = (int) $kernel->db()->value('SELECT COUNT(*) FROM safety_alerts WHERE resolved_at IS NULL', [], 0);
            $p['metrics'][] = ['group' => 'safety', 'key' => 'open_alerts', 'value' => (float) $open, 'target' => 0, 'unit' => '', 'better' => 'lower', 'n' => 1];
            return $p;
        }, 'safety');

        // Права на данные (Р-19): выгрузка и удаление — только своих таблиц.
        \App\UserData::register($kernel, 'safety', 'safety_', [
            'safety_alerts' => [],
            'safety_state'  => [],
        ]);
    }
}
