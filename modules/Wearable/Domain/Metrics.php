<?php
declare(strict_types=1);

namespace Modules\Wearable\Domain;

use App\Contracts\Wearable;
use App\Kernel;
use App\Result;

/**
 * Данные браслета: запись, импорт, медианы, вес.
 *
 * Ручной ввод — полноправный источник, а не запасной: у большинства
 * участников дешёвый браслет, и три числа утром за 20 секунд надёжнее
 * любой интеграции, которая отвалится при обновлении приложения.
 */
final class Metrics implements Wearable
{
    public const FIELDS        = ['steps', 'active_min', 'rhr', 'sleep_min'];
    public const BACKFILL_DAYS = 7;

    public function __construct(private Kernel $kernel)
    {
    }

    // ---------- контракт ----------

    public function dayMetrics(int $userId, string $date): array
    {
        $row = $this->kernel->db()->first('SELECT * FROM wear_days WHERE user_id = ? AND date = ?', [$userId, $date]);
        if ($row === null) {
            return ['steps' => null, 'active_min' => null, 'rhr' => null, 'sleep_min' => null, 'source' => 'none'];
        }
        return [
            // Подозрительные шаги в расчёты не идут.
            'steps'      => (int) $row['suspect'] === 1 ? null : self::intOrNull($row['steps']),
            'active_min' => self::intOrNull($row['active_min']),
            'rhr'        => self::intOrNull($row['rhr']),
            'sleep_min'  => self::intOrNull($row['sleep_min']),
            'source'     => (string) $row['source'],
        ];
    }

    public function baseline(int $userId, int $days = 7, ?string $until = null): array
    {
        $until = $until ?? gmdate('Y-m-d');
        $from  = gmdate('Y-m-d', strtotime($until . ' 00:00:00 UTC') - ($days - 1) * 86400);
        $rows  = $this->kernel->db()->all(
            'SELECT * FROM wear_days WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date',
            [$userId, $from, $until]
        );

        $steps = $rhr = $sleep = $active = [];
        foreach ($rows as $r) {
            if ($r['steps'] !== null && (int) $r['suspect'] === 0) { $steps[] = (int) $r['steps']; }
            if ($r['rhr'] !== null)        { $rhr[]    = (int) $r['rhr']; }
            if ($r['sleep_min'] !== null)  { $sleep[]  = (int) $r['sleep_min']; }
            if ($r['active_min'] !== null) { $active[] = (int) $r['active_min']; }
        }

        return [
            'steps_med'  => $steps  ? (int) round(Plausibility::median($steps)) : null,
            'rhr'        => $rhr    ? (int) round(array_sum($rhr) / count($rhr)) : null,   // § 08: только скользящее среднее
            'sleep_avg'  => $sleep  ? (int) round(array_sum($sleep) / count($sleep)) : null,
            'active_med' => $active ? (int) round(Plausibility::median($active)) : null,
            'days'       => count($rows),
            'source'     => $rows ? 'wearable' : 'none',
        ];
    }

    public function isConnected(int $userId): bool
    {
        return $this->kernel->db()->value(
            "SELECT 1 FROM wear_days WHERE user_id = ? AND source = 'import' AND date >= ?",
            [$userId, gmdate('Y-m-d', time() - 14 * 86400)]
        ) !== null;
    }

    // ---------- запись ----------

    /** Ручной ввод за день: сегодня или неделю назад, не раньше. */
    public function record(int $userId, array $input, string $source = 'manual', ?string $today = null): Result
    {
        $today = $today ?? gmdate('Y-m-d');
        $date  = (string) ($input['date'] ?? $today);
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) {
            return Result::fail('bad_date');
        }
        if ($date > $today) {
            return Result::fail('in_future');
        }
        if ($source === 'manual' && $date < gmdate('Y-m-d', strtotime($today . ' 00:00:00 UTC') - self::BACKFILL_DAYS * 86400)) {
            return Result::fail('too_old');
        }

        $values = [];
        foreach (self::FIELDS as $f) {
            $raw = $input[$f] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            if (!is_numeric($raw)) {
                return Result::fail('not_a_number', ['field' => $f]);
            }
            $v = (int) round((float) $raw);
            if (Plausibility::outOfRange($f, $v)) {
                return Result::fail('out_of_range', ['field' => $f]);
            }
            $values[$f] = $v;
        }
        if ($values === []) {
            return Result::fail('nothing_to_save');
        }

        $this->store($userId, $date, $values, $source);
        $row = $this->kernel->db()->first('SELECT * FROM wear_days WHERE user_id = ? AND date = ?', [$userId, $date]);

        $this->kernel->events->emit('wearable.day_recorded', [
            'user_id' => $userId, 'date' => $date, 'source' => $source,
            'suspect' => (int) $row['suspect'] === 1,
        ]);

