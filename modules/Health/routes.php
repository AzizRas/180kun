<?php
declare(strict_types=1);

use App\Kernel;
use App\Request;
use App\Response;
use App\Router;
use Modules\Health\Report;

return static function (Router $router, Kernel $kernel): void {

    // Человекочитаемая проверка. Открыть после установки на хостинг.
    $router->get('/health', static function (Request $r, Kernel $k): Response {
        $report = (new Report($k))->build();
        return $r->str('format') === 'json'
            ? Response::json($report)
            : Response::html((new Report($k))->render($report));
    });

    // Машинная версия для мониторинга.
    $router->get('/health.json', static function (Request $r, Kernel $k): Response {
        $report = (new Report($k))->build();
        return Response::json($report, $report['ok'] ? 200 : 503);
    });
};
