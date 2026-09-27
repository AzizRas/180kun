<?php
declare(strict_types=1);

namespace App;

final class Request
{
    private array $params = [];
    private ?array $user  = null;
    /** @var array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}> */
    private array $files  = [];

    private function __construct(
        private string $method,
        private string $path,
        private array $query,
        private array $body,
        private array $headers,
        private array $cookies,
    ) {
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . trim($path, '/');

        $raw  = (string) file_get_contents('php://input');
        $body = $_POST;
        if ($raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }

        $request = new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $body,
            $headers,
            $_COOKIE
        );
        foreach ($_FILES as $name => $f) {
            if (is_array($f) && isset($f['tmp_name']) && is_string($f['tmp_name'])) {
                $request->files[(string) $name] = $f;
            }
        }
        return $request;
    }

    /**
     * Для тестов, CLI и Telegram-бота.
     *
     * Строка запроса разбирается так же, как из глобалов: иначе путь
     * с «?» не совпадёт ни с одним маршрутом и тест молча получит 404
     * вместо проверки того, что задумано.
     */
    public static function make(string $method, string $path, array $body = [], array $query = [], array $headers = []): self
    {
        if (str_contains($path, '?')) {
            [$path, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $fromPath);
            $query = $query + $fromPath;
        }

        return new self(strtoupper($method), '/' . trim($path, '/'), $query, $body, $headers, []);
    }

    public function method(): string { return $this->method; }
    public function path(): string   { return $this->path; }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->input($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        return in_array((string) $v, ['1', 'true', 'yes', 'on'], true);
    }

    public function body(): array  { return $this->body; }

    /**
     * Загруженный файл (multipart). Возвращает содержимое, а не путь:
     * домену всё равно, пришёл файл формой или строкой base64.
     */
    public function fileBytes(string $name, int $maxBytes): ?string
    {
        $f = $this->files[$name] ?? null;
        if ($f === null || (int) ($f['error'] ?? 1) !== UPLOAD_ERR_OK || (int) ($f['size'] ?? 0) > $maxBytes) {
            return null;
        }
        $path = (string) $f['tmp_name'];
        // В тестах файл подкладывается напрямую, в бою — только загруженный PHP.
        if (!is_file($path) || (PHP_SAPI !== 'cli' && !is_uploaded_file($path))) {
            return null;
        }
        $bytes = file_get_contents($path);
        return $bytes === false ? null : $bytes;
    }

    /** Для тестов: подложить загруженный файл. */
    public function withFile(string $name, string $path): self
    {
        $this->files[$name] = ['name' => basename($path), 'type' => '', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($path)];
        return $this;
    }
    public function query(): array { return $this->query; }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function param(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    public function setParam(string $name, mixed $value): void
    {
        $this->params[$name] = $value;
    }

    public function user(): ?array { return $this->user; }

    public function setUser(?array $user): void { $this->user = $user; }

    public function userId(): ?int
    {
        return isset($this->user['id']) ? (int) $this->user['id'] : null;
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** Язык: ?lang, затем cookie, затем заголовок браузера. */
    public function lang(string $default = 'ru'): string
    {
        $candidates = [
            $this->str('lang'),
            $this->cookie('lang', ''),
            substr((string) $this->header('accept-language', ''), 0, 2),
        ];
        foreach ($candidates as $c) {
            $c = strtolower(trim((string) $c));
            if (in_array($c, ['ru', 'uz'], true)) {
                return $c;
            }
        }
        return $default;
    }
}
