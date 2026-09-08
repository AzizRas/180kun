<?php
declare(strict_types=1);

namespace Modules\Sms\Domain;

/**
 * Внутренний интерфейс шлюза. Живёт внутри модуля Sms, потому что
 * за его пределами он никому не нужен: остальное приложение знает
 * только контракт Notifier.
 */
interface Gateway
{
    public function name(): string;

    public function isConfigured(): bool;

    public function send(string $phone, string $text): bool;
}
