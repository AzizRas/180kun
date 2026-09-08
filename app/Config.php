<?php
declare(strict_types=1);

namespace App;

final class Config
{
    public function __construct(private array $data)
    {
    }

    /** Доступ по точке: $config->get('ai.model', 'gemini-flash-lite') */
    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public function set(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $node  = &$this->data;
        foreach ($parts as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
        $node = $value;
    }

    public function all(): array
    {
        return $this->data;
    }
}
