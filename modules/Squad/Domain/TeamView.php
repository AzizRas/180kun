<?php
declare(strict_types=1);

namespace Modules\Squad\Domain;

use App\Contracts\Auth;
use App\Contracts\Team;
use App\Kernel;

/**
 * Сквад глазами соседей (контракт Team): лента и созвоны спрашивают,
 * кто в команде, кто лидер, и просят сказать что-то в чат. Внутренности
 * подбора, счёта и ротации наружу не выходят.
 */
final class TeamView implements Team
{
    public function __construct(private Kernel $kernel, private Squads $squads, private Chat $chat)
    {
    }

    public function teamOf(int $userId): ?array
    {
        $squad = $this->squads->squadOf($userId);
        if ($squad === null || !in_array($squad['status'], ['active', 'finished'], true)) {
            return null;
        }

        $auth    = $this->kernel->container->get(Auth::class);
        $members = [];
        foreach ($this->kernel->db()->all(
            "SELECT user_id, status FROM squad_members WHERE squad_id = ? AND status <> 'left' ORDER BY seat",
            [(int) $squad['id']]
        ) as $m) {
            $u = $auth->userById((int) $m['user_id']);
            $members[] = [
                'user_id' => (int) $m['user_id'],
                'name'    => trim((string) ($u['name'] ?? '')) ?: '#' . $m['user_id'],
                'status'  => (string) $m['status'],
            ];
        }

        return [
            'id'        => (int) $squad['id'],
            'name'      => $this->kernel->i18n->t('squad.name', ['n' => (int) $squad['id']]),
            'lang'      => (string) $squad['lang'],
            'status'    => (string) $squad['status'],
            'leader_id' => $squad['leader_id'] !== null ? (int) $squad['leader_id'] : null,
            'chat_link' => $squad['invite_link'] ?: null,
            'members'   => $members,
        ];
    }

    public function isMember(int $teamId, int $userId): bool
    {
        return $this->kernel->db()->value(
            "SELECT 1 FROM squad_members WHERE squad_id = ? AND user_id = ? AND status <> 'left'",
            [$teamId, $userId]
        ) !== null;
    }

    public function announce(int $teamId, string $text): bool
    {
        $chatId = $this->kernel->db()->value('SELECT tg_chat_id FROM squad_squads WHERE id = ?', [$teamId]);
        return $chatId !== null && $chatId !== '' && $this->chat->send((string) $chatId, $text);
    }
}
