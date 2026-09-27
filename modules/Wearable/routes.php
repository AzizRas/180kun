<?php
declare(strict_types=1);

use App\Kernel;
use App\Request;
use App\Response;
use App\Result;
use App\Router;
use Modules\Wearable\Domain\Metrics;

return static function (Router $router, Kernel $kernel): void {

    $metrics = static fn(Kernel $k): Metrics => $k->container->get(Metrics::class);
    $reply   = static fn(Result $r, Kernel $k): Response => $r->ok
        ? Response::json(['ok' => true, 'data' => $r->data])
        : Response::json([
            'error'   => $r->error,
            'message' => $k->i18n->t('wear.error.' . $r->error, ['field' => $k->i18n->t('wear.field.' . ($r->meta['field'] ?? ''))]),
        ] + $r->meta, 422);

    /** Последние две недели и медианы — для экрана и для тренера. */
    $router->get('/api/wearable/days', static function (Request $r, Kernel $k) use ($metrics): Response {
        $days = max(1, min(60, $r->int('days', 14)));
        $m    = $metrics($k);
        return Response::json([
            'ok'        => true,
            'days'      => $m->recent((int) $r->userId(), $days),
            'baseline'  => $m->baseline((int) $r->userId(), 7),
            'connected' => $m->isConnected((int) $r->userId()),
            'weights'   => $m->weightTrend((int) $r->userId()),
        ]);
    }, ['auth' => true]);

    /** Ручной ввод: шаги, сон, пульс покоя, активные минуты — что есть. */
    $router->post('/api/wearable/day', static function (Request $r, Kernel $k) use ($metrics, $reply): Response {
        return $reply($metrics($k)->record((int) $r->userId(), $r->body()), $k);
    }, ['auth' => true]);

    /** Импорт выгрузки. Клиент читает файл сам и присылает текст. */
    $router->post('/api/wearable/import', static function (Request $r, Kernel $k) use ($metrics, $reply): Response {
        return $reply($metrics($k)->import((int) $r->userId(), (string) $r->input('csv', '')), $k);
    }, ['auth' => true]);

    $router->post('/api/wearable/weight', static function (Request $r, Kernel $k) use ($metrics, $reply): Response {
        return $reply($metrics($k)->recordWeight((int) $r->userId(), $r->input('weight_kg'), $r->input('waist_cm')), $k);
    }, ['auth' => true]);
};
