<?php
declare(strict_types=1);

namespace App\Contracts;

/**
 * Команда человека (сквад). Реализуется модулем Squad.
 *
 * Лента и созвоны живут внутри команды, но не знают, как она собрана,
 * и не читают таблицы сквадов. Им нужно три вещи: кто в команде сейчас,
 * кто лидер и как сказать что-то в чат команды.
 *
 * Заглушка возвращает «команды нет» — без модуля сквадов лента и
 * созвоны честно пусты, а не падают.
 */
interface Team
{
    /**
     * Действующая команда человека или null.
     *
     * @return array{
     *   id: int, name: string, lang: string, status: string,
     *   leader_id: ?int, chat_link: ?string,
     *   members: array<int, array{user_id: int, name: string, status: string}>
     * }|null   status: active | finished
     */
    public function teamOf(int $userId): ?array;

    /** Состоит ли человек в этой команде прямо сейчас. */
    public function isMember(int $teamId, int $userId): bool;

    /** Сообщение в чат команды. false — чата нет или отправить не удалось. */
    public function announce(int $teamId, string $text): bool;
}
