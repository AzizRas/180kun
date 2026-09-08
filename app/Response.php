<?php
declare(strict_types=1);

namespace App;

final class Response
{
    private function __construct(
        public string $body,
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        if (is_array($data) && !array_key_exists('ok', $data)) {
            $data = ['ok' => $status < 400] + $data;
        }
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'] + $headers
        );
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withCookie(string $name, string $value, int $days = 180, bool $httpOnly = true): self
    {
        $parts = [
            $name . '=' . rawurlencode($value),
            'Path=/',
            'Max-Age=' . ($days * 86400),
            'SameSite=Lax',
        ];
        if ($httpOnly) {
            $parts[] = 'HttpOnly';
        }
        if (!empty($_SERVER['HTTPS'])) {
            $parts[] = 'Secure';
        }
        $this->headers['Set-Cookie'] = implode('; ', $parts);
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        echo $this->body;
    }

    /** Для тестов. */
    public function decoded(): mixed
    {
        return json_decode($this->body, true);
    }
}
