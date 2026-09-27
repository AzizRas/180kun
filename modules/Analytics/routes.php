<?php
declare(strict_types=1);

use App\Kernel;
use App\Request;
use App\Response;
use App\Router;
use Modules\Analytics\Domain\Dashboard;

return static function (Router $router, Kernel $kernel): void {

    /** Панель метрик § 13. Только для модераторов: это данные о людях, пусть и агрегированные. */
    $router->get('/api/admin/metrics', static function (Request $r, Kernel $k): Response {
        if (!in_array((string) ($r->user()['role'] ?? ''), ['admin', 'moderator'], true)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $data = $k->container->get(Dashboard::class)->build($r->str('date') ?: null);
        foreach ($data['metrics'] as &$m) {
            $m['title'] = $k->i18n->t('metric.' . $m['key']);
            $m['hint']  = $k->i18n->t('metric.' . $m['key'] . '.low');
        }
        unset($m);
        return Response::json(['ok' => true] + $data);
    }, ['auth' => true]);
};
