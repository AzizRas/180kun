<?php
declare(strict_types=1);

require __DIR__ . '/_guard.php';

/**
 * Такт планировщика для cron на своём сервере:
 *
 *   0-59/5 * * * * php /var/www/level180/tools/tick.php >/dev/null 2>&1
 *
 * Модули подписаны на system.tick: созвоны шлют напоминания, медиа
 * стирает фото с истёкшим сроком, сквады пересчитываются.
 */

/** @var App\Kernel $kernel */
$kernel = require dirname(__DIR__) . '/app/bootstrap.php';

$p = $kernel->events->emit('system.tick', ['now' => gmdate('c'), 'done' => []]);
echo gmdate('c') . ' ' . json_encode($p['done'], JSON_UNESCAPED_UNICODE) . "\n";
foreach ($kernel->events->errors() as $e) {
    fwrite(STDERR, json_encode($e, JSON_UNESCAPED_UNICODE) . "\n");
}
