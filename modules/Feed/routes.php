<?php
declare(strict_types=1);

use App\Kernel;
use App\Request;
use App\Response;
use App\Result;
use App\Router;
use Modules\Feed\Domain\Feed;

return static function (Router $router, Kernel $kernel): void {

    $feed = static fn(Kernel $k): Feed => $k->container->get(Feed::class);

    $reply = static function (Result $r, Kernel $k, int $failStatus = 422): Response {
        if ($r->ok) {
            return Response::json(['ok' => true] + (is_array($r->data) ? $r->data : []));
        }
        $code = $r->error;
        $key  = str_starts_with($code, 'media.') ? 'media.error.' . substr($code, 6) : 'feed.error.' . $code;
        $body = ['error' => $code, 'message' => $k->i18n->t($key, array_filter($r->meta, 'is_scalar'))];
        if (isset($r->meta['safety'])) {
            $body['safety'] = $r->meta['safety'];
        }
        if (isset($r->meta['media_reason'])) {
            $body['message'] = $k->i18n->t('media.off.' . $r->meta['media_reason']);
        }
        return Response::json($body, $code === 'not_found' ? 404 : $failStatus);
    };

    $router->get('/api/feed', static function (Request $r, Kernel $k) use ($feed, $reply): Response {
        $uid   = (int) $r->userId();
        $state = $feed($k)->state($uid);
        if (!$state['can_read']) {
            return Response::json(['ok' => true, 'state' => $state, 'posts' => [], 'before' => null]);
        }
        $list = $feed($k)->list($uid, $r->int('before') ?: null);
        return $list->ok ? Response::json(['ok' => true, 'state' => $state] + $list->data) : $reply($list, $k);
    }, ['auth' => true]);

    /**
     * Новое фото. Картинка — полем формы «photo» или строкой base64
     * («image», можно data:URL): приложение само уменьшает фото перед
     * отправкой, но сервер всё равно пересжимает всё, что пришло.
     */
    $router->post('/api/feed', static function (Request $r, Kernel $k) use ($feed, $reply): Response {
        $max   = (int) $k->config->get('media.max_upload_mb', 10) * 1024 * 1024;
        $bytes = $r->fileBytes('photo', $max);
        if ($bytes === null) {
            $b64 = $r->str('image');
            if (str_starts_with($b64, 'data:')) {
                $b64 = substr($b64, (int) strpos($b64, ',') + 1);
            }
            $bytes = $b64 !== '' && strlen($b64) <= (int) ceil($max * 4 / 3) + 4 ? (string) base64_decode($b64, true) : '';
        }
        $res = $feed($k)->post((int) $r->userId(), $bytes, $r->str('tag'), $r->str('caption'), $r->bool('confirm'));
        return $res->ok ? Response::json(['ok' => true] + $res->data, 201) : $reply($res, $k);
    }, ['auth' => true]);

    $router->post('/api/feed/{id}/support', static function (Request $r, Kernel $k) use ($feed, $reply): Response {
        return $reply($feed($k)->support((int) $r->userId(), (int) $r->param('id')), $k);
    }, ['auth' => true]);

    $router->post('/api/feed/{id}/report', static function (Request $r, Kernel $k) use ($feed, $reply): Response {
        return $reply($feed($k)->report((int) $r->userId(), (int) $r->param('id'), $r->str('reason')), $k);
    }, ['auth' => true]);

    $router->post('/api/feed/{id}/delete', static function (Request $r, Kernel $k) use ($feed, $reply): Response {
        return $reply($feed($k)->delete((int) $r->userId(), (int) $r->param('id')), $k);
    }, ['auth' => true]);

    // ---------------- модератор ----------------

    $isModerator = static fn(Request $r): bool => in_array((string) ($r->user()['role'] ?? ''), ['admin', 'moderator'], true);

    $router->get('/api/admin/feed/reports', static function (Request $r, Kernel $k) use ($feed, $isModerator): Response {
        if (!$isModerator($r)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        return Response::json(['ok' => true, 'reports' => $feed($k)->reports((int) $r->userId())]);
    }, ['auth' => true]);

    $router->post('/api/admin/feed/{id}/{action}', static function (Request $r, Kernel $k) use ($feed, $isModerator, $reply): Response {
        if (!$isModerator($r)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        return $reply($feed($k)->resolve((int) $r->param('id'), (string) $r->param('action'), (int) $r->userId()), $k);
    }, ['auth' => true]);
};
