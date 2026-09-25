<?php
declare(strict_types=1);

namespace Modules\Squad\Domain;

use App\Kernel;

/**
 * Лидер сквада (§ 09 досье).
 *
 * - Первые 14 дней назначает система. В день старта ещё нет данных, поэтому
 *   сначала — самый общительный по анкете; на 3-й день роль переходит к
 *   тому, кто больше всех писал в чате за первые три дня.
 * - Дальше — по кругу, каждые 14 дней. За сезон каждый побывает лидером дважды.
 * - Отказаться можно одним нажатием — ход переходит следующему.
 * - 4 дня бездействия — роль молча переходит дальше, без наказания.
 * - Исключать людей лидер не может. Это только модератор.
 * - Награда за полный срок: 250 XP — начисляет модуль очков по событию.
 */
final class Leadership
{
    public const TERM_DAYS     = 14;
    public const INACTIVE_DAYS = 4;
    public const CHECK_DAY     = 3;

    /** Три обязанности — ровно три, по дням недели. Всё остальное — автоматика. */
    public const DUTIES = [1 => 'promises', 3 => 'pause_message', 5 => 'call'];

    public function __construct(private Kernel $kernel)
    {
    }

    public function currentTerm(int $squadId): ?array
    {
        return $this->kernel->db()->first(
            'SELECT * FROM squad_leader_terms WHERE squad_id = ? AND ended_on IS NULL ORDER BY id DESC LIMIT 1',
            [$squadId]
        );
    }

    public function start(array $squad, int $userId, string $from, string $reason = 'start'): void
    {
        $this->kernel->db()->insert('squad_leader_terms', [
            'squad_id'   => (int) $squad['id'],
            'user_id'    => $userId,
            'started_on' => $from,
            'created_at' => gmdate('c'),
        ]);
        $this->kernel->db()->update('squad_squads', ['leader_id' => $userId], 'id = :id', ['id' => (int) $squad['id']]);
        $this->kernel->events->emit('squad.leader_changed', [
            'squad_id' => (int) $squad['id'], 'user_id' => $userId, 'reason' => $reason, 'from' => $from,
        ]);
    }

    private function end(array $term, string $on, string $reason): void
    {
        $this->kernel->db()->update('squad_leader_terms', [
            'ended_on'   => $on,
            'end_reason' => $reason,
        ], 'id = :id', ['id' => (int) $term['id']]);
        $this->kernel->db()->update('squad_squads', ['leader_id' => null], 'id = :id', ['id' => (int) $term['squad_id']]);

        if ($reason === 'completed') {
            $this->kernel->events->emit('squad.leader_term_completed', [
                'squad_id' => (int) $term['squad_id'],
                'user_id'  => (int) $term['user_id'],
                'term_id'  => (int) $term['id'],
                'from'     => (string) $term['started_on'],
                'to'       => $on,
            ]);
        }
    }

    /** Первый лидер в день старта: самый общительный по анкете. */
    public function assignInitial(array $squad): void
    {
        $first = $this->kernel->db()->first(
            "SELECT user_id FROM squad_members WHERE squad_id = ? AND status <> 'left' ORDER BY seat LIMIT 1",
            [(int) $squad['id']]
        );
        if ($first !== null) {
            $this->start($squad, (int) $first['user_id'], (string) $squad['started_on'], 'start');
        }
    }

    /**
     * Проверка роли при каждом открытии сквада.
     *
     * @param array<int, ?string> $lastCheckin последний чек-ин участника, если модуль чек-ина есть
     */
    public function tick(array $squad, array $lastCheckin, string $today): void
    {
        if (($squad['status'] ?? '') !== 'active' || empty($squad['started_on']) || $today < $squad['started_on']) {
            return;
        }

        $this->reselectByChat($squad, $today);

        // Ограничитель: даже если сквад не открывали месяц, за один
        // вызов прокручиваем не больше дюжины сроков.
        for ($guard = 0; $guard < 12; $guard++) {
            $term = $this->currentTerm((int) $squad['id']);
            if ($term === null) {
                $next = $this->nextLeader($squad, null);
                if ($next === null) {
                    return;
                }
                $this->start($squad, $next, $today, 'rotation');
                continue;
            }

            $member = $this->member((int) $squad['id'], (int) $term['user_id']);

            // Лидер ушёл из сквада или выпал в паузу — роль переходит сразу.
            if ($member === null || $member['status'] !== 'active') {
                $this->end($term, $today, 'left');
                $this->startNext($squad, (int) $term['user_id'], $today);
                continue;
            }

            // Срок закончился.
            $termEnd = self::addDays((string) $term['started_on'], self::TERM_DAYS);
            if ($today >= $termEnd) {
                $this->end($term, $termEnd, 'completed');
                $this->startNext($squad, (int) $term['user_id'], $termEnd);
                continue;
            }

            // Бездействие: ни чек-ина, ни сообщения, ни действия в панели.
            $last = max(
                (string) $term['started_on'],
                (string) ($lastCheckin[(int) $term['user_id']] ?? ''),
                substr((string) ($member['last_chat_at'] ?? ''), 0, 10),
                substr((string) ($member['last_action_at'] ?? ''), 0, 10)
            );
            if (self::daysBetween($last, $today) >= self::INACTIVE_DAYS) {
                $this->end($term, $today, 'inactive');
                $this->startNext($squad, (int) $term['user_id'], $today);
                continue;
            }

            return;
        }
    }

