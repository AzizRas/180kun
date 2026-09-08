<?php
declare(strict_types=1);

namespace Modules\Onboarding\Domain;

/**
 * Ступень стартового уровня T0–T3 (§ 05 досье).
 *
 * Единственный параметр, по которому сквады однородны жёстко, поэтому
 * считается по измеренному baseline, а не по самооценке: человек почти
 * всегда переоценивает свою активность.
 */
final class Tier
{
    public const LEVELS = ['T0', 'T1', 'T2', 'T3'];

    /** Границы по медиане шагов за 7 дней. */
    private const STEPS = ['T0' => 0, 'T1' => 4000, 'T2' => 7500, 'T3' => 11000];

    /**
     * @param int $stepsMedian медиана шагов за 7 дней
     * @param int $workouts    тренировок в неделю
     */
    public static function classify(int $stepsMedian, int $workouts): string
    {
        $bySteps = 'T0';
        foreach (self::STEPS as $tier => $min) {
            if ($stepsMedian >= $min) {
                $bySteps = $tier;
            }
        }

        // Тренировки поднимают ступень, но не опускают: человек может мало
        // ходить и при этом регулярно заниматься в зале.
        $byWorkouts = match (true) {
            $workouts >= 4 => 'T3',
            $workouts >= 2 => 'T2',
            $workouts >= 1 => 'T1',
            default        => 'T0',
        };

        return array_search($byWorkouts, self::LEVELS, true) > array_search($bySteps, self::LEVELS, true)
            ? $byWorkouts
            : $bySteps;
    }

    public static function index(string $tier): int
    {
        $i = array_search($tier, self::LEVELS, true);
        return $i === false ? 0 : (int) $i;
    }

    /**
     * Медиана — не среднее: один день с 30 000 шагов в отпуске не должен
     * поднимать человеку ступень и делать план невыполнимым.
     *
     * @param array<int, int|float> $values
     */
    public static function median(array $values): int
    {
        $values = array_values(array_filter($values, static fn($v) => $v !== null && $v > 0));
        if ($values === []) {
            return 0;
        }
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);

        return (int) round($n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2);
    }
}
