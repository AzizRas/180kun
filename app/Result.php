<?php
declare(strict_types=1);

namespace App;

/**
 * Результат доменной операции. Домен не бросает исключения на ожидаемые
 * ошибки (занятый телефон, неверный пароль) и не знает про HTTP —
 * это позволяет переиспользовать его из бота, CLI и тестов.
 */
final class Result
{
    private function __construct(
        public bool $ok,
        public mixed $data = null,
        public string $error = '',
        public array $meta = [],
    ) {
    }

    public static function ok(mixed $data = null, array $meta = []): self
    {
        return new self(true, $data, '', $meta);
    }

    /** @param string $error машинный код ошибки: phone_taken, bad_credentials ... */
    public static function fail(string $error, array $meta = []): self
    {
        return new self(false, null, $error, $meta);
    }

    public function toResponse(int $okStatus = 200, int $failStatus = 400): Response
    {
        if ($this->ok) {
            $payload = is_array($this->data) ? $this->data : ['data' => $this->data];
            return Response::json($payload + $this->meta, $okStatus);
        }
        return Response::json(['error' => $this->error] + $this->meta, $failStatus);
    }
}
