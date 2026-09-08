<?php
declare(strict_types=1);

namespace App;

/**
 * Переводы. Каждый модуль приносит свои строки в lang/ru.php и lang/uz.php.
 * Ключи неймспейсятся именем модуля: 'identity.phone_taken'.
 * Отсутствие перевода не ломает страницу — возвращается ключ.
 */
final class I18n
{
    /** @var array<string, array<string, string>> */
    private array $strings = ['ru' => [], 'uz' => []];
    private array $missing = [];

    public function __construct(private string $lang = 'ru')
    {
    }

    public function load(string $lang, array $strings): void
    {
        $this->strings[$lang] = array_merge($this->strings[$lang] ?? [], $strings);
    }

    public function setLang(string $lang): void
    {
        if (isset($this->strings[$lang])) {
            $this->lang = $lang;
        }
    }

    public function lang(): string
    {
        return $this->lang;
    }

    /** t('identity.welcome', ['name' => 'Давron']) */
    public function t(string $key, array $replace = [], ?string $lang = null): string
    {
        $lang = $lang ?? $this->lang;
        $text = $this->strings[$lang][$key]
            ?? $this->strings['ru'][$key]
            ?? null;

        if ($text === null) {
            $this->missing[$lang][$key] = true;
            return $key;
        }

        foreach ($replace as $k => $v) {
            // В meta ошибок попадают и массивы (например, список полей).
            // Подставляем только то, что осмысленно превращается в строку.
            if (is_array($v) || is_object($v)) {
                continue;
            }
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return $text;
    }

    /** Все строки языка — отдаются фронтенду одним куском. */
    public function all(?string $lang = null): array
    {
        return $this->strings[$lang ?? $this->lang] ?? [];
    }

    /** Непереведённые ключи — видно в health-проверке. */
    public function missing(): array
    {
        return $this->missing;
    }

    /** Какие ключи есть в ru, но отсутствуют в uz. */
    public function untranslated(): array
    {
        return array_values(array_diff(
            array_keys($this->strings['ru'] ?? []),
            array_keys($this->strings['uz'] ?? [])
        ));
    }
}
