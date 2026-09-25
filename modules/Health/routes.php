<?php
declare(strict_types=1);

use App\Contracts\Auth;
use App\Kernel;
use App\Request;
use App\Response;
use App\Router;
use Modules\Health\Report;

return static function (Router $router, Kernel $kernel): void {

    /**
     * Полный отчёт раскрывает устройство приложения: пути, маршруты,
     * модули. Его видят только: админ в своём аккаунте, владелец ключа
     * HEALTH_KEY (/health?key=...) и любой при включённой отладке.
     * Остальным — только «работает / не работает».
     */
    $mayViewDetails = static function (Request $r, Kernel $k): bool {
        if ($k->config->get('app.debug')) {
            return true;
        }
        $key = (string) $k->config->get('admin.health_key', '');
        if ($key !== '' && hash_equals($key, $r->str('key'))) {
            return true;
        }
        try {
            $user = $k->container->get(Auth::class)->currentUser($r);
        } catch (\Throwable) {
            $user = null;   // база недоступна — значит, и админа не узнать
        }
        return ($user['role'] ?? '') === 'admin';
    };

    $brief = static fn(array $report): array => [
        'ok'   => $report['ok'],
        'app'  => $report['app'],
        'time' => $report['time'],
    ];

    // Человекочитаемая проверка. Открыть после установки на хостинг.
    $router->get('/health', static function (Request $r, Kernel $k) use ($mayViewDetails, $brief): Response {
        $report = (new Report($k))->build();

        if (!$mayViewDetails($r, $k)) {
            return $r->str('format') === 'json'
                ? Response::json($brief($report))
                : Response::html((new Report($k))->renderBrief($report));
        }

        return $r->str('format') === 'json'
            ? Response::json($report)
            : Response::html((new Report($k))->render($report));
    });

    // Машинная версия для мониторинга: статус кодом ответа.
    $router->get('/health.json', static function (Request $r, Kernel $k) use ($mayViewDetails, $brief): Response {
        $report = (new Report($k))->build();
        $body   = $mayViewDetails($r, $k) ? $report : $brief($report);
        return Response::json($body, $report['ok'] ? 200 : 503);
    });
};
