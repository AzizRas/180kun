<?php
declare(strict_types=1);

namespace Modules\Squad\Domain;

/**
 * Алгоритм сборки сквадов (§ 05 досье, решения Р-05 и Р-06).
 *
 * Чистая функция: на вход — кандидаты, на выход — предложенные составы.
 * Ни базы, ни ядра, ни времени: один и тот же пул всегда даёт один и тот
 * же результат. Поэтому правила можно проверять перебором сотен пулов, а
 * модератор видит воспроизводимое предложение, а не лотерею.
 *
 * Кандидат — массив:
 *   user_id, goal_dir, sex, age, lang, tier (T0..T3), steps, time_budget,
 *   bmi, window (morning|day|evening), social (1..3), experience,
 *   mixed_ok (bool), commit (1..3)
 *
 * Жёсткие правила (нарушать нельзя ни при какой цене):
 *   1. одно направление цели;
 *   2. ступени N и N+1, и ступени N+1 не больше одного человека;
 *   3. один язык;
 *   4. один пол, смешанный — только если все отметили «не важно»;
 *   5. возраст ±6 лет от медианы сквада;
 *   6. общее окно активности у двух третей;
 *   7. коллеги и родня — никогда вместе;
 *   плюс размер 5–7.
 *
 * «Ровно один якорь» из Р-06 — жёсткое правило в сторону «не больше
 * одного». Если якоря взять неоткуда (ступень T3 или в пуле нет людей на
 * ступень выше), сквад собирается без него, получает штраф в цене и
 * флаг для модератора: оставить шестерых без сквада хуже, чем показать
 * такой состав человеку на утверждение.
 */
final class Matcher
{
    public const TARGET     = 6;
    public const MIN        = 5;
    public const MAX        = 7;
    public const AGE_SPREAD = 6;

    /**
     * Порог качества: выше — сквад помечается на ручной разбор.
     * В досье значение не сохранилось; подобран по синтетическим пулам
     * (5 × 400 человек): медиана цены 4,4, 80-й перцентиль 5,4. Порог 5,5
     * отправляет на разбор примерно худшую пятую часть составов.
     * Правится по опросу 14-го дня («насколько сквад подходит, 1–5»).
     */
    public const QUALITY_LIMIT = 5.5;

    // ---------- формула ----------

    public static function tierNo(string $tier): int
    {
        return max(0, min(3, (int) substr($tier, 1)));
    }

    /** Пересечение окон: «день» частично совпадает и с утром, и с вечером. */
    public static function overlap(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        return ($a === 'day' || $b === 'day') ? 0.5 : 0.0;
    }

    /** Разный опыт срывов: один новичок рядом с тем, кто «это проходил». */
    public static function complementary(array $a, array $b): bool
    {
        return (($a['experience'] ?? '') === 'never_tried') !== (($b['experience'] ?? '') === 'never_tried');
    }

    public static function relationKey(int $a, int $b): string
    {
        return min($a, $b) . ':' . max($a, $b);
    }

    /**
     * Штраф пары: чем меньше, тем лучше им в одном скваде.
     * Коэффициенты — из досье, без изменений.
     *
     * @param array<string, true> $related
     */
    public static function fit(array $a, array $b, array $related = []): float
    {
        $f = 2.5 * abs((int) $a['steps'] - (int) $b['steps']) / 4000
           + 2.0 * abs((int) $a['time_budget'] - (int) $b['time_budget']) / 30
           + 1.5 * abs((float) $a['bmi'] - (float) $b['bmi']) / 5
           + 1.5 * (1 - self::overlap((string) $a['window'], (string) $b['window']))
           + 1.0 * abs((int) $a['age'] - (int) $b['age']) / 6
           + 1.0 * abs((int) $a['social'] - (int) $b['social']) / 2;

        if (isset($related[self::relationKey((int) $a['user_id'], (int) $b['user_id'])])) {
            $f += 3.0;
        }
        if (self::complementary($a, $b)) {
            $f -= 1.5;
        }
        return $f;
    }

