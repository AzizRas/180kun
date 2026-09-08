<?php
declare(strict_types=1);

namespace Modules\Telegram\Domain;

/**
 * Проверка подписи initData из Telegram Mini App.
 *
 * Алгоритм Telegram:
 *   secret_key       = HMAC_SHA256(ключ="WebAppData", данные=bot_token)
 *   data_check_string = пары key=value без hash, отсортированные по ключу,
 *                       склеенные через \n
 *   ожидаемый hash    = hex(HMAC_SHA256(ключ=secret_key, данные=data_check_string))
 *
 * Без этой проверки любой мог бы прислать чужой telegram id и войти
 * под чужим аккаунтом, поэтому здесь нет ни одного «упрощения».
 */
final class InitData
{
    /** Максимальный возраст подписи. Защита от повторной отправки. */
    private const MAX_AGE = 86400;

    public function __construct(private string $botToken)
    {
    }

    /**
     * @return array{ok: bool, user?: array, error?: string, auth_date?: int}
     */
    public function verify(string $initData): array
    {
        if ($this->botToken === '') {
            return ['ok' => false, 'error' => 'bot_not_configured'];
        }
        if (trim($initData) === '') {
            return ['ok' => false, 'error' => 'empty_init_data'];
        }

        parse_str($initData, $fields);

        $hash = (string) ($fields['hash'] ?? '');
        if ($hash === '') {
            return ['ok' => false, 'error' => 'no_hash'];
        }
        unset($fields['hash']);

        // Важно: значения берутся ровно в том виде, в каком пришли,
        // после url-декодирования, и сортируются по имени ключа.
        ksort($fields);
        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key . '=' . (is_array($value) ? json_encode($value) : (string) $value);
        }
        $dataCheckString = implode("\n", $pairs);

        $secretKey = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $expected  = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($expected, $hash)) {
            return ['ok' => false, 'error' => 'bad_signature'];
        }

        $authDate = (int) ($fields['auth_date'] ?? 0);
        if ($authDate <= 0 || (time() - $authDate) > self::MAX_AGE) {
            return ['ok' => false, 'error' => 'stale_init_data'];
        }

        $user = json_decode((string) ($fields['user'] ?? ''), true);
        if (!is_array($user) || empty($user['id'])) {
            return ['ok' => false, 'error' => 'no_user'];
        }

        return ['ok' => true, 'user' => $user, 'auth_date' => $authDate];
    }

    /** Сборка подписанной строки — используется только в тестах. */
    public static function sign(array $fields, string $botToken): string
    {
        ksort($fields);
        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $hash      = hash_hmac('sha256', implode("\n", $pairs), $secretKey);

        return http_build_query($fields + ['hash' => $hash]);
    }
}
