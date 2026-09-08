<?php
declare(strict_types=1);

namespace Modules\Planning\Domain;

/**
 * Детерминированный расчёт плана на 180 дней. Решение Р-11.
 *
 * Здесь нет ни одного обращения к ИИ. При одинаковом входе всегда
 * получается одинаковый выход — это единственный способ отвечать
 * за числа, которые касаются здоровья, и проверять их тестами.
 *
 * Все ограничения из § 08 и Р-14 зашиты как константы, а не как
 * пожелания: превысить их нельзя ни настройкой, ни запросом.
 */
final class Calculator
{
    public const DAYS         = 180;
    public const CHAPTERS     = 6;
    public const CHAPTER_DAYS = 30;
    public const WEEKS        = 26;          // 180 / 7, последняя неделя короткая

    /** Р-12: жёсткий потолок темпа снижения веса за неделю, % массы тела. */
    public const MAX_LOSS_RATE = 1.0;

    /** Нижняя граница целевого веса: ИМТ 21 — с запасом над 18,5. */
    public const MIN_TARGET_BMI = 21.0;

    /** ВОЗ: 150–300 минут умеренной активности в неделю. */
    public const WHO_MIN_MINUTES = 150;
    public const WHO_MAX_MINUTES = 300;

    /** Потолок шагов: выше 12 000 план перестаёт быть выполнимым для офисной работы. */
    public const MAX_STEPS = 12000;

    /** Р-09: недельная норма — 4 дня из 7 и минимум 3 чек-ина. */
    public const NORM_DAYS     = 4;
    public const NORM_CHECKINS = 3;

    /** Р-14: каждая 4-я неделя — разгрузочная, объём минус 40%. */
    public const DELOAD_EVERY  = 4;
    public const DELOAD_FACTOR = 0.6;

    /** Темп по главам, % массы тела в неделю. Глава 5 — удержание. */
    private const RATE_LOSE = [1 => 0.5, 2 => 0.5, 3 => 0.5, 4 => 0.4, 5 => 0.0, 6 => 0.3];
    private const RATE_GAIN = [1 => 0.20, 2 => 0.25, 3 => 0.30, 4 => 0.25, 5 => 0.0, 6 => 0.20];

    /** Стартовый недельный объём тренировок по ступени, минут. */
    private const START_MINUTES = ['T0' => 0, 'T1' => 0, 'T2' => 90, 'T3' => 150];

    /** С какой главы появляются тренировки. */
    private const WORKOUTS_FROM = ['T0' => 2, 'T1' => 2, 'T2' => 1, 'T3' => 1];

    public const THEMES = [
        1 => 'base', 2 => 'fuel', 3 => 'load', 4 => 'equator', 5 => 'trial', 6 => 'own',
    ];

    /**
     * @param array $profile goal_dir, weight_kg, height_cm, target_kg, time_budget, tier
     * @param array $baseline steps_med, workouts
     *
     * @return array{
     *   meta: array, chapters: array<int, array>, weeks: array<int, array>
     * }
     */
    public static function build(array $profile, array $baseline): array
    {
        $goal      = ($profile['goal_dir'] ?? 'lose') === 'gain' ? 'gain' : 'lose';
        $tier      = in_array((string) ($profile['tier'] ?? 'T0'), ['T0', 'T1', 'T2', 'T3'], true)
            ? (string) $profile['tier'] : 'T0';
        $weight    = (float) ($profile['weight_kg'] ?? 0);
        $height    = (int) ($profile['height_cm'] ?? 0);
        $budget    = max(15, (int) ($profile['time_budget'] ?? 30));
        $baseSteps = max(0, (int) ($baseline['steps_med'] ?? 0));

        $weightTarget = self::targetWeight($goal, $weight, $height, $profile['target_kg'] ?? null);

        // Недельный потолок минут: то, что человек сам назвал, минус запас
        // на разминку и дорогу. План не должен требовать больше обещанного.
        $minutesCap = min(self::WHO_MAX_MINUTES, (int) round($budget * 7 * 0.8));

        $stepsCap = $goal === 'gain' ? 8000 : self::MAX_STEPS;

        $weeks    = [];
        $stepsBase   = max($baseSteps, 2000);   // накопленная прогрессия
        $minutesBase = self::START_MINUTES[$tier];
        $weightAt = $weight;

        for ($w = 1; $w <= self::WEEKS; $w++) {
            $firstDay = ($w - 1) * 7 + 1;
            $lastDay  = min(self::DAYS, $w * 7);
            $chapter  = self::chapterOfDay($firstDay);
            $deload   = $w % self::DELOAD_EVERY === 0;
            $weekInCh = (int) ceil(($firstDay - ($chapter - 1) * self::CHAPTER_DAYS) / 7);

            // База растёт независимо от разгрузки: иначе каждая четвёртая
            // неделя навсегда срезала бы прогрессию, и объём падал бы
            // от главы к главе вместо роста.
            [$stepsBase, $minutesBase] = self::progress(
                $chapter, $weekInCh, $tier, $deload, $stepsBase, $minutesBase,
                $baseSteps, $minutesCap, $stepsCap
            );

            // Разгрузка применяется только к предписанию этой недели.
            $steps   = $stepsBase;
            $minutes = $deload && $minutesBase > 0
                ? (int) round($minutesBase * self::DELOAD_FACTOR)
                : $minutesBase;

            $rate = self::rateFor($goal, $chapter);
            if ($deload) {
                $rate *= 0.5;   // на разгрузочной неделе и цель по весу мягче
            }

            $delta = $weightAt * $rate / 100;
            $next  = $goal === 'lose' ? $weightAt - $delta : $weightAt + $delta;

            // Ниже безопасного порога не опускаемся ни при каких настройках.
            if ($goal === 'lose') {
                $next = max($next, $weightTarget);
            }
            $weightAt = round($next, 1);

            $weeks[$w] = [
                'n'            => $w,
                'chapter'      => $chapter,
                'from_day'     => $firstDay,
                'to_day'       => $lastDay,
                'deload'       => $deload,
                'steps_target' => $steps,
                'minutes'      => $minutes,
                'strength'     => self::strengthSessions($chapter, $tier, $deload),
                'weight_target'=> $weightAt,
                'rate_pct'     => round($rate, 2),
                'norm_days'    => self::NORM_DAYS,
                'norm_checkins'=> self::NORM_CHECKINS,
            ];
        }

        $chapters = [];
        for ($c = 1; $c <= self::CHAPTERS; $c++) {
            $inChapter = array_values(array_filter($weeks, static fn($x) => $x['chapter'] === $c));
            $last      = end($inChapter) ?: null;

            $chapters[$c] = [
                'n'             => $c,
                'theme'         => self::THEMES[$c],
                'from_day'      => ($c - 1) * self::CHAPTER_DAYS + 1,
                'to_day'        => $c * self::CHAPTER_DAYS,
                'weight_target' => $last['weight_target'] ?? $weight,
                'steps_target'  => $last['steps_target'] ?? $steps,
                'minutes'       => $last['minutes'] ?? 0,
            ];
        }

        $final = $chapters[self::CHAPTERS]['weight_target'];

        return [
            'meta' => [
                'goal_dir'      => $goal,
                'tier'          => $tier,
                'weight_start'  => $weight,
                'weight_final'  => $final,
                'change_kg'     => round($final - $weight, 1),
                'change_pct'    => $weight > 0 ? round(($final - $weight) / $weight * 100, 1) : 0.0,
                'safe_floor_kg' => $weightTarget,
                'steps_start'   => $baseSteps,
                'steps_final'   => $chapters[self::CHAPTERS]['steps_target'],
                'minutes_cap'   => $minutesCap,
                'days'          => self::DAYS,
            ],
            'chapters' => $chapters,
            'weeks'    => $weeks,
        ];
    }

