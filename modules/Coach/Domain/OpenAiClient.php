<?php
declare(strict_types=1);

namespace Modules\Coach\Domain;

use App\Kernel;

/** OpenAI-совместимый /chat/completions. Не бросает исключений. */
final class OpenAiClient implements LlmClient
{
    public function __construct(private Kernel $kernel)
    {
    }

    public function isAvailable(): bool
    {
        return (bool) $this->kernel->config->get('ai.enabled', false)
            && (string) $this->kernel->config->get('ai.api_key', '') !== ''
            && extension_loaded('curl');
    }

    public function complete(string $model, string $system, string $user, int $maxTokens, int $timeout): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $url  = rtrim((string) $this->kernel->config->get('ai.base'), '/') . '/chat/completions';
        $body = json_encode([
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'max_tokens'  => $maxTokens,
            'temperature' => 0.4,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . (string) $this->kernel->config->get('ai.api_key'),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status >= 400) {
            $this->kernel->log('warning', 'Модель недоступна', ['status' => $status, 'error' => $err, 'body' => mb_substr((string) $raw, 0, 300)]);
            return null;
        }
        $data = json_decode((string) $raw, true);
        $text = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            return null;
        }
        return [
            'text'       => $text,
            'tokens_in'  => (int) ($data['usage']['prompt_tokens'] ?? 0),
            'tokens_out' => (int) ($data['usage']['completion_tokens'] ?? 0),
        ];
    }
}
