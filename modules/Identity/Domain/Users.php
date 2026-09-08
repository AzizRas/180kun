<?php
declare(strict_types=1);

namespace Modules\Identity\Domain;

use App\Kernel;
use App\Result;

/**
 * Доменная логика пользователей.
 *
 * Ничего не знает про HTTP: возвращает Result, а не Response.
 * Поэтому те же методы вызываются из Telegram-бота и из тестов.
 */
final class Users
{
    public function __construct(private Kernel $kernel)
    {
    }

    /** Приводит к +998XXXXXXXXX. Возвращает null, если номер не похож на узбекский. */
    public function normalizePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '998') && strlen($digits) === 12) {
            return '+' . $digits;
        }
        if (strlen($digits) === 9) {
            return '+998' . $digits;
        }
        if (str_starts_with($digits, '8') && strlen($digits) === 10) {
            return '+998' . substr($digits, 1);
        }
        return null;
    }

    public function findByPhone(string $phone): ?array
    {
        return $this->kernel->db()->first(
            'SELECT * FROM identity_users WHERE phone = ?',
            [$phone]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->kernel->db()->first('SELECT * FROM identity_users WHERE id = ?', [$id]);
    }

    public function register(string $rawPhone, string $password, string $name = '', string $lang = 'ru'): Result
    {
        $phone = $this->normalizePhone($rawPhone);
        if ($phone === null) {
            return Result::fail('bad_phone');
        }

        $min = (int) $this->kernel->config->get('auth.password_min', 8);
        if (mb_strlen($password) < $min) {
            return Result::fail('weak_password', ['min' => $min]);
        }

        if ($this->findByPhone($phone) !== null) {
            return Result::fail('phone_taken');
        }

        $adminEmail = (string) $this->kernel->config->get('admin.email', '');
        $isFirst    = (int) $this->kernel->db()->value('SELECT COUNT(*) FROM identity_users', [], 0) === 0;

        $id = $this->kernel->db()->insert('identity_users', [
            'phone'         => $phone,
            'email'         => null,
            'name'          => mb_substr(trim($name), 0, 60),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'lang'          => in_array($lang, ['ru', 'uz'], true) ? $lang : 'ru',
            'role'          => $isFirst && $adminEmail !== '' ? 'admin' : 'user',
            'status'        => 'active',
            'created_at'    => gmdate('c'),
        ]);

        $user = $this->findById($id);

        // Другие модули узнают о новом пользователе отсюда и только отсюда.
        $this->kernel->events->emit('user.registered', ['user' => $user]);

        return Result::ok($user);
    }

    public function findByTgId(string $tgId): ?array
    {
        return $this->kernel->db()->first('SELECT * FROM identity_users WHERE tg_id = ?', [$tgId]);
    }

    /**
     * Вход через Telegram Mini App. Пароль не нужен: подлинность
     * подтверждена подписью Telegram, проверенной в модуле Telegram.
     *
     * @param array $tg данные из initData: id, username, first_name, language_code
     */
    public function upsertFromTelegram(array $tg): Result
    {
        $tgId = (string) ($tg['id'] ?? '');
        if ($tgId === '') {
            return Result::fail('bad_telegram_data');
        }

        $existing = $this->findByTgId($tgId);
        if ($existing !== null) {
            $this->kernel->db()->update('identity_users', [
                'tg_username' => (string) ($tg['username'] ?? ''),
            ], 'id = :id', ['id' => $existing['id']]);
            return Result::ok($this->findById((int) $existing['id']), ['created' => false]);
        }

        $lang    = in_array((string) ($tg['language_code'] ?? ''), ['ru', 'uz'], true)
            ? (string) $tg['language_code']
            : 'ru';
        $isFirst = (int) $this->kernel->db()->value('SELECT COUNT(*) FROM identity_users', [], 0) === 0;

        // Телефон Telegram не отдаёт без отдельного запроса — ставим
        // технический плейсхолдер, пользователь привяжет номер позже.
        $id = $this->kernel->db()->insert('identity_users', [
            'phone'         => 'tg:' . $tgId,
            'name'          => mb_substr(trim((string) ($tg['first_name'] ?? '')), 0, 60),
            'password_hash' => '',                 // пароль не задан
            'lang'          => $lang,
            'role'          => $isFirst ? 'admin' : 'user',
            'status'        => 'active',
            'tg_id'         => $tgId,
            'tg_username'   => (string) ($tg['username'] ?? ''),
            'tg_linked_at'  => gmdate('c'),
            'created_at'    => gmdate('c'),
        ]);

        $user = $this->findById($id);
        $this->kernel->events->emit('user.registered', ['user' => $user, 'via' => 'telegram']);

        return Result::ok($user, ['created' => true]);
    }

    /** Привязка Telegram к существующему аккаунту, созданному по номеру. */
    public function linkTelegram(int $userId, array $tg): Result
    {
        $tgId = (string) ($tg['id'] ?? '');
        if ($tgId === '') {
            return Result::fail('bad_telegram_data');
        }

        $taken = $this->findByTgId($tgId);
        if ($taken !== null && (int) $taken['id'] !== $userId) {
            return Result::fail('telegram_taken');
        }

        $this->kernel->db()->update('identity_users', [
            'tg_id'        => $tgId,
            'tg_username'  => (string) ($tg['username'] ?? ''),
            'tg_linked_at' => gmdate('c'),
        ], 'id = :id', ['id' => $userId]);

        return Result::ok($this->findById($userId));
    }

    /** Привязка номера к аккаунту, созданному через Telegram. */
    public function attachPhone(int $userId, string $phone, string $password = ''): Result
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === null) {
            return Result::fail('bad_phone');
        }
        $taken = $this->findByPhone($normalized);
        if ($taken !== null && (int) $taken['id'] !== $userId) {
            return Result::fail('phone_taken');
        }

        $data = ['phone' => $normalized];
        if ($password !== '') {
            $min = (int) $this->kernel->config->get('auth.password_min', 8);
            if (mb_strlen($password) < $min) {
                return Result::fail('weak_password', ['min' => $min]);
            }
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $this->kernel->db()->update('identity_users', $data, 'id = :id', ['id' => $userId]);
        return Result::ok($this->findById($userId));
    }

    public function setPassword(int $userId, string $password): Result
    {
        $min = (int) $this->kernel->config->get('auth.password_min', 8);
        if (mb_strlen($password) < $min) {
            return Result::fail('weak_password', ['min' => $min]);
        }

        $this->kernel->db()->update(
            'identity_users',
            ['password_hash' => password_hash($password, PASSWORD_DEFAULT)],
            'id = :id',
            ['id' => $userId]
        );

        // Сброс пароля закрывает все прежние сессии.
        $this->kernel->db()->run('DELETE FROM identity_sessions WHERE user_id = ?', [$userId]);

        return Result::ok($this->findById($userId));
    }

    public function verifyPassword(array $user, string $password): bool
    {
        $hash = (string) $user['password_hash'];
        if ($hash === '') {
            return false;   // аккаунт только для входа через Telegram
        }
        return password_verify($password, $hash);
    }

    public function touch(int $id): void
    {
        $this->kernel->db()->update('identity_users', ['last_seen_at' => gmdate('c')], 'id = :id', ['id' => $id]);
    }

    public function setLang(int $id, string $lang): void
    {
        if (!in_array($lang, ['ru', 'uz'], true)) {
            return;
        }
        $this->kernel->db()->update('identity_users', ['lang' => $lang], 'id = :id', ['id' => $id]);
        $this->kernel->events->emit('user.lang_changed', ['user_id' => $id, 'lang' => $lang]);
    }

    /** Публичный вид пользователя: без хеша пароля. */
    public function publicView(array $user): array
    {
        $phone = (string) $user['phone'];

        return [
            'id'           => (int) $user['id'],
            'name'         => (string) $user['name'],
            'phone'        => str_starts_with($phone, 'tg:') ? null : $this->maskPhone($phone),
            'lang'         => (string) $user['lang'],
            'role'         => (string) $user['role'],
            'status'       => (string) $user['status'],
            'has_password' => (string) $user['password_hash'] !== '',
            'has_telegram' => !empty($user['tg_id']),
            'needs_phone'  => str_starts_with($phone, 'tg:'),
        ];
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) > 6
            ? substr($phone, 0, 4) . str_repeat('*', strlen($phone) - 6) . substr($phone, -2)
            : $phone;
    }

    // --- Ограничение частоты попыток входа ---

    public function tooManyAttempts(string $ip): bool
    {
        $max   = (int) $this->kernel->config->get('auth.max_attempts', 5);
        $since = gmdate('c', time() - 900);
        $count = (int) $this->kernel->db()->value(
            'SELECT COUNT(*) FROM identity_attempts WHERE ip = ? AND ok = 0 AND created_at > ?',
            [$ip, $since],
            0
        );
        return $count >= $max;
    }

    public function recordAttempt(string $ip, ?string $phone, bool $ok): void
    {
        $this->kernel->db()->insert('identity_attempts', [
            'ip'         => $ip,
            'phone'      => $phone,
            'ok'         => $ok ? 1 : 0,
            'created_at' => gmdate('c'),
        ]);
    }
}
