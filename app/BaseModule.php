<?php
declare(strict_types=1);

namespace App;

/**
 * Базовый класс модуля. Каждый модуль наследует его в modules/<Name>/Module.php.
 *
 * Жизненный цикл:
 *   register() — положить свои сервисы в контейнер. Здесь НЕЛЬЗЯ обращаться
 *                к сервисам других модулей: они могут быть ещё не зарегистрированы.
 *   boot()     — подписаться на события, прочитать чужие контракты. Здесь можно всё.
 */
abstract class BaseModule
{
    public function __construct(public ModuleInfo $info)
    {
    }

    public function register(Container $container, Kernel $kernel): void
    {
    }

    public function boot(Kernel $kernel): void
    {
    }

    /** Префикс таблиц модуля: identity_ -> identity_users */
    protected function table(string $name): string
    {
        return $this->info->prefix . $name;
    }
}
