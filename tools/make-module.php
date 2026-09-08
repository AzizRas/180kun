<?php
declare(strict_types=1);

/**
 * Создаёт скелет нового модуля со всей обязательной обвязкой.
 *
 *   php tools/make-module.php Checkin checkin_
 *
 * После этого добавьте имя модуля в modules.php.
 */

$root = dirname(__DIR__);
$name = $argv[1] ?? '';

if ($name === '') {
    fwrite(STDERR, "Использование: php tools/make-module.php <ИмяМодуля> [префикс_таблиц_]\n");
    exit(1);
}

$dirName = ucfirst($name);
$slug    = strtolower($name);
$prefix  = $argv[2] ?? ($slug . '_');
$base    = $root . '/modules/' . $dirName;

if (is_dir($base)) {
    fwrite(STDERR, "Модуль {$dirName} уже существует.\n");
    exit(1);
}

$files = [
    'module.json' => json_encode([
        'name'         => $slug,
        'title'        => $dirName,
        'version'      => '0.1.0',
        'description'  => 'TODO: одна строка о том, за что отвечает модуль.',
        'table_prefix' => $prefix,
        'requires'     => [],
        'provides'     => [],
        'emits'        => [],
        'listens'      => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",

    'Module.php' => <<<PHP
<?php
declare(strict_types=1);

namespace Modules\\{$dirName};

use App\\BaseModule;
use App\\Container;
use App\\Kernel;

final class Module extends BaseModule
{
    public function register(Container \$container, Kernel \$kernel): void
    {
        // Сервисы модуля кладём в контейнер. Чужие сервисы здесь не трогаем.
    }

    public function boot(Kernel \$kernel): void
    {
        // Подписки на события других модулей.
        // \$kernel->events->on('checkin.recorded', fn(array \$p) => ..., '{$slug}');
    }
}

PHP,

    'routes.php' => <<<PHP
<?php
declare(strict_types=1);

use App\\Kernel;
use App\\Request;
use App\\Response;
use App\\Router;

return static function (Router \$router, Kernel \$kernel): void {
    \$router->get('/api/{$slug}/ping', static function (Request \$r, Kernel \$k): Response {
        return Response::json(['module' => '{$slug}', 'pong' => true]);
    });
};

PHP,

    'Migrations/001_init.sql' => "-- Таблицы модуля {$dirName}. Только префикс {$prefix}\n"
        . "-- CREATE TABLE IF NOT EXISTS {$prefix}items (\n"
        . "--     id INTEGER PRIMARY KEY AUTOINCREMENT,\n"
        . "--     created_at TEXT NOT NULL\n"
        . "-- );\n",

    'lang/ru.php' => "<?php\nreturn [\n    '{$slug}.title' => '{$dirName}',\n];\n",
    'lang/uz.php' => "<?php\nreturn [\n    '{$slug}.title' => '{$dirName}',\n];\n",
];

foreach ($files as $rel => $content) {
    $path = $base . '/' . $rel;
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, $content);
    echo "  + modules/{$dirName}/{$rel}\n";
}

echo "\nГотово. Теперь добавьте '{$slug}' в modules.php и запустите php tools/migrate.php\n";
