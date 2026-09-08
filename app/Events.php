<?php
declare(strict_types=1);

namespace App;

/**
 * Шина событий — основной способ общения между модулями.
 *
 * ПРАВИЛО ПРОЕКТА: модуль НИКОГДА не вызывает код другого модуля напрямую.
 * Он либо публикует событие, либо запрашивает контракт из контейнера.
 * Поэтому удаление слушателя не ломает издателя.
 *
 * Слушатель, бросивший исключение, не роняет остальных: ошибка пишется в лог.
 */
final class Events
{
    /** @var array<string, array<int, array{fn: callable, module: string, priority: int}>> */
    private array $listeners = [];
    /** @var array<int, array{event: string, at: float, listeners: int}> */
    private array $trace = [];
    private array $errors = [];

    public function __construct(private bool $debug = false)
    {
    }

    public function on(string $event, callable $fn, string $module = 'app', int $priority = 100): void
    {
        $this->listeners[$event][] = ['fn' => $fn, 'module' => $module, 'priority' => $priority];
        usort($this->listeners[$event], static fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    /**
     * Публикует событие. Возвращает payload — слушатели могут его дополнять,
     * но не обязаны. Издатель не знает, слушает ли его хоть кто-то.
     */
    public function emit(string $event, array $payload = []): array
    {
        $listeners = $this->listeners[$event] ?? [];

        if ($this->debug) {
            $this->trace[] = ['event' => $event, 'at' => microtime(true), 'listeners' => count($listeners)];
        }

        foreach ($listeners as $l) {
            try {
                $result = ($l['fn'])($payload, $this);
                if (is_array($result)) {
                    $payload = $result;
                }
            } catch (\Throwable $e) {
                // Падение слушателя не должно ломать основной сценарий.
                $this->errors[] = [
                    'event'   => $event,
                    'module'  => $l['module'],
                    'message' => $e->getMessage(),
                    'where'   => $e->getFile() . ':' . $e->getLine(),
                ];
            }
        }

        return $payload;
    }

    public function hasListeners(string $event): bool
    {
        return !empty($this->listeners[$event]);
    }

    /** @return array<string, array<int, string>> событие => список модулей */
    public function map(): array
    {
        $out = [];
        foreach ($this->listeners as $event => $list) {
            $out[$event] = array_map(static fn($l) => $l['module'], $list);
        }
        ksort($out);
        return $out;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function trace(): array
    {
        return $this->trace;
    }
}
