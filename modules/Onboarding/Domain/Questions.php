<?php
declare(strict_types=1);

namespace Modules\Onboarding\Domain;

/**
 * Восемь вопросов онбординга (решение Р-03: одна цель, минимум ввода).
 *
 * Каждый вопрос — одно нажатие или одно число. Порядок важен: сначала
 * то, что нельзя посчитать, потом то, что влияет на подбор сквада.
 * Формулировки живут в lang/, здесь только структура и правила проверки.
 */
final class Questions
{
    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return [
            [
                'key'     => 'goal_dir',
                'type'    => 'choice',
                'options' => ['lose', 'gain'],
                'required'=> true,
            ],
            [
                'key'     => 'sex',
                'type'    => 'choice',
                'options' => ['male', 'female'],
                'required'=> true,
            ],
            [
                'key'     => 'birth_year',
                'type'    => 'number',
                'min'     => 1940,
                'max'     => (int) gmdate('Y'),
                'required'=> true,
            ],
            [
                'key'     => 'body',            // рост и вес одним экраном
                'type'    => 'group',
                'fields'  => [
                    ['key' => 'height_cm', 'type' => 'number', 'min' => 120, 'max' => 230],
                    ['key' => 'weight_kg', 'type' => 'number', 'min' => 35,  'max' => 300, 'step' => 0.1],
                ],
                'required'=> true,
            ],
            [
                'key'     => 'time_budget',
                'type'    => 'choice',
                'options' => [15, 30, 45, 60],   // минут в день
                'required'=> true,
            ],
            [
                'key'     => 'window',
                'type'    => 'choice',
                'options' => ['morning', 'day', 'evening'],
                'required'=> true,
            ],
            [
                'key'     => 'social',
                'type'    => 'choice',
                'options' => [1, 2, 3],          // насколько активно хочет общаться
                'required'=> true,
            ],
            [
                'key'     => 'experience',
                'type'    => 'choice',
                'options' => ['never_tried', 'lost_motivation', 'no_time', 'no_system', 'got_result_regained'],
                'required'=> true,
            ],
        ];
    }

    /**
     * Проверка ответов. Возвращает [очищенные значения, ошибки по ключам].
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $clean  = [];
        $errors = [];

        foreach (self::all() as $q) {
            if ($q['type'] === 'group') {
                foreach ($q['fields'] as $f) {
                    [$value, $error] = self::validateField($f, $input[$f['key']] ?? null);
                    if ($error !== null) {
                        $errors[$f['key']] = $error;
                    } else {
                        $clean[$f['key']] = $value;
                    }
                }
                continue;
            }

            [$value, $error] = self::validateField($q, $input[$q['key']] ?? null);
            if ($error !== null) {
                $errors[$q['key']] = $error;
            } else {
                $clean[$q['key']] = $value;
            }
        }

        // Необязательные поля свободной формы.
        $clean['constraints'] = mb_substr(trim((string) ($input['constraints'] ?? '')), 0, 200);
        $clean['fasting']     = !empty($input['fasting']) ? 1 : 0;

        $target = $input['target_kg'] ?? null;
        if (is_numeric($target)) {
            $clean['target_kg'] = round((float) $target, 1);
        }

        return [$clean, $errors];
    }

    /** @return array{0: mixed, 1: ?string} */
    private static function validateField(array $q, mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [null, 'required'];
        }

        if ($q['type'] === 'choice') {
            // Сравнение нестрогое по типу: из JSON числа могут прийти строками.
            foreach ($q['options'] as $option) {
                if ((string) $option === (string) $raw) {
                    return [is_int($option) ? (int) $raw : (string) $raw, null];
                }
            }
            return [null, 'not_allowed'];
        }

        if ($q['type'] === 'number') {
            if (!is_numeric($raw)) {
                return [null, 'not_a_number'];
            }
            $value = isset($q['step']) ? round((float) $raw, 1) : (int) $raw;
            if ($value < $q['min'] || $value > $q['max']) {
                return [null, 'out_of_range'];
            }
            return [$value, null];
        }

        return [(string) $raw, null];
    }
}
