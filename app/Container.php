<?php
declare(strict_types=1);

namespace App;

/**
 * Контейнер зависимостей.
 *
 * Ключевая для проекта возможность — fallback(): если модуль, реализующий
 * контракт, выключен, контейнер отдаёт безопасную заглушку вместо падения.
 * Именно это делает выключение модуля безопасным.
 */
final class Container
{
    /** @var array<string, callable> */
    private array $factories = [];
    /** @var array<string, mixed> */
    private array $instances = [];
    /** @var array<string, callable> */
    private array $fallbacks = [];
    /** @var array<string, bool> */
    private array $shared = [];
    /** @var array<string, string> кто предоставил реализацию — для диагностики */
    private array $providers = [];

    public function instance(string $id, mixed $object): void
    {
        $this->instances[$id] = $object;
    }

    public function bind(string $id, callable $factory, string $provider = 'app'): void
    {
        $this->factories[$id] = $factory;
        $this->providers[$id] = $provider;
        unset($this->instances[$id]);
    }

    public function singleton(string $id, callable $factory, string $provider = 'app'): void
    {
        $this->bind($id, $factory, $provider);
        $this->shared[$id] = true;
    }

    /**
     * Заглушка на случай, если контракт никто не реализовал.
     * Регистрируется ядром, а не модулем.
     */
    public function fallback(string $id, callable $factory): void
    {
        $this->fallbacks[$id] = $factory;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    /** Есть ли НАСТОЯЩАЯ реализация (не заглушка). */
    public function isReal(string $id): bool
    {
        return $this->has($id);
    }

    public function providerOf(string $id): string
    {
        return $this->providers[$id] ?? ($this->has($id) ? 'app' : 'fallback');
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            $object = ($this->factories[$id])($this);
            if (!empty($this->shared[$id])) {
                $this->instances[$id] = $object;
            }
            return $object;
        }

        if (isset($this->fallbacks[$id])) {
            return $this->instances[$id] = ($this->fallbacks[$id])($this);
        }

        throw new \RuntimeException("Сервис не зарегистрирован и не имеет заглушки: {$id}");
    }

    /** @return array<string, string> список контракт => провайдер, для админки и health */
    public function map(): array
    {
        $ids = array_unique(array_merge(
            array_keys($this->factories),
            array_keys($this->instances),
            array_keys($this->fallbacks)
        ));
        sort($ids);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->providerOf($id);
        }
        return $out;
    }
}
