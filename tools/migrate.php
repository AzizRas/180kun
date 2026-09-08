<?php
declare(strict_types=1);

/**
 * Применяет миграции всех включённых модулей.
 *
 *   php tools/migrate.php          применить
 *   php tools/migrate.php --dry    показать, что будет применено
 *
 * На хостинге без SSH то же самое делает открытие /health — там видно,
 * какие миграции не применены; применить их можно кнопкой в админке (слайс 6).
 */

/** @var App\Kernel $kernel */
$kernel = require dirname(__DIR__) . '/app/bootstrap.php';

$dry    = in_array('--dry', $argv ?? [], true);
$result = (new App\Migrator($kernel))->migrate($dry);

echo $dry ? "Будет применено:\n" : "Применено:\n";
foreach ($result['applied'] as $name) {
    echo "  + {$name}\n";
}
if (!$result['applied']) {
    echo "  (нечего применять)\n";
}
echo "Пропущено (уже применены): {$result['skipped']}\n";

if ($result['errors']) {
    echo "\nОШИБКИ:\n";
    foreach ($result['errors'] as $e) {
        echo "  ! {$e}\n";
    }
    exit(1);
}