        return Result::ok(self::view($row));
    }

    /** Импорт выгрузки целиком. Существующие ручные значения не затираются пустыми. */
    public function import(int $userId, string $csv, ?string $today = null): Result
    {
        $parsed = CsvImport::parse($csv, $today);
        if ($parsed['error'] !== null) {
            return Result::fail('import_' . $parsed['error']);
        }

        $saved = 0;
        foreach ($parsed['days'] as $date => $values) {
            $clean = [];
            foreach ($values as $f => $v) {
                if ($v !== null && !Plausibility::outOfRange($f, $v)) {
                    $clean[$f] = $v;
                }
            }
            if ($clean !== []) {
                $this->store($userId, $date, $clean, 'import');
                $saved++;
            }
        }

        if ($saved > 0) {
            $this->kernel->events->emit('wearable.day_recorded', [
                'user_id' => $userId, 'date' => array_key_last($parsed['days']), 'source' => 'import', 'imported' => $saved,
            ]);
        }

        return Result::ok([
            'days'     => $saved,
            'skipped'  => $parsed['skipped'],
            'columns'  => $parsed['columns'],
            'suspect'  => (int) $this->kernel->db()->value('SELECT COUNT(*) FROM wear_days WHERE user_id = ? AND suspect = 1', [$userId], 0),
        ]);
    }

    private function store(int $userId, string $date, array $values, string $source): void
    {
        $existing = $this->kernel->db()->first('SELECT * FROM wear_days WHERE user_id = ? AND date = ?', [$userId, $date]);
        $merged   = [];
        foreach (self::FIELDS as $f) {
            $merged[$f] = array_key_exists($f, $values) ? $values[$f] : ($existing[$f] ?? null);
        }

        // Проверка шагов против собственной истории за 30 дней до этой даты.
        $history = $this->kernel->db()->all(
            'SELECT steps, active_min FROM wear_days
             WHERE user_id = ? AND date < ? AND date >= ? AND suspect = 0 AND steps IS NOT NULL',
            [$userId, $date, gmdate('Y-m-d', strtotime($date . ' 00:00:00 UTC') - 30 * 86400)]
        );
        $reason = Plausibility::suspectSteps(
            $merged['steps'] !== null ? (int) $merged['steps'] : null,
            $merged['active_min'] !== null ? (int) $merged['active_min'] : null,
            array_map(static fn($r) => (int) $r['steps'], $history),
            array_values(array_filter(array_map(static fn($r) => $r['active_min'] !== null ? (int) $r['active_min'] : null, $history), static fn($v) => $v !== null))
        );

        $row = $merged + [
            'source'         => $source,
            'suspect'        => $reason !== null ? 1 : 0,
            'suspect_reason' => $reason,
            'updated_at'     => gmdate('c'),
        ];

        if ($existing === null) {
            $this->kernel->db()->insert('wear_days', $row + ['user_id' => $userId, 'date' => $date]);
        } else {
            $this->kernel->db()->update('wear_days', $row, 'user_id = :u AND date = :d', ['u' => $userId, 'd' => $date]);
        }
    }

    /** @return array<int, array> последние N дней, новые сверху */
    public function recent(int $userId, int $days = 14, ?string $until = null): array
    {
        $until = $until ?? gmdate('Y-m-d');
        $rows  = $this->kernel->db()->all(
            'SELECT * FROM wear_days WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date DESC',
            [$userId, gmdate('Y-m-d', strtotime($until . ' 00:00:00 UTC') - ($days - 1) * 86400), $until]
        );
        return array_map([self::class, 'view'], $rows);
    }

    // ---------- вес ----------

    public function recordWeight(int $userId, mixed $weight, mixed $waist = null, ?string $today = null): Result
    {
        $today = $today ?? gmdate('Y-m-d');
        if (!is_numeric($weight) || (float) $weight < 35 || (float) $weight > 300) {
            return Result::fail('bad_weight');
        }
        $waistVal = null;
        if ($waist !== null && $waist !== '') {
            if (!is_numeric($waist) || (float) $waist < 40 || (float) $waist > 250) {
                return Result::fail('bad_waist');
            }
            $waistVal = round((float) $waist, 1);
        }

        $this->kernel->db()->run(
            'INSERT OR REPLACE INTO wear_weights (user_id, date, weight_kg, waist_cm, created_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $today, round((float) $weight, 1), $waistVal, gmdate('c')]
        );
        $this->kernel->events->emit('wearable.weight_recorded', ['user_id' => $userId, 'date' => $today, 'weight_kg' => round((float) $weight, 1)]);

        return Result::ok(['trend' => $this->weightTrend($userId)]);
    }

    /**
     * Тренд веса по неделям: последний замер в каждой неделе. Дневные
     * значения наружу не отдаём — они шумят на ±1 кг от воды (§ 08).
     *
     * @return array<int, array{week_end: string, weight_kg: float}>
     */
    public function weightTrend(int $userId, int $weeks = 8): array
    {
        $rows = $this->kernel->db()->all(
            'SELECT date, weight_kg FROM wear_weights WHERE user_id = ? ORDER BY date DESC LIMIT 60',
            [$userId]
        );
        $byWeek = [];
        foreach ($rows as $r) {
            $wk = gmdate('o-W', strtotime($r['date'] . ' 00:00:00 UTC'));
            if (!isset($byWeek[$wk])) {
                $byWeek[$wk] = ['week_end' => $r['date'], 'weight_kg' => (float) $r['weight_kg']];
            }
            if (count($byWeek) >= $weeks) {
                break;
            }
        }
        return array_values(array_reverse($byWeek));
    }

    public static function view(array $row): array
    {
        return [
            'date'       => $row['date'],
            'steps'      => self::intOrNull($row['steps']),
            'active_min' => self::intOrNull($row['active_min']),
            'rhr'        => self::intOrNull($row['rhr']),
            'sleep_min'  => self::intOrNull($row['sleep_min']),
            'source'     => $row['source'],
            'suspect'    => (int) $row['suspect'] === 1,
        ];
    }

    private static function intOrNull(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
