<?php
declare(strict_types=1);

namespace Modules\Onboarding\Domain;

/**
 * Скрининг допуска. Решение Р-12: красные линии проверяются кодом,
 * а не формулировкой в промпте — здесь нет ни одной строки, которую
 * можно было бы обойти уговорами.
 *
 * Два независимых слоя:
 *   1. Жёсткий отказ (reject) — программа не выдаётся вообще.
 *   2. Направление к врачу (needs_doctor) — программа выдаётся,
 *      но интенсивность ограничена и человек предупреждён.
 *
 * Модуль не ставит диагнозов и не объясняет причину медицинскими
 * терминами: он только сообщает, что эта программа человеку не подходит.
 */
final class Screening
{
    /** Вопросы PAR-Q. Ответ «да» на любой — направление к врачу. */
    public const PARQ = [
        'heart',        // говорил ли врач о проблемах с сердцем
        'chest_pain',   // боль в груди при нагрузке или в покое
        'dizziness',    // потеря равновесия из-за головокружения
        'joints',       // проблемы с суставами, которые ухудшатся от нагрузки
        'bp_meds',      // принимает препараты от давления или сердца
        'other_reason', // иная причина не увеличивать нагрузку
    ];

    /** Вопросы жёсткого отказа. */
    public const BLOCKERS = [
        'pregnant',        // беременность или кормление
        'eating_disorder', // диагностированное расстройство пищевого поведения
    ];

    public const MIN_AGE = 18;
    public const MIN_BMI = 18.5;
    public const MAX_BMI = 40.0;

    /**
     * @param array $profile goal_dir, birth_year, height_cm, weight_kg
     * @param array $answers ключ => 0/1
     *
     * @return array{
     *   ok: bool, reject: ?string, needs_doctor: bool,
     *   bmi: float, age: int, answers: array<string, int>
     * }
     */
    public static function evaluate(array $profile, array $answers): array
    {
        $clean = [];
        foreach (array_merge(self::PARQ, self::BLOCKERS) as $key) {
            $clean[$key] = !empty($answers[$key]) ? 1 : 0;
        }

        $age = (int) gmdate('Y') - (int) ($profile['birth_year'] ?? 0);
        $bmi = self::bmi((float) ($profile['weight_kg'] ?? 0), (int) ($profile['height_cm'] ?? 0));

        $reject = null;

        // Порядок проверок = порядок приоритета сообщения пользователю.
        if ($age < self::MIN_AGE) {
            $reject = 'under_18';
        } elseif ($clean['pregnant'] === 1) {
            $reject = 'pregnancy';
        } elseif ($clean['eating_disorder'] === 1) {
            $reject = 'eating_disorder';
        } elseif ($bmi > 0 && $bmi < self::MIN_BMI) {
            $reject = 'bmi_too_low';
        } elseif ($bmi > self::MAX_BMI) {
            // Не «вам нельзя», а «эта программа не подходит»: при таком ИМТ
            // нужна работа со специалистом, а не приложение с челленджем.
            $reject = 'bmi_too_high';
        } elseif (($profile['goal_dir'] ?? '') === 'lose' && $bmi > 0 && $bmi < 22.0) {
            // Снижение веса при нормальном ИМТ — отказываем в направлении,
            // но предлагаем набор массы или удержание.
            $reject = 'no_need_to_lose';
        }

        $needsDoctor = false;
        foreach (self::PARQ as $key) {
            if ($clean[$key] === 1) {
                $needsDoctor = true;
                break;
            }
        }

        return [
            'ok'           => $reject === null,
            'reject'       => $reject,
            'needs_doctor' => $needsDoctor,
            'bmi'          => $bmi,
            'age'          => $age,
            'answers'      => $clean,
        ];
    }

    public static function bmi(float $weightKg, int $heightCm): float
    {
        if ($heightCm <= 0 || $weightKg <= 0) {
            return 0.0;
        }
        $m = $heightCm / 100;
        return round($weightKg / ($m * $m), 1);
    }
}
