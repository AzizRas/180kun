<?php
declare(strict_types=1);

namespace Modules\Identity\Domain;

use App\Contracts\Notifier;
use App\Kernel;
use App\Result;

/**
 * Одноразовые коды подтверждения: регистрация по номеру и сброс пароля.
 *
 * Сам доставкой не занимается — просит контракт Notifier. Если ни один
 * канал не подключён, в режиме отладки код возвращается в ответе,
 * чтобы можно было тестировать локально без SMS-шлюза.
 */
final class Codes
{
    private const TTL_SECONDS     = 300;   // код живёт 5 минут
    private const MAX_ATTEMPTS    = 5;     // попыток ввести код
    private const RESEND_COOLDOWN = 60;    // не чаще раза в минуту

    public function __construct(private Kernel $kernel)
    {
    }

    /** @param 'signup'|'reset' $purpose */
    public function request(string $phone, string $purpose, string $channel = 'auto'): Result
    {
        $recent = $this->kernel->db()->first(
            'SELECT created_at FROM identity_codes
             WHERE phone = ? AND purpose = ? ORDER BY id DESC LIMIT 1',
            [$phone, $purpose]
        );

        if ($recent !== null) {
            $age = time() - strtotime((string) $recent['created_at']);
            if ($age < self::RESEND_COOLDOWN) {
                return Result::fail('code_cooldown', ['retry_after' => self::RESEND_COOLDOWN - $age]);
            }
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->kernel->db()->insert('identity_codes', [
            'phone'      => $phone,
            'purpose'    => $purpose,
            'code_hash'  => password_hash($code, PASSWORD_DEFAULT),
            'created_at' => gmdate('c'),
            'expires_at' => gmdate('c', time() + self::TTL_SECONDS),
        ]);

        /** @var Notifier $notifier */
        $notifier  = $this->kernel->container->get(Notifier::class);
        $delivered = $notifier->sendCode($channel, $phone, $code);

        $meta = ['delivered' => $delivered, 'expires_in' => self::TTL_SECONDS];

        // Локальная разработка: код виден в ответе и в логе, чтобы можно
        // было пройти сценарий без SMS-шлюза. В бою app.debug = false,
        // и код никогда не покидает канал доставки.
        if ($this->kernel->config->get('app.debug')) {
            $this->kernel->log('info', 'Код подтверждения (debug)', ['phone' => $phone, 'code' => $code]);
            $meta['debug_code'] = $code;
        }

        if (!$delivered && !$this->kernel->config->get('app.debug')) {
            return Result::fail('delivery_failed');
        }

        return Result::ok(null, $meta);
    }

    public function verify(string $phone, string $purpose, string $code): Result
    {
        $row = $this->kernel->db()->first(
            'SELECT * FROM identity_codes
             WHERE phone = ? AND purpose = ? AND verified_at IS NULL
             ORDER BY id DESC LIMIT 1',
            [$phone, $purpose]
        );

        if ($row === null) {
            return Result::fail('code_not_found');
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return Result::fail('code_expired');
        }
        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return Result::fail('code_attempts_exceeded');
        }

        $this->kernel->db()->run(
            'UPDATE identity_codes SET attempts = attempts + 1 WHERE id = ?',
            [$row['id']]
        );

        if (!password_verify($code, (string) $row['code_hash'])) {
            return Result::fail('code_wrong', ['left' => self::MAX_ATTEMPTS - (int) $row['attempts'] - 1]);
        }

        $this->kernel->db()->update(
            'identity_codes',
            ['verified_at' => gmdate('c')],
            'id = :id',
            ['id' => $row['id']]
        );

        // Одноразовый талон на завершение действия — живёт 15 минут.
        return Result::ok(['ticket' => $this->issueTicket($phone, $purpose)]);
    }

    /** Подписанный талон: подтверждает, что номер только что прошёл проверку кодом. */
    private function issueTicket(string $phone, string $purpose): string
    {
        $payload = $phone . '|' . $purpose . '|' . (time() + 900);
        return base64_encode($payload . '|' . hash_hmac('sha256', $payload, $this->secret()));
    }

    public function checkTicket(string $ticket, string $phone, string $purpose): bool
    {
        $raw   = base64_decode($ticket, true);
        $parts = $raw === false ? [] : explode('|', $raw);
        if (count($parts) !== 4) {
            return false;
        }
        [$p, $pur, $exp, $sig] = $parts;

        if ($p !== $phone || $pur !== $purpose || (int) $exp < time()) {
            return false;
        }
        return hash_equals(
            hash_hmac('sha256', $p . '|' . $pur . '|' . $exp, $this->secret()),
            $sig
        );
    }

    private function secret(): string
    {
        return $this->kernel->secret();
    }
}
