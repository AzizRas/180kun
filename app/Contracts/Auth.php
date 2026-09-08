<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Request;

/**
 * Контракт авторизации. Реализуется модулем Identity.
 * Всё остальное приложение знает только этот интерфейс.
 */
interface Auth
{
    /** @return array|null профиль текущего пользователя или null */
    public function currentUser(Request $request): ?array;

    public function userById(int $id): ?array;

    /** @return array{token: string, user: array}|null */
    public function issueSession(int $userId): ?array;

    public function revokeSession(string $token): void;
}
