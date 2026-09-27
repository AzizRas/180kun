<?php
declare(strict_types=1);

namespace Modules\Wearable\Domain;

/**
 * Импорт выгрузок браслетов и телефонов.
 *
 * Почему CSV, а не прямое подключение (решение принято в срезе 5):
 * - Google Fit REST закрыт для новых приложений с 2024 года и отключается
 *   в конце 2026-го;
 * - Health Connect живёт только на Android-устройстве, у него нет
 *   серверного API — нужен свой нативный клиент, а его по Р-01 не строим
 *   до 3000 платящих;
 * - Google Health API (наследник Fitbit Web API) требует проверки
 *   приложения в Google — это задача сезона 2.
 * Выгрузку в CSV умеют Mi Fitness, Zepp (Amazfit), Samsung Health и
 * Google Takeout. Колонки у всех называются по-разному, поэтому они
 * распознаются по словарю синонимов, а не по жёсткому формату.
 */
final class CsvImport
{
    public const MAX_BYTES = 2_000_000;
    public const MAX_DAYS  = 90;

    private const COLUMNS = [
        'date'       => ['date', 'day', 'дата', 'день', 'sana', 'kun', 'time', 'timestamp', 'starttime', 'datetime', 'startdate'],
        'steps'      => ['steps', 'step', 'stepcount', 'totalsteps', 'шаги', 'шагов', 'qadam', 'qadamlar'],
        'active_min' => ['activeminutes', 'moveminutes', 'moveminutescount', 'activemin', 'exerciseminutes', 'активныеминуты', 'faoldaqiqalar'],
        'rhr'        => ['restingheartrate', 'restinghr', 'rhr', 'restingheartratebpm', 'пульспокоя', 'чссвпокое', 'чсспокоя', 'tinchpuls'],
        'sleep'      => ['sleep', 'sleepminutes', 'minutesasleep', 'totalsleep', 'sleepmin', 'sleepduration', 'sleeptime', 'сон', 'uyqu', 'sleephours', 'сончасы'],
    ];

    /**
     * @return array{days: array<string, array{steps: ?int, active_min: ?int, rhr: ?int, sleep_min: ?int}>, columns: array<string, string>, skipped: int, error: ?string}
     */
    public static function parse(string $text, ?string $today = null): array
    {
        $today = $today ?? gmdate('Y-m-d');
        $out   = ['days' => [], 'columns' => [], 'skipped' => 0, 'error' => null];

        if (strlen($text) > self::MAX_BYTES) {
            return ['error' => 'too_big'] + $out;
        }
        $text  = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;   // BOM
        $lines = preg_split('/\r\n|\n|\r/', trim($text)) ?: [];
        if (count($lines) < 2) {
            return ['error' => 'empty'] + $out;
        }

        $delimiter = self::delimiter($lines[0]);
        $header    = str_getcsv($lines[0], $delimiter, '"', '\\');
        $map       = self::mapColumns($header);
        if (!isset($map['date']) || count($map) < 2) {
            return ['error' => 'no_columns'] + $out;
        }
        $out['columns'] = array_map(static fn($i) => (string) $header[$i], $map);

        $sleepInHours = isset($map['sleep']) && preg_match('/hour|час|soat/iu', (string) $header[$map['sleep']]);
        $oldest       = gmdate('Y-m-d', strtotime($today . ' 00:00:00 UTC') - self::MAX_DAYS * 86400);
        $acc          = [];

        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            if (trim($lines[$i]) === '') {
                continue;
            }
            $row  = str_getcsv($lines[$i], $delimiter, '"', '\\');
            $date = self::date((string) ($row[$map['date']] ?? ''));
            if ($date === null || $date > $today || $date < $oldest) {
                $out['skipped']++;
                continue;
            }

            $d = $acc[$date] ?? ['steps' => null, 'active_min' => null, 'rhr' => [], 'sleep_min' => null];

            // Несколько строк на день (почасовые выгрузки) — шаги и минуты
            // суммируются, пульс покоя берётся минимальный.
            if (isset($map['steps']) && ($v = self::number($row[$map['steps']] ?? '')) !== null) {
                $d['steps'] = (int) round(($d['steps'] ?? 0) + $v);
            }
            if (isset($map['active_min']) && ($v = self::number($row[$map['active_min']] ?? '')) !== null) {
                $d['active_min'] = (int) round(($d['active_min'] ?? 0) + $v);
            }
            if (isset($map['rhr']) && ($v = self::number($row[$map['rhr']] ?? '')) !== null && $v > 0) {
                $d['rhr'][] = (int) round($v);
            }
            if (isset($map['sleep'])) {
                $raw = (string) ($row[$map['sleep']] ?? '');
                $v   = self::duration($raw, (bool) $sleepInHours);
                if ($v !== null) {
                    $d['sleep_min'] = (int) round(($d['sleep_min'] ?? 0) + $v);
                }
            }
            $acc[$date] = $d;
        }

