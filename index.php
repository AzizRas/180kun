<?php
declare(strict_types=1);

/**
 * Единственная точка входа веб-приложения.
 * Всё — и страницы, и API — проходит через маршрутизатор ядра.
 */

// Встроенный сервер PHP (php -S localhost:8000 index.php) отдаёт сам
// только assets/ — тот же белый список, что в Caddyfile и .htaccess.
if (PHP_SAPI === 'cli-server') {
    $path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (str_starts_with($path, '/assets/') && !str_contains($path, '..') && is_file(__DIR__ . $path)) {
        return false;
    }
}

/** @var App\Kernel $kernel */
$kernel = require __DIR__ . '/app/bootstrap.php';

date_default_timezone_set((string) $kernel->config->get('app.timezone', 'Asia/Tashkent'));

if ($kernel->config->get('app.debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');   // ошибки уходят в JSON и лог, а не в вёрстку
}

$request = App\Request::fromGlobals();
$kernel->i18n->setLang($request->lang((string) $kernel->config->get('app.default_lang', 'ru')));

$kernel->handle($request)->send();
