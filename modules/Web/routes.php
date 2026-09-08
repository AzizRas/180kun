<?php
declare(strict_types=1);

use App\Kernel;
use App\Request;
use App\Response;
use App\Router;

return static function (Router $router, Kernel $kernel): void {

    /** Строки интерфейса — фронтенд забирает их одним запросом. */
    $router->get('/api/i18n', static function (Request $r, Kernel $k): Response {
        // Отдаём все строки языка целиком: их несколько сотен, это
        // единицы килобайт, и один запрос дешевле, чем догрузка по частям
        // на каждом экране.
        $lang = $r->lang((string) $k->config->get('app.default_lang', 'ru'));

        return Response::json(['lang' => $lang, 'strings' => $k->i18n->all($lang)]);
    });

    /** Оболочка приложения. Всё остальное рисует app.js. */
    $router->get('/', static function (Request $r, Kernel $k): Response {
        $lang    = $r->lang((string) $k->config->get('app.default_lang', 'ru'));
        $version = '1.0.0';
        $e       = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!doctype html>
<html lang="{$e($lang)}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0B0F14">
<title>{$e($k->i18n->t('ui.app_name', [], $lang))}</title>
<link rel="manifest" href="/assets/manifest.json">
<link rel="stylesheet" href="/assets/app.css?v={$version}">
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>
<div id="app" class="screen-host" aria-live="polite">
  <div class="boot">
    <div class="boot-mark">180</div>
    <p class="boot-text">{$e($k->i18n->t('ui.loading', [], $lang))}</p>
  </div>
</div>
<script>window.L180 = {lang: "{$e($lang)}"};</script>
<script src="/assets/app.js?v={$version}"></script>
</body>
</html>
HTML;

        return Response::html($html);
    });
};
