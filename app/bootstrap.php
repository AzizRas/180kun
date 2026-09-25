<?php
declare(strict_types=1);

/**
 * Единственная точка сборки приложения.
 * Используется и public/index.php, и CLI-инструментами, и тестами.
 */

use App\Contracts;
use App\Kernel;

$root = dirname(__DIR__);

require_once $root . '/app/Kernel.php';
Kernel::registerAutoloader($root);

require_once $root . '/app/Contracts/Nulls.php';

$kernel = new Kernel($root);

// Заглушки для всех контрактов — регистрируются ДО модулей.
// Модуль, реализующий контракт, перекрывает заглушку в register().
$kernel->container->fallback(Contracts\Auth::class,         static fn() => new Contracts\NullAuth());
$kernel->container->fallback(Contracts\Coach::class,        static fn() => new Contracts\NullCoach());
$kernel->container->fallback(Contracts\Notifier::class,     static fn() => new Contracts\EventNotifier($kernel->events));
$kernel->container->fallback(Contracts\Planner::class,      static fn() => new Contracts\NullPlanner());
$kernel->container->fallback(Contracts\Wearable::class,     static fn() => new Contracts\NullWearable());
$kernel->container->fallback(Contracts\Gamification::class, static fn() => new Contracts\NullGamification());

$kernel->boot();

/**
 * Автоматические миграции.
 *
 * На shared-хостинге и на платформах вроде Railway нет удобного шага,
 * где можно выполнить `php tools/migrate.php` после деплоя, — и продукт
 * встречает пользователя ошибкой «no such table». Поэтому набор миграций
 * сверяется по отпечатку имён файлов: пока он не менялся и база на месте,
 * к базе никто не обращается; после деплоя с новыми миграциями или на
 * новом пустом томе они применяются один раз.
 *
 * Блокировка нужна потому, что первые запросы после деплоя приходят
 * одновременно, и без неё два процесса начали бы одну миграцию.
 */
if ($kernel->config->get('app.auto_migrate', true)) {
    $dataDir         = $kernel->dataDir();
    $fingerprintFile = $dataDir . '/.migrations';
    $dbPath          = (string) $kernel->config->get('db.path');
    $names           = [];

    foreach ($kernel->modules->enabled() as $module) {
        foreach (glob($module->path . '/Migrations/*.sql') ?: [] as $file) {
            $names[] = $module->name . '/' . basename($file);
        }
    }
    sort($names);
    $fingerprint = md5($dbPath . '|' . implode('|', $names));

    $known = is_file($fingerprintFile) ? trim((string) file_get_contents($fingerprintFile)) : '';

    if ($fingerprint !== $known || !is_file($dbPath)) {
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }
        $lock = @fopen($dataDir . '/.migrate.lock', 'c');
        try {
            if ($lock) {
                flock($lock, LOCK_EX);   // второй процесс дождётся первого и увидит, что всё применено
            }
            $result = (new App\Migrator($kernel))->migrate();

            if ($result['errors'] === []) {
                @file_put_contents($fingerprintFile, $fingerprint);
                if ($result['applied'] !== []) {
                    $kernel->log('info', 'Миграции применены автоматически', $result['applied']);
                }
            } else {
                $kernel->log('error', 'Автомиграции не прошли', $result['errors']);
            }
        } catch (\Throwable $e) {
            // База может быть недоступна (нет прав, не смонтирован том) —
            // это должен показать /health, а не белый экран.
            $kernel->log('error', 'Автомиграции упали: ' . $e->getMessage());
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}

return $kernel;
