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

return $kernel;
