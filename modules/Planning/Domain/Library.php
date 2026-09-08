<?php
declare(strict_types=1);

namespace Modules\Planning\Domain;

/**
 * Библиотека ежедневных действий.
 *
 * Действие на день выбирается детерминированно из пула главы: при
 * одинаковом плане и дне всегда выпадает одно и то же. Так человек
 * может заранее посмотреть, что будет завтра, а мы — воспроизвести
 * любой день при разборе жалобы.
 *
 * Тексты живут в lang/, здесь только ключи и правила применимости.
 */
final class Library
{
    /**
     * Пул действий по главам.
     * tier — минимальная ступень, с которой действие уместно.
     * kind — что именно попросят подтвердить в чек-ине на срезе 3.
     */
    private const POOL = [
        1 => [   // База: шаги, сон, регулярность. Никакой еды и нагрузки.
            ['key' => 'walk_after_meal',  'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'steps_goal',       'kind' => 'number', 'tier' => 'T0'],
            ['key' => 'sleep_window',     'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'stairs',           'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'morning_water',    'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'walk_call',        'kind' => 'do',     'tier' => 'T0'],
        ],
        2 => [   // Топливо: три правила еды, без подсчёта калорий.
            ['key' => 'protein_each_meal','kind' => 'do',     'tier' => 'T0'],
            ['key' => 'no_sweet_drinks',  'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'water_goal',       'kind' => 'number', 'tier' => 'T0'],
            ['key' => 'plate_half_veg',   'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'first_workout',    'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'no_eat_after',     'kind' => 'do',     'tier' => 'T1'],
        ],
        3 => [   // Нагрузка: объём растёт, появляется силовая работа.
            ['key' => 'strength_session', 'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'steps_goal',       'kind' => 'number', 'tier' => 'T0'],
            ['key' => 'cardio_session',   'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'mobility',         'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'progress_weight',  'kind' => 'number', 'tier' => 'T2'],
            ['key' => 'sleep_window',     'kind' => 'do',     'tier' => 'T0'],
        ],
        4 => [   // Экватор: сознательно легче, много рефлексии.
            ['key' => 'weekly_review',    'kind' => 'text',   'tier' => 'T0'],
            ['key' => 'steps_goal',       'kind' => 'number', 'tier' => 'T0'],
            ['key' => 'strength_session', 'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'walk_outdoor',     'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'protein_each_meal','kind' => 'do',     'tier' => 'T0'],
            ['key' => 'rest_day',         'kind' => 'do',     'tier' => 'T0'],
        ],
        5 => [   // Испытание: удержание при помехах — пост, поездки, застолья.
            ['key' => 'hold_the_line',    'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'plan_the_event',   'kind' => 'text',   'tier' => 'T0'],
            ['key' => 'minimum_day',      'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'steps_goal',       'kind' => 'number', 'tier' => 'T0'],
            ['key' => 'sleep_window',     'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'walk_after_meal',  'kind' => 'do',     'tier' => 'T0'],
        ],
        6 => [   // Своё: человек ведёт план сам, система только наблюдает.
            ['key' => 'own_choice',       'kind' => 'text',   'tier' => 'T0'],
            ['key' => 'steps_goal',       'kind' => 'number', 'tier' => 'T0'],
            ['key' => 'strength_session', 'kind' => 'do',     'tier' => 'T0'],
            ['key' => 'teach_someone',    'kind' => 'do',     'tier' => 'T1'],
            ['key' => 'weekly_review',    'kind' => 'text',   'tier' => 'T0'],
            ['key' => 'hold_the_line',    'kind' => 'do',     'tier' => 'T0'],
        ],
    ];

    /** Действия, которые не дают человеку с ограничениями по нагрузке. */
    private const IMPACT = ['strength_session', 'cardio_session'];

    /**
     * Действие на конкретный день плана.
     *
     * @param array $week  строка недели из Calculator
     * @param array $meta  meta плана
     * @param bool  $limited есть ограничения по нагрузке (PAR-Q или травма)
     */
    public static function actionFor(int $day, array $week, array $meta, bool $limited = false): array
    {
        $chapter = Calculator::chapterOfDay($day);
        $pool    = self::POOL[$chapter] ?? self::POOL[1];
        $tierIdx = array_search((string) ($meta['tier'] ?? 'T0'), ['T0', 'T1', 'T2', 'T3'], true) ?: 0;

        $available = array_values(array_filter($pool, static function (array $a) use ($tierIdx, $limited): bool {
            $need = (int) array_search($a['tier'], ['T0', 'T1', 'T2', 'T3'], true);
            if ($need > $tierIdx) {
                return false;
            }
            return !($limited && in_array($a['key'], self::IMPACT, true));
        }));

        if ($available === []) {
            $available = [['key' => 'steps_goal', 'kind' => 'number', 'tier' => 'T0']];
        }

        // Детерминированный выбор: день внутри главы по кругу пула.
        $dayInChapter = $day - ($chapter - 1) * Calculator::CHAPTER_DAYS;
        $action       = $available[($dayInChapter - 1) % count($available)];

        return [
            'key'     => $action['key'],
            'kind'    => $action['kind'],
            'chapter' => $chapter,
            'target'  => self::targetFor($action['key'], $week),
        ];
    }

    /** Числовая цель действия, если она есть. */
    private static function targetFor(string $key, array $week): ?int
    {
        return match ($key) {
            'steps_goal'      => (int) ($week['steps_target'] ?? 0),
            'water_goal'      => 8,                              // стаканов
            'progress_weight' => null,
            default           => null,
        };
    }

    /** @return array<int, string> все ключи — для проверки полноты переводов */
    public static function allKeys(): array
    {
        $keys = [];
        foreach (self::POOL as $pool) {
            foreach ($pool as $action) {
                $keys[] = $action['key'];
            }
        }
        return array_values(array_unique($keys));
    }
}
