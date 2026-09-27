<?php
declare(strict_types=1);

namespace Modules\Coach\Domain;

/**
 * Флаги нагрузки (Р-14). Решает код; облегчение применяется без спроса
 * (Р-13: легче — автоматически, тяжелее — только с согласия).
 *
 * ЖЁЛТЫЙ → нагрузка × 0,7 на 3 дня, если:
 *   - ЧСС покоя выше своего baseline на 5+ два дня подряд;
 *   - энергия ≤ 2 три чек-ина подряд;
 *   - сон меньше 6 часов две ночи подряд;
 *   - отмечена болезнь;
 *   - вес уходит быстрее 1% в неделю две недели подряд.
 * КРАСНЫЙ → × 0,5 на 3 дня и совет показаться врачу, если держится:
 *   - ЧСС покоя выше baseline на 10+ два дня подряд.
 *
 * В тексте досье строка про сон повреждена («сон baseline + 10 уд/мин»).
 * Прочитана как два правила: сон < 6 ч — жёлтый (§ 08: «меньше 6 часов —
 * модификатор нагрузки»), пульс +10 — красный. Решение записано в STATE.md.
 */
final class LoadRules
{
    public const YELLOW = 0.7;
    public const RED    = 0.5;
    public const DAYS   = 3;
    public const SHORT_SLEEP = 360;

    /**
     * @param array{days: array, rhr_base?: ?int, event_type?: ?string, weights?: array} $w
     * @return array{level: string, factor: float, days: int, reasons: array<int, string>}
     */
    public static function evaluate(array $w): array
    {
        $days    = array_values($w['days'] ?? []);
        $base    = $w['rhr_base'] ?? null;
        $reasons = [];

        if ($base !== null && Classifier::lastTwo($days, 'rhr', static fn($v) => $v >= $base + 10)) {
            return ['level' => 'red', 'factor' => self::RED, 'days' => self::DAYS, 'reasons' => ['rhr_up_10']];
        }

        foreach (Classifier::overloadSigns($days, $base) as $r) {
            $reasons[] = $r;
        }
        if (Classifier::lastTwo($days, 'sleep_min', static fn($v) => $v < self::SHORT_SLEEP)) {
            $reasons[] = 'short_sleep';
        }
        if (($w['event_type'] ?? null) === 'illness') {
            $reasons[] = 'illness';
        }
        if (self::fastLoss((array) ($w['weights'] ?? []))) {
            $reasons[] = 'fast_loss';
        }

        return $reasons === []
            ? ['level' => 'none', 'factor' => 1.0, 'days' => 0, 'reasons' => []]
            : ['level' => 'yellow', 'factor' => self::YELLOW, 'days' => self::DAYS, 'reasons' => array_values(array_unique($reasons))];
    }

    /** Потеря больше 1% в неделю две недели подряд (по недельному тренду). */
    public static function fastLoss(array $weights): bool
    {
        $w = array_values(array_map(static fn($x) => (float) $x['weight_kg'], $weights));
        $n = count($w);
        if ($n < 3) {
            return false;
        }
        $drop1 = ($w[$n - 3] - $w[$n - 2]) / $w[$n - 3];
        $drop2 = ($w[$n - 2] - $w[$n - 1]) / $w[$n - 2];
        return $drop1 > 0.01 && $drop2 > 0.01;
    }
}
