<?php
declare(strict_types=1);

namespace App;

/**
 * Маршрутизатор. Маршруты объявляют сами модули в своём routes.php.
 * Ядро не содержит ни одного захардкоженного пути приложения.
 *
 * Поддерживает параметры: /api/squad/{id}/members
 */
final class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: string, params: array, handler: callable, name: string, auth: bool}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $options = []): self
    {
        [$regex, $params] = $this->compile($pattern);

        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'regex'   => $regex,
            'params'  => $params,
            'handler' => $handler,
            'name'    => (string) ($options['name'] ?? ''),
            'auth'    => (bool) ($options['auth'] ?? false),
        ];
        return $this;
    }

    public function get(string $p, callable $h, array $o = []): self    { return $this->add('GET', $p, $h, $o); }
    public function post(string $p, callable $h, array $o = []): self   { return $this->add('POST', $p, $h, $o); }
    public function put(string $p, callable $h, array $o = []): self    { return $this->add('PUT', $p, $h, $o); }
    public function delete(string $p, callable $h, array $o = []): self { return $this->add('DELETE', $p, $h, $o); }

    /** @return array{0: string, 1: array<int, string>} */
    private function compile(string $pattern): array
    {
        $params = [];
        $regex  = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $pattern
        );
        return ['#^' . $regex . '$#', $params];
    }

    public function dispatch(Request $request, Kernel $kernel): Response
    {
        $path          = $request->path();
        $methodMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            $methodMatched = true;

            if ($route['method'] !== $request->method()) {
                continue;
            }

            foreach ($route['params'] as $name) {
                $request->setParam($name, $m[$name] ?? null);
            }

            // Проверка авторизации делегируется контракту — если модуль
            // авторизации выключен, заглушка честно вернёт «нет доступа».
            if ($route['auth']) {
                $auth = $kernel->container->get(Contracts\Auth::class);
                $user = $auth->currentUser($request);
                if ($user === null) {
                    return Response::json(['ok' => false, 'error' => 'unauthorized'], 401);
                }
                $request->setUser($user);
            }

            $result = ($route['handler'])($request, $kernel);
            return $result instanceof Response ? $result : Response::json($result);
        }

        if ($methodMatched) {
            return Response::json(['ok' => false, 'error' => 'method_not_allowed'], 405);
        }
        return Response::json(['ok' => false, 'error' => 'not_found', 'path' => $path], 404);
    }

    /** @return array<int, array{method: string, pattern: string, name: string, auth: bool}> */
    public function map(): array
    {
        return array_map(
            static fn($r) => [
                'method'  => $r['method'],
                'pattern' => $r['pattern'],
                'name'    => $r['name'],
                'auth'    => $r['auth'],
            ],
            $this->routes
        );
    }
}
