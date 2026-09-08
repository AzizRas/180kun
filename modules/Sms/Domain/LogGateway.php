<?php
declare(strict_types=1);

namespace Modules\Sms\Domain;

use App\Kernel;

/**
 * Запасной шлюз для разработки: ничего не отправляет, пишет сообщение
 * в storage/logs. Включается только при app.debug = true, поэтому
 * на бою случайно «отправить в лог» невозможно.
 */
final class LogGateway implements Gateway
{
    public function __construct(private Kernel $kernel)
    {
    }

    public function name(): string
    {
        return 'log';
    }

    public function isConfigured(): bool
    {
        return (bool) $this->kernel->config->get('app.debug', false);
    }

    public function send(string $phone, string $text): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $this->kernel->log('info', 'SMS (режим отладки, не отправлено)', [
            'phone' => $phone,
            'text'  => $text,
        ]);
        return true;
    }
}