    /**
     * Цена сквада целиком.
     *
     * @param array<int, array> $members
     * @param array<string, true> $related
     */
    public static function cost(array $members, array $related = []): float
    {
        $members = array_values($members);
        $n       = count($members);
        if ($n === 0) {
            return 0.0;
        }

        $sum   = 0.0;
        $pairs = 0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $sum += self::fit($members[$i], $members[$j], $related);
                $pairs++;
            }
        }
        $meanFit = $pairs > 0 ? $sum / $pairs : 0.0;

        return $meanFit
            + 4.0 * abs(self::anchors($members) - 1)
            + 3.0 * max(0, self::MIN - $n)
            + 2.0 * self::variance(array_map(static fn($m) => (int) ($m['commit'] ?? 2), $members));
    }

    /** Сколько в скваде людей на ступень выше базовой. */
    public static function anchors(array $members): int
    {
        if ($members === []) {
            return 0;
        }
        $tiers = array_map(static fn($m) => self::tierNo((string) $m['tier']), $members);
        $base  = min($tiers);
        return count(array_filter($tiers, static fn($t) => $t === $base + 1));
    }

    public static function baseTier(array $members): string
    {
        return 'T' . min(array_map(static fn($m) => self::tierNo((string) $m['tier']), $members));
    }

    // ---------- жёсткие правила ----------

    /**
     * Какие жёсткие правила нарушены. Пустой массив — состав допустим.
     * $final = false — состав ещё растёт: не проверяем нижнюю границу
     * размера и допускаем чуть больше несовпадений по окну.
     *
     * @param array<string, true> $related
     * @return array<int, string>
     */
    public static function violations(array $members, array $related = [], bool $final = true): array
    {
        $members = array_values($members);
        $n       = count($members);
        $bad     = [];
        if ($n === 0) {
            return $final ? ['size_min'] : [];
        }

        if (count(array_unique(array_column($members, 'goal_dir'))) > 1) {
            $bad[] = 'goal';
        }
        if (count(array_unique(array_column($members, 'lang'))) > 1) {
            $bad[] = 'lang';
        }

        $sexes = array_unique(array_column($members, 'sex'));
        if (count($sexes) > 1) {
            foreach ($members as $m) {
                if (empty($m['mixed_ok'])) {
                    $bad[] = 'sex';
                    break;
                }
            }
        }

        $tiers = array_map(static fn($m) => self::tierNo((string) $m['tier']), $members);
        if (max($tiers) - min($tiers) > 1) {
            $bad[] = 'tier_spread';
        } elseif (self::anchors($members) > 1) {
            $bad[] = 'anchors_many';
        }

        $ages   = array_map(static fn($m) => (int) $m['age'], $members);
        $median = self::median($ages);
        foreach ($ages as $a) {
            if (abs($a - $median) > self::AGE_SPREAD) {
                $bad[] = 'age';
                break;
            }
        }

        // Окно: общее у двух третей (4 из 6, 4 из 5, 5 из 7).
        $windows  = array_count_values(array_column($members, 'window'));
        $dominant = max($windows);
        $need     = $final ? (int) ceil(2 * $n / 3) : $n - 2;
        if ($dominant < $need) {
            $bad[] = 'window';
        }

        $ids = array_map(static fn($m) => (int) $m['user_id'], $members);
        for ($i = 0; $i < $n && !in_array('related', $bad, true); $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (isset($related[self::relationKey($ids[$i], $ids[$j])])) {
                    $bad[] = 'related';
                    break;
                }
            }
        }

        if ($n > self::MAX) {
            $bad[] = 'size_max';
        }
        if ($final && $n < self::MIN) {
            $bad[] = 'size_min';
        }

        return $bad;
    }

    /**
     * Флаги для модератора — не нарушения, а то, на что стоит посмотреть.
     *
     * @return array<int, string>
     */
    public static function flags(array $members, array $related = []): array
    {
        $flags = [];
        if (self::anchors($members) === 0) {
            $flags[] = 'no_anchor';
        }
        if (self::cost($members, $related) > self::QUALITY_LIMIT) {
            $flags[] = 'high_cost';
        }
        if (count(array_unique(array_column($members, 'sex'))) > 1) {
            $flags[] = 'mixed';
        }
        if (count($members) < self::TARGET) {
            $flags[] = 'small';
        }
        return $flags;
    }

    // ---------- сборка ----------

    /**
     * @param array<int, array> $candidates
     * @param array<string, true> $related
     * @return array{squads: array<int, array{members: array<int, int>, cost: float, base_tier: string, sex: string, flags: array}>, left: array<int, int>}
     */
    public static function match(array $candidates, array $related = []): array
    {
        // Детерминированный порядок — основа воспроизводимости.
        usort($candidates, static fn($a, $b) => (int) $a['user_id'] <=> (int) $b['user_id']);

        $squads = [];
        $rest   = [];

        // Проход 1: страты по жёстким правилам — направление, язык, пол.
        foreach (self::groupBy($candidates, static fn($c) => $c['goal_dir'] . '|' . $c['lang'] . '|' . $c['sex']) as $group) {
            [$formed, $left] = self::formInStratum($group, $related);
            array_push($squads, ...$formed);
            array_push($rest, ...$left);
        }

        // Проход 2: смешанные сквады — только из тех, кто сам отметил «не важно».
        $mixedPool = array_values(array_filter($rest, static fn($c) => !empty($c['mixed_ok'])));
        $stillLeft = array_values(array_filter($rest, static fn($c) => empty($c['mixed_ok'])));
        foreach (self::groupBy($mixedPool, static fn($c) => $c['goal_dir'] . '|' . $c['lang']) as $group) {
            [$formed, $left] = self::formInStratum($group, $related);
            array_push($squads, ...$formed);
            array_push($stillLeft, ...$left);
        }

        $out = [];
        foreach ($squads as $members) {
            $sexes = array_unique(array_column($members, 'sex'));
            $out[] = [
                'members'   => array_map(static fn($m) => (int) $m['user_id'], $members),
                'anchor'    => self::anchorId($members),
                'cost'      => round(self::cost($members, $related), 3),
                'base_tier' => self::baseTier($members),
                'sex'       => count($sexes) > 1 ? 'mixed' : (string) reset($sexes),
                'goal_dir'  => (string) $members[0]['goal_dir'],
                'lang'      => (string) $members[0]['lang'],
                'flags'     => self::flags($members, $related),
            ];
        }

        $leftIds = array_map(static fn($c) => (int) $c['user_id'], $stillLeft);
        sort($leftIds);

        return ['squads' => $out, 'left' => $leftIds];
    }

    /**
     * Сборка внутри одной страты. Базовые ступени идут снизу вверх:
     * T0 — самая массовая группа, и ей в первую очередь нужны якоря из T1.
     *
     * @return array{0: array<int, array<int, array>>, 1: array<int, array>}
     */
    private static function formInStratum(array $group, array $related): array
    {
        $buckets = [0 => [], 1 => [], 2 => [], 3 => []];
        foreach ($group as $c) {
            $buckets[self::tierNo((string) $c['tier'])][] = $c;
        }
        // Внутри ступени — по возрасту: соседние по возрасту легче проходят коридор ±6.
        foreach ($buckets as &$b) {
            usort($b, static fn($x, $y) => [(int) $x['age'], (int) $x['user_id']] <=> [(int) $y['age'], (int) $y['user_id']]);
        }
        unset($b);

        $formed   = [];
        $unplaced = [];

        for ($base = 0; $base <= 3; $base++) {
            while (true) {
                $core    = $buckets[$base];
                $anchors = $base < 3 ? $buckets[$base + 1] : [];
                $needCore = $anchors !== [] ? self::MIN - 1 : self::MIN;
                if (count($core) < $needCore) {
                    break;
                }

                $squad      = [array_shift($core)];
                $targetCore = $anchors !== [] ? self::TARGET - 1 : self::TARGET;

                while (count($squad) < $targetCore) {
                    $pick = self::bestAddition($squad, $core, $related, false);
                    if ($pick === null) {
                        break;
                    }
                    $squad[] = $core[$pick];
                    array_splice($core, $pick, 1);
                }

                $anchorPick = null;
                if ($anchors !== [] && count($squad) >= self::MIN - 1) {
                    $anchorPick = self::bestAddition($squad, $anchors, $related, false);
                    if ($anchorPick !== null) {
                        $squad[] = $anchors[$anchorPick];
                    }
                }

                if (count($squad) >= self::MIN && self::violations($squad, $related, true) === []) {
                    $formed[] = $squad;
                    $taken    = array_flip(array_map(static fn($m) => (int) $m['user_id'], $squad));
                    $buckets[$base] = array_values(array_filter($buckets[$base], static fn($m) => !isset($taken[(int) $m['user_id']])));
                    if ($base < 3) {
                        $buckets[$base + 1] = array_values(array_filter($buckets[$base + 1], static fn($m) => !isset($taken[(int) $m['user_id']])));
                    }
                } else {
                    // Первому в очереди не нашлось достаточно совместимых —
                    // откладываем его, остальные пробуют дальше.
                    $unplaced[] = array_shift($buckets[$base]);
                }
            }
        }

        $leftovers = array_merge($unplaced, ...array_values($buckets));

        // Остатки пробуем подсадить в уже собранные составы, до семи человек.
        $stillLeft = [];
        foreach ($leftovers as $person) {
            $bestSquad = null;
            $bestCost  = INF;
            foreach ($formed as $i => $squad) {
                if (count($squad) >= self::MAX) {
                    continue;
                }
                $try = array_merge($squad, [$person]);
                if (self::violations($try, $related, true) !== []) {
                    continue;
                }
                $c = self::cost($try, $related);
                if ($c < $bestCost) {
                    $bestCost  = $c;
                    $bestSquad = $i;
                }
            }
            if ($bestSquad === null) {
                $stillLeft[] = $person;
            } else {
                $formed[$bestSquad][] = $person;
            }
        }

        return [$formed, $stillLeft];
    }

    /** Индекс лучшего кандидата на добавление или null, если все нарушают правила. */
    private static function bestAddition(array $squad, array $pool, array $related, bool $final): ?int
    {
        $best     = null;
        $bestCost = INF;
        foreach ($pool as $i => $candidate) {
            $try = array_merge($squad, [$candidate]);
            if (self::violations($try, $related, $final) !== []) {
                continue;
            }
            $c = self::cost($try, $related);
            if ($c < $bestCost - 1e-9) {
                $bestCost = $c;
                $best     = $i;
            }
        }
        return $best;
    }

    private static function anchorId(array $members): ?int
    {
        $base = self::tierNo(self::baseTier($members));
        foreach ($members as $m) {
            if (self::tierNo((string) $m['tier']) === $base + 1) {
                return (int) $m['user_id'];
            }
        }
        return null;
    }

    /** @return array<string, array<int, array>> */
    private static function groupBy(array $items, callable $key): array
    {
        $groups = [];
        foreach ($items as $item) {
            $groups[$key($item)][] = $item;
        }
        ksort($groups);
        return $groups;
    }

    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n   = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    public static function variance(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $sum  = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }
        return $sum / $n;
    }
}