        ksort($acc);
        foreach ($acc as $date => $d) {
            $out['days'][$date] = [
                'steps'      => $d['steps'],
                'active_min' => $d['active_min'],
                'rhr'        => $d['rhr'] !== [] ? min($d['rhr']) : null,
                'sleep_min'  => $d['sleep_min'],
            ];
        }
        if ($out['days'] === []) {
            $out['error'] = 'no_rows';
        }
        return $out;
    }

    private static function delimiter(string $headerLine): string
    {
        $best = ',';
        $max  = 0;
        foreach ([',', ';', "\t"] as $d) {
            $c = substr_count($headerLine, $d);
            if ($c > $max) {
                $max  = $c;
                $best = $d;
            }
        }
        return $best;
    }

    /** @return array<string, int> поле => номер колонки */
    private static function mapColumns(array $header): array
    {
        $map = [];
        foreach ($header as $i => $name) {
            $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $name) ?? '');
            // «Sleep (min)» → sleepmin, «Steps» → steps.
            foreach (self::COLUMNS as $field => $synonyms) {
                if (isset($map[$field])) {
                    continue;
                }
                foreach ($synonyms as $syn) {
                    if ($key === $syn) {
                        $map[$field] = $i;
                        continue 3;
                    }
                }
            }
        }
        // Второй проход — по вхождению, для длинных названий вроде
        // «Resting heart rate (bpm) avg».
        foreach ($header as $i => $name) {
            if (in_array($i, $map, true)) {
                continue;
            }
            $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $name) ?? '');
            foreach (['rhr' => 'resting', 'steps' => 'step', 'sleep' => 'sleep', 'active_min' => 'minutes'] as $field => $needle) {
                if (!isset($map[$field]) && str_contains($key, $needle)) {
                    $map[$field] = $i;
                    break;
                }
            }
        }
        return $map;
    }

    public static function date(string $raw): ?string
    {
        $raw = trim($raw, " \t\"'");
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{10}(\d{3})?$/', $raw)) {
            $ts = (int) substr($raw, 0, 10);
            return gmdate('Y-m-d', $ts);
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $raw, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/', $raw, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $raw, $m)) {
            // 25/09/2026 — день первым; 09/25/2026 — месяц первым. 03/04 неоднозначно:
            // в Узбекистане пишут день первым, так и читаем.
            [$a, $b] = [(int) $m[1], (int) $m[2]];
            [$day, $month] = $b > 12 ? [$b, $a] : [$a, $b];
            return checkdate($month, $day, (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $month, $day) : null;
        }
        return null;
    }

    private static function number(mixed $raw): ?float
    {
        $s = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim((string) $raw, " \t\"'"));
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float) $s : null;
    }

    /** Длительность: минуты числом, часы числом (если колонка в часах) или «7:30» / «7h 30m». */
    private static function duration(string $raw, bool $hours): ?float
    {
        $raw = trim($raw, " \t\"'");
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $raw, $m)) {
            return (int) $m[1] * 60 + (int) $m[2];
        }
        if (preg_match('/^(\d{1,2})\s*[hч]\s*(\d{1,2})?\s*[mм]?/iu', $raw, $m)) {
            return (int) $m[1] * 60 + (int) ($m[2] ?? 0);
        }
        $v = self::number($raw);
        if ($v === null) {
            return null;
        }
        return ($hours || $v <= 16) ? $v * 60 : $v;
    }
}
