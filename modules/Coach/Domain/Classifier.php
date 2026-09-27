<?php
declare(strict_types=1);

namespace Modules\Coach\Domain;

/**
 * Классификатор спада (§ 07) на скользящем окне 14 дней.
 *
 * Решение (принято автономно): состояние определяет КОД, а не модель.
 * В досье классификация отнесена к задачам ИИ, но от состояния зависят
 * действия с нагрузкой, а их по Р-11 решает только детерминированный код.
 * Модель получает готовое состояние и подбирает к нему слова.
 *
 *   steady   — всё идёт;
 *   obstacle — помеха: отмечено событие (тўй, болезнь, поездка);
 *   overload — перегруз: энергия ≤ 2 три чек-ина подряд, пульс покоя
 *              выше своего baseline на 5+ два дня, или выполнение падает
 *              при сохранённых чек-инах и низкой энергии;
 *   drift    — потеря смысла: чек-ины есть, действий нет, настроение ровное;
 *   quit     — отказ: 7 дней ни чек-инов, ни данных браслета.
 */
final class Classifier
{
    /**
     * @param array{days: array<int, array>, event_active?: bool, rhr_base?: ?int} $w
     * @return array{state: string, reasons: array<int, string>}
     */
    public static function classify(array $w): array
    {
        $days  = array_values($w['days'] ?? []);
        $last7 = array_slice($days, -7);
        $prev7 = array_slice($days, 0, max(0, count($days) - 7));

        $hasData = static fn(array $d): bool => !empty($d['checked']) || ($d['steps'] ?? null) !== null || ($d['sleep_min'] ?? null) !== null;
        if (count($last7) >= 7 && count(array_filter($last7, $hasData)) === 0) {
            return ['state' => 'quit', 'reasons' => ['no_data_7_days']];
        }

        if (!empty($w['event_active'])) {
            return ['state' => 'obstacle', 'reasons' => ['event']];
        }

        $reasons = self::overloadSigns($days, $w['rhr_base'] ?? null);
        $checks7 = array_values(array_filter($last7, static fn($d) => !empty($d['checked'])));
        $done7   = count(array_filter($checks7, static fn($d) => in_array($d['done'] ?? null, ['yes', 'partial'], true)));
        $donePrev = count(array_filter($prev7, static fn($d) => in_array($d['done'] ?? null, ['yes', 'partial'], true)));
        $energy7 = self::avg(array_column($checks7, 'energy'));

        if (count($checks7) >= 4 && $donePrev >= 4 && $done7 < $donePrev * 0.6 && $energy7 !== null && $energy7 <= 2.5) {
            $reasons[] = 'declining_with_low_energy';
        }
        if ($reasons !== []) {
            return ['state' => 'overload', 'reasons' => $reasons];
        }

        $mood7 = self::avg(array_column($checks7, 'mood'));
        if (count($checks7) >= 4 && $done7 <= 1 && ($mood7 === null || $mood7 >= 3)) {
            return ['state' => 'drift', 'reasons' => ['checkins_without_actions']];
        }

        return ['state' => 'steady', 'reasons' => []];
    }

    /** @return array<int, string> */
    public static function overloadSigns(array $days, ?int $rhrBase): array
    {
        $reasons = [];

        $checked = array_values(array_filter($days, static fn($d) => !empty($d['checked'])));
        $last3   = array_slice($checked, -3);
        if (count($last3) === 3 && count(array_filter($last3, static fn($d) => ($d['energy'] ?? 5) !== null && (int) ($d['energy'] ?? 5) <= 2)) === 3) {
            $reasons[] = 'low_energy_3';
        }

        if ($rhrBase !== null && self::lastTwo($days, 'rhr', static fn($v) => $v >= $rhrBase + 5)) {
            $reasons[] = 'rhr_up';
        }
        return $reasons;
    }

    /** Два последних дня с данными по полю — оба удовлетворяют условию. */
    public static function lastTwo(array $days, string $field, callable $cond): bool
    {
        $with = array_values(array_filter($days, static fn($d) => ($d[$field] ?? null) !== null));
        $two  = array_slice($with, -2);
        if (count($two) < 2) {
            return false;
        }
        // Дни должны идти подряд, иначе это не тренд.
        $a = strtotime($two[0]['date'] . ' 00:00:00 UTC');
        $b = strtotime($two[1]['date'] . ' 00:00:00 UTC');
        if ($b - $a !== 86400) {
            return false;
        }
        return $cond((int) $two[0][$field]) && $cond((int) $two[1][$field]);
    }

    public static function avg(array $values): ?float
    {
        $values = array_values(array_filter($values, static fn($v) => $v !== null));
        return $values ? array_sum($values) / count($values) : null;
    }
}
