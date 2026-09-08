<?php
declare(strict_types=1);

namespace App\Contracts;

/**
 * Данные носимых устройств. Решение § 08: доверяем шагам, активным минутам,
 * ЧСС покоя в тренде и длительности сна. Калории и фазы сна не используем.
 */
interface Wearable
{
    /** @return array{steps: ?int, active_min: ?int, rhr: ?int, sleep_min: ?int, source: string} */
    public function dayMetrics(int $userId, string $date): array;

    /** Медианы за N дней — основа baseline и жёлтых флагов. */
    public function baseline(int $userId, int $days = 7): array;

    public function isConnected(int $userId): bool;
}
