<?php
declare(strict_types=1);

namespace Modules\Sms\Domain;

use App\Kernel;

/**
 * Адаптер шлюза Eskiz (notify.eskiz.uz).
 *
 * Токен живёт около месяца, поэтому кешируется в storage/ и обновляется
 * только при 401. Ошибки не бросаются — возвращается false, вызывающий
 * код сам решает, что делать.
 *
 * Другой провайдер добавляется отдельным классом с теми же двумя методами:
 * менять модуль Sms целиком не придётся.
 */
final class Eskiz implements Gateway
{
    private const BASE = 'https://notify.eskiz.uz/api';

    public function __construct(private Kernel $kernel)
    {
    }

    public function name(): string
    {
        return 'eskiz';
    }

    public function isConfigured(): bool
    {
        return $this->kernel->config->get('sms.provider') === 'eskiz'
            && (string) $this->kernel->config->get('sms.login', '') !== ''
            && (string) $this->kernel->config->get('sms.password', '') !== ''
            && extension_loaded('curl');
    }

    public function send(string $phone, string $text): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $token = $this->token();
        if ($token === null) {
            return false;
        }

        $response = $this->request('POST', '/message/sms/send', [
            'mobile_phone' => ltrim($phone, '+'),
            'message'      => $text,
            'from'         => (string) $this->kernel->config->get('sms.sender', '4546'),
        ], $token);

        // Токен протух — обновляем один раз и повторяем.
        if (($response['status'] ?? 0) === 401) {
            $this->forgetToken();
            $token = $this->token();
            if ($token === null) {
                return false;
            }
            $response = $this->request('POST', '/message/sms/send', [
                'mobile_phone' => ltrim($phone, '+'),
                'message'      => $text,
                'from'         => (string) $this->kernel->config->get('sms.sender', '4546'),
            ], $token);
        }

        $ok = ($response['status'] ?? 0) < 300
            && in_array((string) ($response['body']['status'] ?? ''), ['waiting', 'success', ''], true);

        if (!$ok) {
            $this->kernel->log('warning', 'Eskiz не принял сообщение', [
                'http' => $response['status'] ?? null,
                'body' => mb_substr(json_encode($response['body'] ?? [], JSON_UNESCAPED_UNICODE) ?: '', 0, 300),
            ]);
        }
        return $ok;
    }

    private function tokenFile(): string
    {
        return $this->kernel->root . '/storage/eskiz.token';
    }

    private function token(): ?string
    {
        $file = $this->tokenFile();
        if (is_file($file) && (time() - (int) filemtime($file)) < 20 * 86400) {
            $cached = trim((string) file_get_contents($file));
            if ($cached !== '') {
                return $cached;
            }
        }

        $response = $this->request('POST', '/auth/login', [
            'email'    => (string) $this->kernel->config->get('sms.login'),
            'password' => (string) $this->kernel->config->get('sms.password'),
        ]);

        $token = (string) ($response['body']['data']['token'] ?? '');
        if ($token === '') {
            $this->kernel->log('error', 'Eskiz: не удалось получить токен', ['http' => $response['status'] ?? null]);
            return null;
        }

        @file_put_contents($file, $token);
        @chmod($file, 0600);
        return $token;
    }

    private function forgetToken(): void
    {
        @unlink($this->tokenFile());
    }

    /** @return array{status: int, body: array} */
    private function request(string $method, string $path, array $data, ?string $token = null): array
    {
        $ch      = curl_init(self::BASE . $path);
        $headers = ['Accept: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->kernel->log('warning', 'Eskiz недоступен', ['error' => $error]);
            return ['status' => 0, 'body' => []];
        }

        $decoded = json_decode((string) $body, true);
        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : []];
    }
}
