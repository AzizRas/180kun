<?php
declare(strict_types=1);

namespace App\Contracts;

/** Отправка сообщений пользователю: Telegram, SMS, e-mail. */
interface Notifier
{
    /** @return bool доставлено ли */
    public function send(int $userId, string $text, array $options = []): bool;

    /** Код подтверждения при регистрации. */
    public function sendCode(string $channel, string $address, string $code): bool;

    /** @return array<int, string> доступные каналы: telegram, sms */
    public function channels(): array;
}
