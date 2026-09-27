<?php
declare(strict_types=1);

namespace Modules\Wearable\Domain;

/**
 * Правдоподобие данных (§ 10 досье, анти-фрод).
 *
 * Шаги, выросшие больше чем втрое против собственной медианы за 30 дней
 * без соответствующего роста активных минут, — это тряска телефона или
 * ошибка выгрузки, а не прогулка. Такой день не удаляется и никого не
 * наказывает: он просто не идёт в расчёты, и человек видит почему.
 */
final class Plausibility
{
    public const LIMITS = [
        'steps'      => [0, 60000],
        'active_min' => [0, 600],
        'rhr'        => [30, 130],
        'sleep_min'  => [0, 960],
    ];

    public const SPIKE_FACTOR = 3.0;
    public const MIN_HISTORY  = 5;   // меньше дней истории — сравнивать не с чем

    /** Значение вне физических границ — ошибка ввода, а не рекорд. */
    public static function outOfRange(string $field, ?int $value): bool
    {
        if ($value === null || !isset(self::LIMITS[$field])) {
            return false;
        }
        [$min, $max] = self::LIMITS[$field];
        return $value < $min || $value > $max;
    }

    /**
     * @param array<int, int> $stepsHistory  шаги за прошлые дни (до 30)
     * @param array<int, int> $activeHistory активные минуты за те же дни
     * @return ?string причина подозрения или null
     */
    public static function suspectSteps(?int $steps, ?int $active, array $stepsHistory, array $activeHistory): ?string
    {
        if ($steps === null || count($stepsHistory) < self::MIN_HISTORY) {
            return null;
        }
        $median = self::median($stepsHistory);
        if ($median <= 0 || $steps <= self::SPIKE_FACTOR * $median) {
            return null;
        }

        // Скачок шагов подтверждён активными минутами — верим.
        if ($active !== null && $activeHistory !== []) {
            $activeMedian = self::median($activeHistory);
            if ($activeMedian > 0 && $active >= 2 * $activeMedian) {
                return null;
            }
        }
        return 'steps_spike';
    }

    public static function median(array $values): float
    {
        $values = array_values(array_filter($values, static fn($v) => $v !== null));
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
