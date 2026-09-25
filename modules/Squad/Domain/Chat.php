<?php
declare(strict_types=1);

namespace Modules\Squad\Domain;

use App\Contracts\Auth;
use App\Kernel;

/**
 * Чат сквада — группа в Telegram (решение Р-01: живём внутри мессенджера).
 *
 * Модуль не говорит с Bot API сам. Он публикует просьбы
 * telegram.group_send / telegram.invite_link и слушает
 * telegram.group_message. Выключен модуль Telegram — сквады продолжают
 * работать, просто без публикаций в группу.
 *
 * Привязка: модератор создаёт группу, добавляет туда бота админом и
 * пишет в группе  /bind КОД  — код сквада виден в панели модератора.
 *
 * Текст сообщений не сохраняется. Храним только, кто и в какой день
 * писал: этого хватает для выбора лидера и метрики первой реакции.
 */
final class Chat
{
    public function __construct(private Kernel $kernel)
    {
    }

    /**
     * Входящее сообщение из группы. Возвращает текст ответа или null.
     */
    public function onMessage(string $chatId, ?int $userId, string $text, ?string $at = null): ?string
    {
        $at   = $at ?? gmdate('c');
        $text = trim($text);

        if (preg_match('~^/bind(?:@\w+)?\s+([A-Z0-9]{4,10})$~i', $text, $m)) {
            return $this->bind($chatId, strtoupper($m[1]));
        }

        $squad = $this->kernel->db()->first('SELECT * FROM squad_squads WHERE tg_chat_id = ?', [$chatId]);
        if ($squad === null || $userId === null) {
            return null;
        }
        $member = $this->kernel->db()->first(
            "SELECT * FROM squad_members WHERE squad_id = ? AND user_id = ? AND status <> 'left'",
            [(int) $squad['id'], $userId]
        );
        if ($member === null) {
            return null;
        }

        $day = substr($at, 0, 10);
        $this->kernel->db()->run(
            'INSERT INTO squad_chat_days (squad_id, user_id, day, messages) VALUES (?, ?, ?, 1)
             ON CONFLICT(squad_id, user_id, day) DO UPDATE SET messages = messages + 1',
            [(int) $squad['id'], $userId, $day]
        );
        $this->kernel->db()->update('squad_members', ['last_chat_at' => $at], 'squad_id = :s AND user_id = :u', [
            's' => (int) $squad['id'], 'u' => $userId,
        ]);

        // Первая реакция живого человека после карточек — лучший ранний
        // предиктор выживания сквада. Цель — меньше 30 минут.
        if (!empty($squad['first_contact_at']) && empty($squad['first_reaction_at']) && $at >= $squad['first_contact_at']) {
            $this->kernel->db()->update('squad_squads', ['first_reaction_at' => $at], 'id = :id', ['id' => (int) $squad['id']]);
        }

        // Реакция на новичка: первое сообщение кого-то другого после его представления.
        $this->kernel->db()->run(
            "UPDATE squad_members SET first_reaction_at = ?
             WHERE squad_id = ? AND user_id <> ? AND intro_at IS NOT NULL
               AND first_reaction_at IS NULL AND intro_at <= ?",
            [$at, (int) $squad['id'], $userId, $at]
        );

        return null;
    }

    private function bind(string $chatId, string $code): string
    {
        $squad = $this->kernel->db()->first('SELECT * FROM squad_squads WHERE code = ?', [$code]);
        if ($squad === null || in_array($squad['status'], ['disbanded', 'finished'], true)) {
            return $this->kernel->i18n->t('squad.chat.bind_unknown');
        }

        $taken = $this->kernel->db()->first('SELECT id FROM squad_squads WHERE tg_chat_id = ? AND id <> ?', [$chatId, (int) $squad['id']]);
        if ($taken !== null) {
            return $this->kernel->i18n->t('squad.chat.bind_taken');
        }

        $answer = $this->kernel->events->emit('telegram.invite_link', ['chat_id' => $chatId, 'link' => null]);
        $this->kernel->db()->update('squad_squads', [
            'tg_chat_id'  => $chatId,
            'invite_link' => $answer['link'] ?? $squad['invite_link'],
        ], 'id = :id', ['id' => (int) $squad['id']]);

        $squad = $this->kernel->db()->first('SELECT * FROM squad_squads WHERE id = ?', [(int) $squad['id']]);
        if ($squad['status'] === 'active' && empty($squad['first_contact_at'])) {
            $this->firstContact($squad);
        }

        return $this->kernel->i18n->t('squad.chat.bound', ['code' => $code], (string) $squad['lang']);
    }

