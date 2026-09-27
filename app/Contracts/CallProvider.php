<?php
declare(strict_types=1);

namespace App\Contracts;

/**
 * Где проходит созвон команды (срез 7).
 *
 * Первая реализация — видеочат группы сквада в Telegram (модуль Calls).
 * Своя видеокомната (Jitsi, LiveKit на узбекском сервере) подключается
 * заменой реализации, а расписание, ответы «приду / не смогу» и
 * напоминания остаются как есть.
 */
interface CallProvider
{
    /** Короткое имя: telegram | jitsi | … */
    public function name(): string;

    /**
     * Как попасть на созвон.
     *
     * @param array $team    Team::teamOf()
     * @param array $session строка расписания созвона
     * @return array{url: ?string, how: string}  how — ключ перевода с инструкцией
     */
    public function room(array $team, array $session): array;
}
