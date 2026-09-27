<?php
declare(strict_types=1);

use App\Kernel;
use App\Request;
use App\Response;
use App\Router;
use Modules\Media\Domain\MediaService;

return static function (Router $router, Kernel $kernel): void {

    $media = static fn(Kernel $k): MediaService => $k->container->get(MediaService::class);

    /**
     * Отдача фото по подписанной ссылке. Входа не требует: картинку
     * грузит тег <img>, а он не умеет слать заголовок сессии. Вместо
     * этого — подпись на конкретного зрителя со сроком 10–15 минут.
     * Любая ошибка — 404, чтобы по ответу нельзя было подбирать имена.
     */
    $router->get('/api/media/{token}/{variant}', static function (Request $r, Kernel $k) use ($media): Response {
        $file = $media($k)->serve(
            (string) $r->param('token'),
            (string) $r->param('variant'),
            $r->int('u'),
            $r->int('e'),
            $r->str('s')
        );
        if ($file === null) {
            return Response::json(['error' => 'not_found'], 404);
        }
        if ($r->header('if-none-match') === $file['etag']) {
            return Response::binary('', 'image/jpeg', ['ETag' => $file['etag']], 304);
        }
        return Response::binary($file['bytes'], 'image/jpeg', [
            'ETag'                         => $file['etag'],
            'Cache-Control'                => 'private, max-age=' . max(0, $file['expires'] - time()),
            'X-Content-Type-Options'       => 'nosniff',
            'Content-Security-Policy'      => "default-src 'none'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy'              => 'no-referrer',
            'Content-Disposition'          => 'inline',
        ]);
    });

    /** Модератор: сколько фото, сколько места, работает ли приём. */
    $router->get('/api/admin/media', static function (Request $r, Kernel $k) use ($media): Response {
        if (!in_array((string) ($r->user()['role'] ?? ''), ['admin', 'moderator'], true)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $stats = $media($k)->stats();
        if (!$stats['available']['ok']) {
            $stats['available']['message'] = $k->i18n->t('media.off.' . $stats['available']['reason']);
            $stats['available']['platform'] = MediaService::foreignPlatform();
        }
        return Response::json(['ok' => true] + $stats);
    }, ['auth' => true]);
};
