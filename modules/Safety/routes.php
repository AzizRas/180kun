<?php
declare(strict_types=1);

use App\Kernel;
use App\Request;
use App\Response;
use App\Router;
use Modules\Safety\Domain\Protocol;

return static function (Router $router, Kernel $kernel): void {

    $isModerator = static fn(Request $r): bool => in_array((string) ($r->user()['role'] ?? ''), ['admin', 'moderator'], true);

    /** Режим тишины и контакты помощи — экран может показать их в любой момент. */
    $router->get('/api/safety/state', static function (Request $r, Kernel $k): Response {
        $p = $k->container->get(Protocol::class);
        return Response::json(['ok' => true, 'quiet' => $p->isQuiet((int) $r->userId())] + $p->help((int) $r->userId()));
    }, ['auth' => true]);

    $router->get('/api/admin/safety/alerts', static function (Request $r, Kernel $k) use ($isModerator): Response {
        if (!$isModerator($r)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        return Response::json(['ok' => true, 'alerts' => $k->container->get(Protocol::class)->openAlerts()]);
    }, ['auth' => true]);

    $router->post('/api/admin/safety/alerts/{id}/resolve', static function (Request $r, Kernel $k) use ($isModerator): Response {
        if (!$isModerator($r)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $ok = $k->container->get(Protocol::class)->resolve((int) $r->param('id'), (int) $r->userId());
        return $ok ? Response::json(['ok' => true]) : Response::json(['error' => 'not_found'], 404);
    }, ['auth' => true]);
};
