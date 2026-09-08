<?php
declare(strict_types=1);

namespace Modules\Identity\Domain;

use App\Contracts\Auth;
use App\Kernel;
use App\Request;

/**
 * Реализация контракта Auth.
 *
 * Токен сессии отдаётся клиенту, а в базе хранится только его хеш —
 * утечка дампа базы не даёт войти под чужим аккаунтом.
 */
final class AuthService implements Auth
{
    /**
     * Кеш привязан к конкретному объекту запроса, а не к сервису: сервис
     * живёт как singleton, и через него проходит несколько разных запросов.
     *
     * Именно WeakMap, а не spl_object_id: идентификаторы объектов PHP
     * переиспользуются после сборки мусора, и новый запрос мог получить
     * ответ, закешированный для давно уничтоженного чужого запроса.
     * Записи WeakMap умирают вместе с самим запросом.
     *
     * @var \WeakMap<Request, array|null>
     */
    private \WeakMap $cache;

    public function __construct(private Kernel $kernel, private Users $users)
    {
        $this->cache = new \WeakMap();
    }

    public function currentUser(Request $request): ?array
    {
        if (isset($this->cache[$request]) || $this->cache->offsetExists($request)) {
            return $this->cache[$request];
        }

        $token = $request->header('x-session-token') ?? $request->cookie('l180_session');
        if (!$token) {
            return $this->cache[$request] = null;
        }

        $row = $this->kernel->db()->first(
            'SELECT user_id FROM identity_sessions WHERE token_hash = ? AND expires_at > ?',
            [$this->hash($token), gmdate('c')]
        );
        if ($row === null) {
            return $this->cache[$request] = null;
        }

        $user = $this->users->findById((int) $row['user_id']);
        if ($user === null || $user['status'] === 'blocked') {
            return $this->cache[$request] = null;
        }

        $this->users->touch((int) $user['id']);
        return $this->cache[$request] = $user;
    }

    public function userById(int $id): ?array
    {
        return $this->users->findById($id);
    }

    public function issueSession(int $userId): ?array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $days  = (int) $this->kernel->config->get('auth.session_days', 180);

        $this->kernel->db()->insert('identity_sessions', [
            'user_id'    => $userId,
            'token_hash' => $this->hash($token),
            'created_at' => gmdate('c'),
            'expires_at' => gmdate('c', time() + $days * 86400),
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
        ]);

        $this->kernel->events->emit('user.logged_in', ['user' => $user]);

        return ['token' => $token, 'user' => $user];
    }

    public function revokeSession(string $token): void
    {
        $this->kernel->db()->run('DELETE FROM identity_sessions WHERE token_hash = ?', [$this->hash($token)]);
        $this->kernel->events->emit('user.logged_out', []);
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->kernel->secret());
    }
}
