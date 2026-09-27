<?php
declare(strict_types=1);

use App\Kernel;
use App\Request;
use App\Response;
use App\Result;
use App\Router;
use Modules\Calls\Domain\Calls;

return static function (Router $router, Kernel $kernel): void {

    $calls = static fn(Kernel $k): Calls => $k->container->get(Calls::class);

    $reply = static function (Result $r, Kernel $k): Response {
        if ($r->ok) {
            return Response::json(['ok' => true] + (is_array($r->data) ? $r->data : []));
        }
        return Response::json([
            'error'   => $r->error,
            'message' => $k->i18n->t('calls.error.' . $r->error, array_filter($r->meta, 'is_scalar')),
        ] + $r->meta, $r->error === 'not_found' ? 404 : ($r->error === 'not_leader' ? 403 : 422));
    };

    $router->get('/api/calls', static function (Request $r, Kernel $k) use ($calls): Response {
        return Response::json(['ok' => true] + $calls($k)->overview((int) $r->userId()));
    }, ['auth' => true]);

    /** Лидер назначает: дата и время по Ташкенту. */
    $router->post('/api/calls', static function (Request $r, Kernel $k) use ($calls, $reply): Response {
        return $reply($calls($k)->schedule(
            (int) $r->userId(), $r->str('date'), $r->str('time'), $r->int('duration', 30), $r->str('topic'), $r->str('note')
        ), $k);
    }, ['auth' => true]);

    $router->post('/api/calls/{id}/rsvp', static function (Request $r, Kernel $k) use ($calls, $reply): Response {
        return $reply($calls($k)->rsvp((int) $r->userId(), (int) $r->param('id'), $r->str('answer')), $k);
    }, ['auth' => true]);

    $router->post('/api/calls/{id}/join', static function (Request $r, Kernel $k) use ($calls, $reply): Response {
        return $reply($calls($k)->join((int) $r->userId(), (int) $r->param('id')), $k);
    }, ['auth' => true]);

    $router->post('/api/calls/{id}/cancel', static function (Request $r, Kernel $k) use ($calls, $reply): Response {
        return $reply($calls($k)->cancel((int) $r->userId(), (int) $r->param('id')), $k);
    }, ['auth' => true]);
};
