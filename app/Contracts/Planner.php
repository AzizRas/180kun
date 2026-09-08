<?php
declare(strict_types=1);

namespace App\Contracts;

/**
 * Контракт планировщика. Реализуется модулем Planning.
 *
 * Решение Р-11: все числа о нагрузке и темпе считает детерминированный
 * код внутри этого модуля. ИИ-тренер получает уже готовый коридор и
 * только выбирает формулировку — поэтому Coach зависит от Planner,
 * а не наоборот.
 */
interface Planner
{
    /** Текущий план пользователя или null, если ещё не построен. */
    public function currentPlan(int $userId): ?array;

    /**
     * Что делать сегодня.
     *
     * @return array{day: int, chapter: int, week: int, action: ?array, norm: array}|null
     */
    public function today(int $userId, ?string $date = null): ?array;

    /**
     * Построить план заново — при первом онбординге и при пересборке
     * после длительного перерыва.
     */
    public function build(int $userId, array $profile, array $baseline): ?array;

    public function hasPlan(int $userId): bool;
}
