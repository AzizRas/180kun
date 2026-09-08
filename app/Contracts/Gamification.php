<?php
declare(strict_types=1);

namespace App\Contracts;

/**
 * Очки, уровни, серии. Реализуется модулем Gamification.
 * Модуль Checkin публикует события и НЕ знает, начисляется ли что-то за них.
 */
interface Gamification
{
    public function award(int $userId, string $reason, int $amount = 0): int;

    /** @return array{xp: int, level: int, streak: int, shields: int} */
    public function profile(int $userId): array;
}
