<?php
declare(strict_types=1);

namespace Modules\Telegram\Domain;

use App\Kernel;

/** Тонкий клиент Bot API. Не бросает исключений — возвращает false при неудаче. */
final class BotApi
{
    public function __construct(private Kernel $kernel, private string $token)
    {
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && extension_loaded('curl');
    }

    public function sendMessage(string $chatId, string $text, array $extra = []): bool
    {
        $result = $this->call('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ] + $extra);

        return $result !== null && ($result['ok'] ?? false) === true;
    }

    public function setWebhook(string $url, string $secret): bool
    {
        $result = $this->call('setWebhook', [
            'url'             => $url,
            'secret_token'    => $secret,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ]);
        return $result !== null && ($result['ok'] ?? false) === true;
    }

    private function call(string $method, array $params): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $ch = curl_init("https://api.telegram.org/bot{$this->token}/{$method}");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->kernel->log('warning', 'Telegram API недоступен', ['method' => $method, 'error' => $err]);
            return null;
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            $this->kernel->log('warning', 'Telegram API вернул ошибку', [
                'method' => $method,
                'body'   => mb_substr((string) $body, 0, 300),
            ]);
        }
        return is_array($decoded) ? $decoded : null;
    }
}
