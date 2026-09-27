<?php
declare(strict_types=1);

namespace Modules\Calls\Domain;

use App\Contracts\CallProvider;

/**
 * Созвон — видеочат в группе сквада. Бот не умеет начинать видеочат сам
 * (в Bot API такого нет), поэтому ссылка ведёт в группу, а начинает звонок
 * лидер одной кнопкой; остальные видят плашку «идёт видеочат».
 */
final class TelegramRoom implements CallProvider
{
    public function name(): string
    {
        return 'telegram';
    }

    public function room(array $team, array $session): array
    {
        return ['url' => $team['chat_link'] ?? null, 'how' => 'calls.how.telegram'];
    }
}