    /** Куда вообще допустимо прийти по весу. */
    public static function targetWeight(string $goal, float $weight, int $height, mixed $wanted): float
    {
        if ($goal === 'gain') {
            return $weight;   // для набора нижняя граница не нужна
        }

        $floorByBmi = $height > 0
            ? round(self::MIN_TARGET_BMI * ($height / 100) ** 2, 1)
            : $weight * 0.8;

        // Если человек хочет опуститься ниже безопасного порога — не спорим
        // с ним словами, просто не строим такой план.
        $wantedKg = is_numeric($wanted) ? (float) $wanted : $weight * 0.85;

        return round(max($floorByBmi, $wantedKg), 1);
    }

    public static function chapterOfDay(int $day): int
    {
        return min(self::CHAPTERS, max(1, (int) ceil($day / self::CHAPTER_DAYS)));
    }

    private static function rateFor(string $goal, int $chapter): float
    {
        $table = $goal === 'gain' ? self::RATE_GAIN : self::RATE_LOSE;
        $rate  = $table[$chapter] ?? 0.0;

        return min($rate, self::MAX_LOSS_RATE);
    }

    /**
     * Шаги и минуты на неделю.
     *
     * @return array{0: int, 1: int}
     */
    private static function progress(
        int $chapter, int $weekInChapter, string $tier, bool $deload,
        int $steps, int $minutes, int $baseSteps, int $minutesCap, int $stepsCap
    ): array {
        // Глава 1: плавный выход на baseline + 25%, без тренировок для T0/T1.
        if ($chapter === 1) {
            $goalSteps = max(4000, (int) round(max($baseSteps, 2000) * 1.25));
            $steps     = (int) round($baseSteps + ($goalSteps - $baseSteps) * min(1, $weekInChapter / 4));
        } elseif ($chapter >= 5) {
            // Главы «Испытание» и «Своё» — удержание достигнутого,
            // планка намеренно не растёт: в пятой мешают внешние
            // обстоятельства, в шестой человек ведёт план сам.
            $steps += 0;
        } elseif (!$deload) {
            $steps = (int) round($steps * 1.10);
        }

        $steps = min($stepsCap, max(3000, $steps));

        // Тренировки появляются с главы, зависящей от ступени.
        if ($chapter >= self::WORKOUTS_FROM[$tier]) {
            if ($minutes === 0) {
                $minutes = 40;                          // две сессии по 20 минут
            } elseif ($chapter < 5 && !$deload) {
                $minutes = (int) round($minutes * 1.10);
            }
            // Главы 5 и 6 — удержание объёма, роста нет.
        }

        return [$steps, min($minutesCap, $minutes)];
    }

    private static function strengthSessions(int $chapter, string $tier, bool $deload): int
    {
        if ($chapter < self::WORKOUTS_FROM[$tier]) {
            return 0;
        }
        if ($deload) {
            return 1;
        }
        return $chapter >= 3 ? 2 : 1;
    }
}