    /**
     * Первый контакт: карточки участников и первый вопрос.
     * Если группа ещё не привязана — сделаем это при привязке.
     */
    public function firstContact(array $squad): void
    {
        if (empty($squad['tg_chat_id']) || !empty($squad['first_contact_at'])) {
            return;
        }
        $lang  = (string) $squad['lang'];
        $lines = [$this->kernel->i18n->t('squad.chat.hello', [], $lang), ''];

        foreach ($this->activeMembers((int) $squad['id']) as $m) {
            $lines[] = $this->card($m, $lang);
        }
        $lines[] = '';
        $lines[] = $this->kernel->i18n->t('squad.chat.first_question', [], $lang);

        // Время первого контакта пишем, только если карточки реально ушли:
        // иначе метрика «до первой реакции» считалась бы от сообщения,
        // которого никто не видел.
        if ($this->send((string) $squad['tg_chat_id'], implode("\n", $lines))) {
            $this->kernel->db()->update('squad_squads', ['first_contact_at' => gmdate('c')], 'id = :id', ['id' => (int) $squad['id']]);
        }
    }

    /** Новичок-замена: представляем его скваду и засекаем время до ответа. */
    public function introduce(array $squad, int $userId): void
    {
        if (empty($squad['tg_chat_id'])) {
            return;
        }
        $member = $this->kernel->db()->first(
            'SELECT * FROM squad_members WHERE squad_id = ? AND user_id = ?',
            [(int) $squad['id'], $userId]
        );
        if ($member === null) {
            return;
        }
        $lang = (string) $squad['lang'];
        if ($this->send((string) $squad['tg_chat_id'], $this->kernel->i18n->t('squad.chat.newcomer', [], $lang) . "\n" . $this->card($member, $lang))) {
            $this->kernel->db()->update('squad_members', ['intro_at' => gmdate('c')], 'squad_id = :s AND user_id = :u', [
                's' => (int) $squad['id'], 'u' => $userId,
            ]);
        }
    }

    /** Возвращение — повод для сквада, а не для разбора. Без упрёков. */
    public function announceReturn(array $squad, int $userId): void
    {
        if (empty($squad['tg_chat_id'])) {
            return;
        }
        $name = $this->name($userId);
        $this->send((string) $squad['tg_chat_id'], $this->kernel->i18n->t('squad.chat.returned', ['name' => $name], (string) $squad['lang']));
    }

    public function send(string $chatId, string $text): bool
    {
        $r = $this->kernel->events->emit('telegram.group_send', ['chat_id' => $chatId, 'text' => $text, 'sent' => false]);
        return !empty($r['sent']);
    }

    private function card(array $member, string $lang): string
    {
        $pool = $this->kernel->db()->first('SELECT goal_dir, tier, window FROM squad_pool WHERE user_id = ?', [(int) $member['user_id']]);
        $t    = fn(string $k) => $this->kernel->i18n->t($k, [], $lang);

        $parts = ['• <b>' . htmlspecialchars($this->name((int) $member['user_id']), ENT_QUOTES, 'UTF-8') . '</b>'];
        if ($pool !== null) {
            $parts[] = $t('squad.goal.' . $pool['goal_dir']);
            $parts[] = $t('tier.' . $pool['tier']);
            $parts[] = $t('squad.window.' . $pool['window']);
        }
        if (($member['role'] ?? '') === 'anchor') {
            $parts[] = $t('squad.anchor');
        }
        return implode(' · ', $parts);
    }

    private function name(int $userId): string
    {
        $user = $this->kernel->container->get(Auth::class)->userById($userId);
        $name = trim((string) ($user['name'] ?? ''));
        return $name !== '' ? $name : '#' . $userId;
    }

    private function activeMembers(int $squadId): array
    {
        return $this->kernel->db()->all(
            "SELECT * FROM squad_members WHERE squad_id = ? AND status <> 'left' ORDER BY seat",
            [$squadId]
        );
    }

    /**
     * Медиана минут до первой реакции: по скваду после карточек и по новичкам.
     *
     * @return array{squads: ?float, newcomers: ?float, samples: int}
     */
    public function reactionMetrics(): array
    {
        $squadMinutes = [];
        foreach ($this->kernel->db()->all(
            'SELECT first_contact_at, first_reaction_at FROM squad_squads WHERE first_contact_at IS NOT NULL AND first_reaction_at IS NOT NULL'
        ) as $r) {
            $squadMinutes[] = max(0, (strtotime($r['first_reaction_at']) - strtotime($r['first_contact_at'])) / 60);
        }

        $newcomerMinutes = [];
        foreach ($this->kernel->db()->all(
            'SELECT intro_at, first_reaction_at FROM squad_members WHERE intro_at IS NOT NULL AND first_reaction_at IS NOT NULL'
        ) as $r) {
            $newcomerMinutes[] = max(0, (strtotime($r['first_reaction_at']) - strtotime($r['intro_at'])) / 60);
        }

        return [
            'squads'    => $squadMinutes ? round(Matcher::median($squadMinutes), 1) : null,
            'newcomers' => $newcomerMinutes ? round(Matcher::median($newcomerMinutes), 1) : null,
            'samples'   => count($squadMinutes) + count($newcomerMinutes),
        ];
    }
}
