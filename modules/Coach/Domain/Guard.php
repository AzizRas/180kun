<?php
declare(strict_types=1);

namespace Modules\Coach\Domain;

/**
 * Красные линии (Р-12) и правила текста (§ 07). Проверяются КОДОМ,
 * до показа человеку. Ответ модели, нарушивший хоть одно правило, не
 * показывается — вместо него уходит шаблон.
 *
 * Правила:
 *  - никаких лекарств, БАД, дозировок, диагнозов, «лечения», анализов;
 *  - никакого голодания, «исключить навсегда», калорий (их считает код);
 *  - никакого стыда: «провалил», «сорвался», «опять», «должен был»;
 *  - никаких сравнений с другими участниками и оценок тела;
 *  - никаких банальностей из чёрного списка;
 *  - одно действие на сообщение, а не список советов;
 *  - минимум два числа ИЗ ДАННЫХ человека — иначе это общий совет;
 *  - кризисные темы модель не трогает вообще.
 */
final class Guard
{
    public const MAX_LEN = ['checkin' => 600, 'week' => 1500];

    private const FORBIDDEN = [
        'medical' => [
            '/таблет/u', '/препарат/u', '/дозиров/u', '/\bмг\b/u', '/\bбад\b/u', '/витамин/u', '/добавк\w*\s+к\s+пище/u',
            '/диагноз/u', '/лечени/u', '/лечит/u', '/вылеч/u', '/анализ\w*\s+(крови|мочи|на\s+гормон)/u', '/гормон/u',
            '/\bdori\b/u', '/tabletka/u', '/tashxis/u', '/davola/u', '/qon\s+tahlil/u', '/vitamin/u',
        ],
        'fasting' => [
            '/голодан/u', '/голодать/u', '/голодайте/u', '/детокс/u', '/разгрузочн\w*\s+д(ень|ни)/u', '/очищени\w*\s+организм/u',
            '/не\s+ешь(те)?\s+(целый\s+)?(день|сутки)/u', '/och\s+qol/u', '/ochlik/u',
        ],
        'forever' => [
            '/навсегда/u', '/никогда\s+больше\s+не\s+ешь/u', '/butunlay\s+voz\s+kech/u', '/abadiy/u',
        ],
        'calories' => ['/ккал/u', '/калори/u', '/kkal/u', '/kaloriya/u'],
        'shame' => [
            '/провал/u', '/сорвал/u', '/сорвёшь/u', '/\bопять\b/u', '/должен\s+был/u', '/должна\s+была/u', '/должны\s+были/u',
            '/лентя/u', '/стыд/u', '/позор/u', '/слабак/u', '/сила\s+воли/u',
            '/dangasa/u', '/uyat/u', '/sharmand/u', '/muvaffaqiyatsiz/u', '/irodasiz/u',
        ],
        'comparison' => [
            '/други\w*\s+участник/u', '/остальны\w*\s+участник/u', '/как\s+у\s+других/u', '/лучше\s+других/u', '/хуже\s+других/u',
            '/отста(ёшь|ете|ешь)\s+от/u', '/boshqa\s+ishtirokchi/u', '/boshqalardan/u',
        ],
        'body' => ['/жирн/u', '/\bжир(ок|ы|а|у|ом)?\b/u', '/толст/u', '/некрасив/u', '/semiz/u', '/xunuk/u'],
        'banality' => [
            '/главное\s*[—-]?\s*постоянств/u', '/ты\s+справишься/u', '/вы\s+справитесь/u', '/маленькие\s+шаги\s+ведут/u',
            '/верь(те)?\s+в\s+себя/u', '/всё\s+получится/u', '/все\s+получится/u', '/не\s+сдавайся/u', '/не\s+сдавайтесь/u',
            '/у\s+тебя\s+всё\s+получится/u', '/путь\s+в\s+тысячу\s+ли/u', '/никогда\s+не\s+поздно/u',
            '/o.?zingizga\s+ishon/u', '/o.?zingga\s+ishon/u', '/hammasi\s+yaxshi\s+bo.?ladi/u', '/taslim\s+bo.?lma/u', '/uddalaysiz/u',
        ],
        'crisis' => ['/суицид/u', '/самоубий/u', '/самоповрежд/u', '/рвот/u', '/слабительн/u', '/suitsid/u'],
    ];

    /**
     * @param array<string, int|float|string> $facts числа из данных человека
     * @return array<int, string> коды нарушений; пусто — можно показывать
     */
    public static function violations(string $text, array $facts, string $kind = 'checkin'): array
    {
        $bad = [];
        $t   = self::normalize($text);

        if ($t === '') {
            return ['empty'];
        }
        if (mb_strlen($text) > (self::MAX_LEN[$kind] ?? 600)) {
            $bad[] = 'too_long';
        }

        foreach (self::FORBIDDEN as $code => $patterns) {
            foreach ($patterns as $p) {
                // (*UCP): без него \b не видит границ кириллических слов.
                if (preg_match('/(*UCP)' . substr($p, 1), $t)) {
                    $bad[] = $code;
                    break;
                }
            }
        }

        // Одно сообщение — одно действие: список из трёх и более пунктов — нет.
        if (preg_match_all('/^\s*(?:[-•*·–]|\d+[.)])\s+/mu', $text) >= 3) {
            $bad[] = 'many_actions';
        }

        if (self::userNumbers($text, $facts) < 2) {
            $bad[] = 'two_numbers';
        }

        return array_values(array_unique($bad));
    }

    /** Сколько разных чисел из данных человека встречается в тексте. */
    public static function userNumbers(string $text, array $facts): int
    {
        $inText = self::numbers($text);
        if ($inText === []) {
            return 0;
        }
        $allowed = [];
        foreach ($facts as $v) {
            if (!is_numeric($v)) {
                continue;
            }
            $f = (float) $v;
            $allowed[self::key($f)] = true;
            $allowed[self::key(round($f))] = true;
            $allowed[self::key(round($f, 1))] = true;
        }

        $hits = [];
        foreach ($inText as $n) {
            if (isset($allowed[self::key($n)])) {
                $hits[self::key($n)] = true;
            }
        }
        return count($hits);
    }

    /** @return array<int, float> числа из текста: «9 200» и «9 200» — одно число, «7,5» — дробь */
    public static function numbers(string $text): array
    {
        $t = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text);
        // Разделители тысяч: «9 200», «12 000».
        $t = preg_replace('/(?<=\d) (?=\d{3}\b)/u', '', $t) ?? $t;
        preg_match_all('/\d+(?:[.,]\d+)?/u', $t, $m);
        return array_map(static fn($s) => (float) str_replace(',', '.', $s), $m[0]);
    }

    private static function key(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private static function normalize(string $text): string
    {
        $t = mb_strtolower(trim($text));
        return str_replace(['ʻ', 'ʼ', '’', '‘', '`'], "'", $t);
    }
}
