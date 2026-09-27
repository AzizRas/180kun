<?php
declare(strict_types=1);

namespace Modules\Admin;

use App\BaseModule;
use App\Container;
use App\Kernel;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
    }

    public function boot(Kernel $kernel): void
    {
        // Любой модуль сообщает о решении модератора событием admin.action;
        // журнал ведём здесь. Модуль выключен — решения работают, журнала нет.
        $kernel->events->on('admin.action', static function (array $p) use ($kernel): array {
            $kernel->db()->insert('admin_audit', [
                'actor_id'   => isset($p['actor_id']) ? (int) $p['actor_id'] : null,
                'module'     => (string) ($p['module'] ?? 'app'),
                'action'     => (string) ($p['action'] ?? ''),
                'target_id'  => isset($p['target_id']) ? (int) $p['target_id'] : null,
                'meta'       => !empty($p['meta']) ? json_encode($p['meta'], JSON_UNESCAPED_UNICODE) : null,
                'created_at' => gmdate('c'),
            ]);
            return $p;
        }, 'admin');

        // Удаление аккаунта: в журнале остаётся действие, но не человек.
        \App\UserData::register($kernel, 'admin', 'admin_', [
            'admin_audit' => ['export' => false, 'erase' => 'UPDATE admin_audit SET target_id = NULL WHERE target_id = ?'],
        ]);
    }
}
