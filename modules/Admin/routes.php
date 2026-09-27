<?php
declare(strict_types=1);

use App\Kernel;
use App\Migrator;
use App\Request;
use App\Response;
use App\Router;

return static function (Router $router, Kernel $kernel): void {

    $router->get('/api/admin/audit', static function (Request $r, Kernel $k): Response {
        if (!in_array((string) ($r->user()['role'] ?? ''), ['admin', 'moderator'], true)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $rows = $k->db()->all('SELECT * FROM admin_audit ORDER BY id DESC LIMIT 100');
        $auth = $k->container->get(App\Contracts\Auth::class);
        foreach ($rows as &$row) {
            $row['actor'] = $row['actor_id'] ? (trim((string) ($auth->userById((int) $row['actor_id'])['name'] ?? '')) ?: '#' . $row['actor_id']) : '—';
            $row['meta']  = json_decode((string) $row['meta'], true);
        }
        unset($row);
        return Response::json(['ok' => true, 'audit' => $rows]);
    }, ['auth' => true]);

    /**
     * Миграции кнопкой — для хостинга без консоли. Они и так применяются
     * сами при первом запросе после деплоя; кнопка — чтобы увидеть
     * результат и повторить после ошибки. Только администратор.
     */
    $router->post('/api/admin/migrate', static function (Request $r, Kernel $k): Response {
        if ((string) ($r->user()['role'] ?? '') !== 'admin') {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $result = (new Migrator($k))->migrate($r->bool('dry'));
        $k->events->emit('admin.action', ['actor_id' => (int) $r->userId(), 'module' => 'admin', 'action' => 'migrate', 'meta' => ['applied' => $result['applied']]]);
        return Response::json(['ok' => $result['errors'] === []] + $result);
    }, ['auth' => true]);
};