    /** Отказ от роли одним нажатием. */
    public function decline(array $squad, int $userId, string $today): bool
    {
        $term = $this->currentTerm((int) $squad['id']);
        if ($term === null || (int) $term['user_id'] !== $userId) {
            return false;
        }
        $this->end($term, $today, 'declined');
        $this->startNext($squad, $userId, $today);
        return true;
    }

    public function touch(int $squadId, int $userId): void
    {
        $this->kernel->db()->update(
            'squad_members',
            ['last_action_at' => gmdate('c')],
            'squad_id = :s AND user_id = :u',
            ['s' => $squadId, 'u' => $userId]
        );
    }

    /**
     * На 3-й день первого срока роль переходит к самому активному в чате.
     * Срок при этом не продлевается: он всё равно заканчивается на 14-й день.
     */
    private function reselectByChat(array $squad, string $today): void
    {
        if ((int) ($squad['leader_checked'] ?? 0) === 1) {
            return;
        }
        $checkOn = self::addDays((string) $squad['started_on'], self::CHECK_DAY);
        if ($today < $checkOn) {
            return;
        }

        $this->kernel->db()->update('squad_squads', ['leader_checked' => 1], 'id = :id', ['id' => (int) $squad['id']]);

        $top = $this->kernel->db()->first(
            "SELECT c.user_id, SUM(c.messages) AS n
             FROM squad_chat_days c
             JOIN squad_members m ON m.squad_id = c.squad_id AND m.user_id = c.user_id
             WHERE c.squad_id = ? AND c.day >= ? AND c.day < ? AND m.status = 'active'
             GROUP BY c.user_id ORDER BY n DESC, MIN(m.seat) ASC LIMIT 1",
            [(int) $squad['id'], (string) $squad['started_on'], $checkOn]
        );
        $term = $this->currentTerm((int) $squad['id']);
        if ($top === null || $term === null || (int) $top['user_id'] === (int) $term['user_id']) {
            return;
        }

        $this->end($term, $today, 'provisional');
        $this->start($squad, (int) $top['user_id'], (string) $term['started_on'], 'chat_activity');
    }

    private function startNext(array $squad, int $afterUserId, string $from): void
    {
        $next = $this->nextLeader($squad, $afterUserId);
        if ($next !== null) {
            $this->start($squad, $next, $from, 'rotation');
        }
    }

    /** Следующий по кругу среди активных. */
    private function nextLeader(array $squad, ?int $afterUserId): ?int
    {
        $members = $this->kernel->db()->all(
            "SELECT user_id, seat FROM squad_members WHERE squad_id = ? AND status = 'active' ORDER BY seat",
            [(int) $squad['id']]
        );
        if ($members === []) {
            return null;
        }
        if ($afterUserId === null) {
            return (int) $members[0]['user_id'];
        }

        $afterSeat = (int) ($this->kernel->db()->value(
            'SELECT seat FROM squad_members WHERE squad_id = ? AND user_id = ?',
            [(int) $squad['id'], $afterUserId]
        ) ?? -1);

        foreach ($members as $m) {
            if ((int) $m['seat'] > $afterSeat) {
                return (int) $m['user_id'];
            }
        }
        // Круг замкнулся. Если активен только сам бывший лидер — он и остаётся.
        return (int) $members[0]['user_id'];
    }

    private function member(int $squadId, int $userId): ?array
    {
        return $this->kernel->db()->first(
            'SELECT * FROM squad_members WHERE squad_id = ? AND user_id = ?',
            [$squadId, $userId]
        );
    }

    public function history(int $squadId): array
    {
        return $this->kernel->db()->all(
            'SELECT user_id, started_on, ended_on, end_reason FROM squad_leader_terms WHERE squad_id = ? ORDER BY id',
            [$squadId]
        );
    }

    /** Обязанность на сегодня, если сегодня её день. */
    public static function dutyFor(string $date): ?string
    {
        $dow = (int) gmdate('N', strtotime($date . ' 00:00:00 UTC'));
        return self::DUTIES[$dow] ?? null;
    }

    public static function addDays(string $date, int $days): string
    {
        return gmdate('Y-m-d', strtotime($date . ' 00:00:00 UTC') + $days * 86400);
    }

    public static function daysBetween(string $from, string $to): int
    {
        return (int) floor((strtotime($to . ' 00:00:00 UTC') - strtotime($from . ' 00:00:00 UTC')) / 86400);
    }
}
