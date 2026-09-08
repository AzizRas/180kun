<?php
declare(strict_types=1);

use App\Kernel;
use App\Request;
use App\Response;
use App\Router;
use Modules\Checkin\Domain\Checkins;
use Modules\Checkin\Domain\Recovery;

return static function (Router $router, Kernel $kernel): void {

    $fail = static fn(Kernel $k, App\Result $r, int $status = 422): Response => Response::json([
        'error'   => $r->error,
        'message' => $k->i18n->t('checkin.' . $r->error, $r->meta),
    ] + $r->meta, $status);

    /** Экран чек-ина: действие, состояние, прогресс недели, серия. */
    $router->get('/api/checkin/today', static function (Request $r, Kernel $k): Response {
        /** @var Checkins $checkins */
        $checkins = $k->container->get(Checkins::class);
        $today    = $checkins->today((int) $r->userId(), $r->str('date') ?: null);

        // Текст действия и подсказки берём из переводов уже здесь,
        // чтобы фронтенд не собирал ключи руками.
        if (!empty($today['plan']['action'])) {
            $key = $today['plan']['action']['key'];
            $today['plan']['action']['title'] = $k->i18n->t('action.' . $key);
            $today['plan']['action']['hint']  = $k->i18n->t('action.' . $key . '.hint');
        }
        if (!empty($today['plan']['theme'])) {
            $today['plan']['chapter_title'] = $k->i18n->t('chapter.' . $today['plan']['theme']);
        }

        $today['state_title'] = $k->i18n->t('state.' . $today['state']);
        $today['state_hint']  = $k->i18n->t('state.' . $today['state'] . '.hint');

        return Response::json($today);
    }, ['auth' => true]);

    $router->post('/api/checkin', static function (Request $r, Kernel $k) use ($fail): Response {
        /** @var Checkins $checkins */
        $checkins = $k->container->get(Checkins::class);
        $result   = $checkins->record((int) $r->userId(), $r->body());

        if (!$result->ok) {
            return $fail($k, $result);
        }

        $payload = $result->data;
        $payload['message'] = $payload['returned']
            ? $k->i18n->t('checkin.welcome_back')
            : $k->i18n->t('checkin.saved');

        return Response::json($payload);
    }, ['auth' => true]);

    $router->post('/api/checkin/event', static function (Request $r, Kernel $k) use ($fail): Response {
        /** @var Checkins $checkins */
        $checkins = $k->container->get(Checkins::class);
        $result   = $checkins->markEvent((int) $r->userId(), $r->body());

        return $result->ok
            ? Response::json($result->data + ['message' => $k->i18n->t('checkin.event_saved')])
            : $fail($k, $result);
    }, ['auth' => true]);

    $router->get('/api/checkin/history', static function (Request $r, Kernel $k): Response {
        /** @var Checkins $checkins */
        $checkins = $k->container->get(Checkins::class);
        $days     = max(7, min(180, $r->int('days', 30)));

        return Response::json(['days' => $checkins->history((int) $r->userId(), $days)]);
    }, ['auth' => true]);

    /**
     * Return Rate по всей базе. Открыто только администратору:
     * это метрика продукта, а не личная статистика.
     */
    $router->get('/api/metrics/return-rate', static function (Request $r, Kernel $k): Response {
        $user = $r->user();
        if (($user['role'] ?? '') !== 'admin') {
            return Response::json(['error' => 'forbidden'], 403);
        }

        /** @var Recovery $recovery */
        $recovery = $k->container->get(Recovery::class);
        return Response::json($recovery->returnRate());
    }, ['auth' => true]);
};
