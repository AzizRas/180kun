<?php
declare(strict_types=1);

namespace Modules\Safety\Domain;

/**
 * Распознавание триггерных тем (Р-12).
 *
 * Сознательно широкая сеть: ложное срабатывание стоит одного письма
 * модератора, пропуск может стоить жизни. ИИ в этом сценарии не
 * участвует и ни одного слова не импровизирует — только заранее
 * написанный текст.
 *
 * Русский и узбекский (латиница, с разными вариантами апострофа).
 */
final class Triggers
{
    private const PATTERNS = [
        'self_harm' => [
            // русский
            '/суицид/u', '/самоубий/u', '/покончить\s+с\s+собой/u', '/убить\s+себя/u', '/убью\s+себя/u',
            '/не\s+хочу\s+(больше\s+)?жить/u', '/жить\s+не\s+хочется/u', '/лучше\s+(бы\s+)?(мне\s+)?умереть/u',
            '/хочу\s+умереть/u', '/режу\s+себя/u', '/порезать\s+себя/u', '/порезы\s+на\s+рук/u', '/самоповрежд/u',
            '/причин(яю|ить)\s+себе\s+боль/u', '/наложить\s+на\s+себя\s+руки/u',
            // узбекский
            '/o.?z\s*joni(ga|mga)\s+qasd/u', '/o.?zimni\s+o.?ldir/u', '/o.?zini\s+o.?ldir/u', '/yashag(im|ing)\s+kelmay/u',
            '/o.?lg(im|ing)\s+keladi/u', '/o.?limni\s+o.?ylay/u', '/suitsid/u', '/o.?zimga\s+zarar/u',
        ],
        'purging' => [
            '/вызыва\w*\s+рвот/u', '/рвот\w*\s+после\s+еды/u', '/выр(вать|вало|вал)\s+после\s+еды/u', '/два\s+пальца\s+в\s+рот/u',
            '/слабительн/u', '/мочегонн\w*\s+чтобы\s+похуд/u',
            '/qust(ir|ish)/u', '/ovqatdan\s+keyin\s+qus/u', '/surgi\s+dori/u',
        ],
        'food_refusal' => [
            '/совсем\s+не\s+ем/u', '/ничего\s+не\s+ем\s+(уже\s+)?\d*\s*(дн|недел|сут)/u', '/отказ\w*\s+от\s+(всей\s+)?еды/u',
            '/не\s+ел(а)?\s+(уже\s+)?\d+\s*(дн|сут)/u', '/голода(ю|ть)\s+(уже\s+)?\d+\s*(дн|сут)/u',
            '/umuman\s+ovqat\s+yemay/u', '/ovqat\s+yemayotganimga/u', '/och\s+qolyapman\s+\d+\s*kun/u',
        ],
    ];

    /** @return ?string категория или null */
    public static function detect(string $text): ?string
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return null;
        }
        // Узбекские апострофы бывают любыми: ʻ ’ ' ` — приводим к одному.
        $t = str_replace(['ʻ', 'ʼ', '’', '‘', '`', '´'], "'", $t);
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        foreach (self::PATTERNS as $category => $patterns) {
            foreach ($patterns as $p) {
                if (preg_match($p, $t)) {
                    return $category;
                }
            }
        }
        return null;
    }
}
