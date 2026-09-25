<?php
declare(strict_types=1);

namespace Modules\Squad\Domain;

use App\Contracts\Auth;
use App\Kernel;
use App\Result;

/**
 * Сквады: волны, предложения алгоритма, утверждение, старт и жизнь.
 *
 * Порядок (Р-07): заявки копятся в волне → модератор запускает подбор →
 * алгоритм предлагает составы → модератор утверждает, переставляет,
 * отклоняет → в день старта все утверждённые сквады стартуют вместе.
 * Первые три сезона «алгоритм предлагает — человек подтверждает»:
 * автоматического старта без утверждения нет.
 *
 * Жизненный цикл участника (§ 05), по дням без чек-ина:
 *   5  → «на паузе» (видно скваду, без осуждающих формулировок)
 *  10  → «восстановление», место заморожено
 *  14  → место освобождается; вернувшийся в течение 30 дней от начала
 *        восстановления получает его обратно, если в скваде есть место
 * Замена из листа ожидания — только если осталось меньше пяти и до конца
 * сезона больше 45 дней. Роспуск — если активных меньше трёх дольше 10 дней.
 */
final class Squads
{
    public const PAUSE_DAYS      = 5;
    public const RECOVERY_DAYS   = 10;
    public const RELEASE_DAYS    = 14;
    public const RETURN_WINDOW   = 30;   // дней от начала восстановления
    public const SEASON_DAYS     = 180;
    public const REPLACE_MIN_DAYS_LEFT = 45;
    public const DISBAND_ACTIVE  = 3;
    public const DISBAND_AFTER   = 10;

    public function __construct(
        private Kernel $kernel,
        private Pool $pool,
        private Leadership $leadership,
        private Scoring $scoring,
        private Chat $chat,
    ) {
    }

    // ================= волны =================

    public function createWave(string $startDate, string $title = '', ?string $today = null): Result
    {
        $today = $today ?? gmdate('Y-m-d');
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $startDate) || $startDate < $today) {
            return Result::fail('bad_date');
        }
        $id = $this->kernel->db()->insert('squad_waves', [
            'title'      => mb_substr(trim($title), 0, 80),
            'start_date' => $startDate,
            'status'     => 'open',
            'created_at' => gmdate('c'),
        ]);

        // Все, кто ждал без волны, попадают в неё и узнают дату старта.
        $waiting = array_map('intval', array_column(
            $this->kernel->db()->all("SELECT user_id FROM squad_pool WHERE status = 'waiting' AND wave_id IS NULL"),
            'user_id'
        ));
        if ($waiting !== []) {
            $this->kernel->db()->run("UPDATE squad_pool SET wave_id = ? WHERE status = 'waiting' AND wave_id IS NULL", [$id]);
            $this->kernel->events->emit('season.scheduled', ['user_ids' => $waiting, 'start_date' => $startDate]);
        }

        return Result::ok($this->wave((int) $id));
    }

    public function wave(int $id): ?array
    {
        return $this->kernel->db()->first('SELECT * FROM squad_waves WHERE id = ?', [$id]);
    }

    public function waves(): array
    {
        $rows = $this->kernel->db()->all('SELECT * FROM squad_waves ORDER BY start_date DESC, id DESC');
        foreach ($rows as &$w) {
            $w['pool']    = (int) $this->kernel->db()->value("SELECT COUNT(*) FROM squad_pool WHERE wave_id = ? AND status = 'waiting'", [(int) $w['id']], 0);
            $w['squads']  = (int) $this->kernel->db()->value("SELECT COUNT(*) FROM squad_squads WHERE wave_id = ?", [(int) $w['id']], 0);
        }
        return $rows;
    }

    /**
     * Подбор. Прежние неутверждённые предложения волны снимаются, люди
     * возвращаются в пул — так подбор можно перезапускать сколько угодно.
     */
    public function runMatching(int $waveId): Result
    {
        $wave = $this->wave($waveId);
        if ($wave === null || $wave['status'] === 'started') {
            return Result::fail('wave_closed');
        }

        return $this->kernel->db()->transaction(function () use ($waveId, $wave) {
            foreach ($this->kernel->db()->all("SELECT id FROM squad_squads WHERE wave_id = ? AND status = 'proposed'", [$waveId]) as $old) {
                $this->dropProposal((int) $old['id']);
            }

            $candidates = $this->pool->candidates($waveId);
            $result     = Matcher::match($candidates, $this->pool->related());
            $byId       = [];
            foreach ($candidates as $c) {
                $byId[$c['user_id']] = $c;
            }

            $ids = [];
            foreach ($result['squads'] as $proposal) {
                $ids[] = $this->persistProposal($waveId, $proposal, $byId);
            }

            $this->kernel->db()->update('squad_waves', [
                'status'     => 'proposed',
                'matched_at' => gmdate('c'),
            ], 'id = :id', ['id' => $waveId]);

            $this->kernel->events->emit('squad.proposed', ['wave_id' => $waveId, 'squad_ids' => $ids, 'left' => $result['left']]);

            return Result::ok(['squads' => $ids, 'left' => $result['left'], 'start_date' => $wave['start_date']]);
        });
    }

    private function persistProposal(int $waveId, array $proposal, array $byId): int
    {
        $members = array_map(static fn($id) => $byId[$id], $proposal['members']);

        // Порядок ротации: сначала самые общительные — первый станет
        // лидером в день старта, пока нет данных чата.
        usort($members, static fn($a, $b) => [-(int) $a['social'], (int) $a['user_id']] <=> [-(int) $b['social'], (int) $b['user_id']]);

        $squadId = (int) $this->kernel->db()->insert('squad_squads', [
            'wave_id'    => $waveId,
            'code'       => $this->newCode(),
            'lang'       => $proposal['lang'],
            'goal_dir'   => $proposal['goal_dir'],
            'sex'        => $proposal['sex'],
            'base_tier'  => $proposal['base_tier'],
            'status'     => 'proposed',
            'cost'       => $proposal['cost'],
            'flags'      => json_encode($proposal['flags']),
            'created_at' => gmdate('c'),
        ]);

        foreach ($members as $seat => $m) {
            $this->kernel->db()->insert('squad_members', [
                'squad_id'     => $squadId,
                'user_id'      => (int) $m['user_id'],
                'seat'         => $seat,
                'role'         => (int) $m['user_id'] === (int) ($proposal['anchor'] ?? 0) ? 'anchor' : 'member',
                'status'       => 'active',
                'status_since' => gmdate('Y-m-d'),
                'joined_on'    => gmdate('Y-m-d'),
            ]);
        }
        $this->pool->setStatus($proposal['members'], 'proposed');

        return $squadId;
    }

    private function dropProposal(int $squadId): void
    {
        $ids = array_map('intval', array_column(
            $this->kernel->db()->all('SELECT user_id FROM squad_members WHERE squad_id = ?', [$squadId]),
            'user_id'
        ));
        $this->kernel->db()->run('DELETE FROM squad_members WHERE squad_id = ?', [$squadId]);
        $this->kernel->db()->run('DELETE FROM squad_squads WHERE id = ?', [$squadId]);
        $this->pool->setStatus($ids, 'waiting');
    }

    public function approve(int $squadId, int $moderatorId): Result
    {
        $squad = $this->find($squadId);
        if ($squad === null || $squad['status'] !== 'proposed') {
            return Result::fail('not_proposed');
        }
        $bad = Matcher::violations($this->candidatesOf($squadId), $this->pool->related(), true);
        if ($bad !== []) {
            return Result::fail('rules_violated', ['rules' => $bad]);
        }
        $this->kernel->db()->update('squad_squads', [
            'status'      => 'approved',
            'approved_at' => gmdate('c'),
            'approved_by' => $moderatorId,
        ], 'id = :id', ['id' => $squadId]);
        $this->kernel->events->emit('squad.approved', ['squad_id' => $squadId, 'by' => $moderatorId]);
        return Result::ok($this->find($squadId));
    }

    public function reject(int $squadId): Result
    {
        $squad = $this->find($squadId);
        if ($squad === null || !in_array($squad['status'], ['proposed', 'approved'], true)) {
            return Result::fail('not_proposed');
        }
        $this->dropProposal($squadId);
        return Result::ok();
    }

    /**
     * Ручная перестановка модератором: человек из пула или из другого
     * неначатого состава — в неначатый состав. Жёсткие правила проверяются
     * для обоих составов; нарушить их нельзя даже вручную.
     */
    public function move(int $userId, int $toSquadId): Result
    {
        $to = $this->find($toSquadId);
        if ($to === null || !in_array($to['status'], ['proposed', 'approved'], true)) {
            return Result::fail('target_locked');
        }
        $poolRow = $this->pool->find($userId);
        if ($poolRow === null || !in_array($poolRow['status'], ['waiting', 'proposed'], true)) {
            return Result::fail('not_movable');
        }

        $from = $this->kernel->db()->first(
            "SELECT s.* FROM squad_members m JOIN squad_squads s ON s.id = m.squad_id
             WHERE m.user_id = ? AND s.status IN ('proposed', 'approved')",
            [$userId]
        );
        if ($from !== null && (int) $from['id'] === $toSquadId) {
            return Result::fail('same_squad');
        }

        $related = $this->pool->related();
        $target  = array_merge($this->candidatesOf($toSquadId), [Pool::toCandidate($poolRow)]);
        $bad     = Matcher::violations($target, $related, true);
        if ($bad !== []) {
            return Result::fail('rules_violated', ['rules' => $bad]);
        }
        if ($from !== null) {
            $rest = array_values(array_filter($this->candidatesOf((int) $from['id']), static fn($c) => $c['user_id'] !== $userId));
            $badFrom = Matcher::violations($rest, $related, true);
            if ($badFrom !== []) {
                return Result::fail('source_breaks', ['rules' => $badFrom]);
            }
        }

        $this->kernel->db()->transaction(function () use ($userId, $from, $toSquadId, $related) {
            if ($from !== null) {
                $this->kernel->db()->run('DELETE FROM squad_members WHERE squad_id = ? AND user_id = ?', [(int) $from['id'], $userId]);
                $this->refreshProposalMeta((int) $from['id'], $related);
            }
            $seat = (int) $this->kernel->db()->value('SELECT COALESCE(MAX(seat), -1) + 1 FROM squad_members WHERE squad_id = ?', [$toSquadId], 0);
            $this->kernel->db()->insert('squad_members', [
                'squad_id' => $toSquadId, 'user_id' => $userId, 'seat' => $seat, 'role' => 'member',
                'status' => 'active', 'status_since' => gmdate('Y-m-d'), 'joined_on' => gmdate('Y-m-d'),
            ]);
            $this->refreshProposalMeta($toSquadId, $related);
            $this->pool->setStatus([$userId], 'proposed');
            // Перестановка меняет состав — утверждение нужно заново.
            foreach (array_filter([$from['id'] ?? null, $toSquadId]) as $sid) {
                $this->kernel->db()->run("UPDATE squad_squads SET status = 'proposed', approved_at = NULL WHERE id = ? AND status = 'approved'", [(int) $sid]);
            }
        });

        return Result::ok($this->find($toSquadId));
    }

    private function refreshProposalMeta(int $squadId, array $related): void
    {
        $members = $this->candidatesOf($squadId);
        if ($members === []) {
            $this->kernel->db()->run('DELETE FROM squad_squads WHERE id = ?', [$squadId]);
            return;
        }
        $anchor = null;
        $base   = Matcher::tierNo(Matcher::baseTier($members));
        foreach ($members as $m) {
            if (Matcher::tierNo($m['tier']) === $base + 1) {
                $anchor = $m['user_id'];
            }
        }
        $this->kernel->db()->run("UPDATE squad_members SET role = CASE WHEN user_id = ? THEN 'anchor' ELSE 'member' END WHERE squad_id = ?", [(int) $anchor, $squadId]);
        $sexes = array_unique(array_column($members, 'sex'));
        $this->kernel->db()->update('squad_squads', [
            'cost'      => round(Matcher::cost($members, $related), 3),
            'flags'     => json_encode(Matcher::flags($members, $related)),
            'base_tier' => Matcher::baseTier($members),
            'sex'       => count($sexes) > 1 ? 'mixed' : (string) reset($sexes),
        ], 'id = :id', ['id' => $squadId]);
    }

    /**
     * Старт волны: все утверждённые сквады начинают в один день.
     * Неутверждённые предложения снимаются, люди ждут следующую волну.
     */
    public function startWave(int $waveId, ?string $today = null): Result
    {
        $wave = $this->wave($waveId);
        if ($wave === null || $wave['status'] === 'started') {
            return Result::fail('wave_closed');
        }
        $approved = $this->kernel->db()->all("SELECT * FROM squad_squads WHERE wave_id = ? AND status = 'approved'", [$waveId]);
        if ($approved === []) {
            return Result::fail('nothing_approved');
        }

        // День 1 — назначенная дата волны. Нажали «старт» заранее — составы
        // фиксируются и знакомятся в чате, а счёт дней пойдёт с даты волны.
        // Опоздали с нажатием — день 1 сегодня: задним числом не стартуем.
        $startOn = max($today ?? gmdate('Y-m-d'), (string) $wave['start_date']);

        foreach ($this->kernel->db()->all("SELECT id FROM squad_squads WHERE wave_id = ? AND status = 'proposed'", [$waveId]) as $p) {
            $this->dropProposal((int) $p['id']);
        }

        $started = [];
        foreach ($approved as $squad) {
            $this->kernel->db()->update('squad_squads', ['status' => 'active', 'started_on' => $startOn], 'id = :id', ['id' => (int) $squad['id']]);
            $this->kernel->db()->run('UPDATE squad_members SET joined_on = ?, status_since = ? WHERE squad_id = ?', [$startOn, $startOn, (int) $squad['id']]);
            $userIds = $this->memberIds((int) $squad['id']);
            $this->pool->setStatus($userIds, 'placed');

            $squad = $this->find((int) $squad['id']);
            $this->leadership->assignInitial($squad);
            $this->kernel->events->emit('season.scheduled', ['user_ids' => $userIds, 'start_date' => $startOn]);
            $this->kernel->events->emit('squad.started', ['squad_id' => (int) $squad['id'], 'user_ids' => $userIds, 'start_date' => $startOn]);
            $this->chat->firstContact($this->find((int) $squad['id']));
            $started[] = (int) $squad['id'];
        }

        // Кто остался без сквада — переходит в следующую волну.
        $this->kernel->db()->run("UPDATE squad_pool SET wave_id = NULL WHERE wave_id = ? AND status = 'waiting'", [$waveId]);
        $this->kernel->db()->update('squad_waves', ['status' => 'started', 'started_at' => gmdate('c'), 'start_date' => $startOn], 'id = :id', ['id' => $waveId]);

        return Result::ok(['squads' => $started, 'start_date' => $startOn]);
    }

    // ================= жизнь сквада =================

    /**
     * Ленивый пересчёт при каждом открытии: статусы участников, лидер,
     * замены, роспуск, счёт закрытых недель.
     */
    public function sweep(int $squadId, ?string $today = null): ?array
    {
        $today = $today ?? gmdate('Y-m-d');
        $squad = $this->find($squadId);
        if ($squad === null || $squad['status'] !== 'active' || $today < (string) $squad['started_on']) {
            return $squad;
        }

        // Ушедшие молча ещё 30 дней могут вернуться на своё место — их тоже смотрим.
        $members  = $this->kernel->db()->all(
            "SELECT * FROM squad_members WHERE squad_id = ?
               AND (status <> 'left' OR (left_reason = 'silent' AND left_on >= ?))",
            [$squadId, Leadership::addDays($today, -(self::RETURN_WINDOW + 1))]
        );
        $userIds  = array_map(static fn($m) => (int) $m['user_id'], $members);
        $activity = $this->kernel->events->emit('squad.activity', ['user_ids' => $userIds, 'last_dates' => null]);
        $known    = is_array($activity['last_dates'] ?? null);
        $last     = $known ? (array) $activity['last_dates'] : [];

        // Без модуля чек-ина статусы не меняем: не знаем, кто пропал.
        if ($known) {
            foreach ($members as $m) {
                $this->updateMemberStatus($squad, $m, $last[(int) $m['user_id']] ?? null, $today);
            }
        }

        $this->leadership->tick($this->find($squadId), $last, $today);

        $squad = $this->find($squadId);
        $this->maybeReplace($squad, $today);
        $this->maybeDisband($squad, $today);
        $this->finishIfOver($this->find($squadId), $today);

        $squad = $this->find($squadId);
        if ($squad['status'] === 'active' || $squad['status'] === 'finished') {
            $this->scoring->scoreClosedWeeks($squad, $today);
        }
        return $squad;
    }

    private function updateMemberStatus(array $squad, array $m, ?string $lastDate, string $today): void
    {
        $ref = max((string) $m['joined_on'], (string) ($lastDate ?? ''));
        $gap = Leadership::daysBetween($ref, $today);

        $new = match (true) {
            $gap >= self::RELEASE_DAYS  => 'left',
            $gap >= self::RECOVERY_DAYS => 'recovery',
            $gap >= self::PAUSE_DAYS    => 'paused',
            default                     => 'active',
        };

        $old = (string) $m['status'];
        if ($old === $new) {
            return;
        }

        if ($old === 'left') {
            // Ушёл не молча (переведён, исключён, роспуск) — не возвращаем.
            if ($m['left_reason'] !== 'silent' || $new === 'left') {
                return;
            }
            // Вернулся в течение 30 дней от начала восстановления и есть место.
            $recoveryStart = Leadership::addDays((string) $m['left_on'], self::RECOVERY_DAYS - self::RELEASE_DAYS);
            $seats = (int) $this->kernel->db()->value("SELECT COUNT(*) FROM squad_members WHERE squad_id = ? AND status <> 'left'", [(int) $squad['id']], 0);
            if (Leadership::daysBetween($recoveryStart, $today) > self::RETURN_WINDOW || $seats >= Matcher::MAX) {
                // Место не вернуть — человек снова ждёт сквад в пуле, а не висит между.
                $this->kernel->db()->run(
                    "UPDATE squad_pool SET status = 'waiting', wave_id = NULL WHERE user_id = ? AND status = 'placed'",
                    [(int) $m['user_id']]
                );
                return;
            }
        }

        $fields = ['status' => $new, 'status_since' => $today];
        if ($new === 'left') {
            $fields += ['left_on' => $today, 'left_reason' => 'silent'];
        } elseif ($old === 'left') {
            $fields += ['left_on' => null, 'left_reason' => null];
        }
        $this->kernel->db()->update('squad_members', $fields, 'squad_id = :s AND user_id = :u', [
            's' => (int) $squad['id'], 'u' => (int) $m['user_id'],
        ]);

        $this->kernel->events->emit('squad.member_status', [
            'squad_id' => (int) $squad['id'], 'user_id' => (int) $m['user_id'], 'from' => $old, 'to' => $new,
        ]);
        if ($new === 'left') {
            $this->kernel->events->emit('squad.member_left', ['squad_id' => (int) $squad['id'], 'user_id' => (int) $m['user_id'], 'reason' => 'silent']);
        }
        if ($new === 'active' && in_array($old, ['recovery', 'left'], true)) {
            $this->chat->announceReturn($squad, (int) $m['user_id']);
        }
    }

    /** Замена из листа ожидания (§ 05): меньше пяти и до конца больше 45 дней. */
    private function maybeReplace(array $squad, string $today): void
    {
        if ($squad['status'] !== 'active') {
            return;
        }
        $daysLeft = self::SEASON_DAYS - Leadership::daysBetween((string) $squad['started_on'], $today);
        if ($daysLeft <= self::REPLACE_MIN_DAYS_LEFT) {
            return;
        }

        $related = $this->pool->related();
        while (count($this->candidatesOf((int) $squad['id'])) < Matcher::MIN) {
            $current = $this->candidatesOf((int) $squad['id']);
            $waiting = $this->kernel->db()->all(
                "SELECT * FROM squad_pool WHERE status = 'waiting' AND goal_dir = ? AND lang = ? ORDER BY user_id",
                [$squad['goal_dir'], $squad['lang']]
            );

            $best = null;
            $bestCost = INF;
            foreach ($waiting as $row) {
                $c   = Pool::toCandidate($row);
                $try = array_merge($current, [$c]);
                // Размер ещё может быть меньше пяти — нижнюю границу не проверяем.
                if (Matcher::violations($try, $related, false) !== []) {
                    continue;
                }
                // Базовая ступень сквада не меняется из-за новичка.
                if (Matcher::tierNo($c['tier']) < Matcher::tierNo((string) $squad['base_tier'])) {
                    continue;
                }
                $cost = Matcher::cost($try, $related);
                if ($cost < $bestCost) {
                    $bestCost = $cost;
                    $best     = $c;
                }
            }
            if ($best === null) {
                return;
            }
            $this->addMember($squad, $best, $today);
        }
    }

    private function addMember(array $squad, array $candidate, string $today): void
    {
        $seat = (int) $this->kernel->db()->value('SELECT COALESCE(MAX(seat), -1) + 1 FROM squad_members WHERE squad_id = ?', [(int) $squad['id']], 0);
        $isAnchor = Matcher::tierNo($candidate['tier']) === Matcher::tierNo((string) $squad['base_tier']) + 1;

        $this->kernel->db()->insert('squad_members', [
            'squad_id'     => (int) $squad['id'],
            'user_id'      => (int) $candidate['user_id'],
            'seat'         => $seat,
            'role'         => $isAnchor ? 'anchor' : 'member',
            'status'       => 'active',
            'status_since' => $today,
            'joined_on'    => $today,
        ]);
        $this->pool->setStatus([(int) $candidate['user_id']], 'placed');
        $this->kernel->events->emit('squad.member_added', [
            'squad_id' => (int) $squad['id'], 'user_id' => (int) $candidate['user_id'], 'reason' => 'replacement',
        ]);
        $this->chat->introduce($squad, (int) $candidate['user_id']);
    }

    private function maybeDisband(array $squad, string $today): void
    {
        if ($squad['status'] !== 'active') {
            return;
        }
        $active = (int) $this->kernel->db()->value("SELECT COUNT(*) FROM squad_members WHERE squad_id = ? AND status = 'active'", [(int) $squad['id']], 0);

        if ($active >= self::DISBAND_ACTIVE) {
            if ($squad['low_since'] !== null) {
                $this->kernel->db()->update('squad_squads', ['low_since' => null], 'id = :id', ['id' => (int) $squad['id']]);
            }
            return;
        }
        if ($squad['low_since'] === null) {
            $this->kernel->db()->update('squad_squads', ['low_since' => $today], 'id = :id', ['id' => (int) $squad['id']]);
            return;
        }
        if (Leadership::daysBetween((string) $squad['low_since'], $today) > self::DISBAND_AFTER) {
            $this->disband((int) $squad['id'], 'inactive', $today);
        }
    }

    /** Роспуск: люди возвращаются в пул и распределяются в другие сквады той же страты. */
    public function disband(int $squadId, string $reason = 'moderator', ?string $today = null): Result
    {
        $today = $today ?? gmdate('Y-m-d');
        $squad = $this->find($squadId);
        if ($squad === null || !in_array($squad['status'], ['active', 'approved', 'proposed'], true)) {
            return Result::fail('not_active');
        }

        $userIds = $this->memberIds($squadId, true);
        $this->kernel->db()->run(
            "UPDATE squad_members SET status = 'left', left_on = ?, left_reason = 'disbanded' WHERE squad_id = ? AND status <> 'left'",
            [$today, $squadId]
        );
        $this->kernel->db()->run("UPDATE squad_pool SET status = 'waiting', wave_id = NULL WHERE user_id IN (" . ($userIds ? implode(',', $userIds) : '0') . ')');
        $this->kernel->db()->update('squad_squads', ['status' => 'disbanded', 'disbanded_at' => gmdate('c'), 'leader_id' => null], 'id = :id', ['id' => $squadId]);
        $this->kernel->db()->run("UPDATE squad_leader_terms SET ended_on = ?, end_reason = 'disbanded' WHERE squad_id = ? AND ended_on IS NULL", [$today, $squadId]);

        $this->kernel->events->emit('squad.disbanded', ['squad_id' => $squadId, 'user_ids' => $userIds, 'reason' => $reason]);
        return Result::ok(['user_ids' => $userIds]);
    }

    private function finishIfOver(array $squad, string $today): void
    {
        if ($squad['status'] === 'active'
            && Leadership::daysBetween((string) $squad['started_on'], $today) >= self::SEASON_DAYS) {
            $this->kernel->db()->update('squad_squads', ['status' => 'finished'], 'id = :id', ['id' => (int) $squad['id']]);
        }
    }

    /** Модератор убирает участника (токсичность, просьба человека). Лидер так не может. */
    public function removeMember(int $squadId, int $userId, string $reason = 'removed', ?string $today = null): Result
    {
        $today = $today ?? gmdate('Y-m-d');
        $m = $this->kernel->db()->first(
            "SELECT * FROM squad_members WHERE squad_id = ? AND user_id = ? AND status <> 'left'",
            [$squadId, $userId]
        );
        if ($m === null) {
            return Result::fail('not_member');
        }
        $this->kernel->db()->update('squad_members', [
            'status' => 'left', 'left_on' => $today, 'left_reason' => $reason, 'status_since' => $today,
        ], 'squad_id = :s AND user_id = :u', ['s' => $squadId, 'u' => $userId]);
        $this->kernel->db()->run("UPDATE squad_pool SET status = 'waiting', wave_id = NULL WHERE user_id = ?", [$userId]);
        $this->kernel->events->emit('squad.member_left', ['squad_id' => $squadId, 'user_id' => $userId, 'reason' => $reason]);
        return Result::ok();
    }

    /** Возврат после срыва: место ещё за человеком — он снова активен сразу. */
    public function onUserReturned(int $userId, string $today): void
    {
        $row = $this->kernel->db()->first(
            "SELECT m.squad_id FROM squad_members m JOIN squad_squads s ON s.id = m.squad_id
             WHERE m.user_id = ? AND s.status = 'active' ORDER BY m.joined_on DESC LIMIT 1",
            [$userId]
        );
        if ($row !== null) {
            $this->sweep((int) $row['squad_id'], $today);
        }
    }

    public function flagHelp(int $squadId, int $byUserId, int $userId): Result
    {
        $m = $this->kernel->db()->first(
            "SELECT * FROM squad_members WHERE squad_id = ? AND user_id = ? AND status <> 'left'",
            [$squadId, $userId]
        );
        if ($m === null) {
            return Result::fail('not_member');
        }
        $this->kernel->db()->update('squad_members', ['needs_help' => 1], 'squad_id = :s AND user_id = :u', ['s' => $squadId, 'u' => $userId]);
        $this->leadership->touch($squadId, $byUserId);
        $this->kernel->events->emit('squad.help_requested', ['squad_id' => $squadId, 'user_id' => $userId, 'by' => $byUserId]);
        return Result::ok();
    }

    // ================= чтение =================

    public function find(int $id): ?array
    {
        return $this->kernel->db()->first('SELECT * FROM squad_squads WHERE id = ?', [$id]);
    }

    /** Текущий сквад человека: действующий или утверждённый к старту. */
    public function squadOf(int $userId): ?array
    {
        return $this->kernel->db()->first(
            "SELECT s.* FROM squad_members m JOIN squad_squads s ON s.id = m.squad_id
             WHERE m.user_id = ? AND m.status <> 'left' AND s.status IN ('active', 'approved', 'proposed', 'finished')
             ORDER BY CASE s.status WHEN 'active' THEN 0 WHEN 'finished' THEN 1 ELSE 2 END, s.id DESC LIMIT 1",
            [$userId]
        );
    }

    /** @return array<int, int> */
    public function memberIds(int $squadId, bool $includeLeft = false): array
    {
        return array_map('intval', array_column($this->kernel->db()->all(
            'SELECT user_id FROM squad_members WHERE squad_id = ?' . ($includeLeft ? '' : " AND status <> 'left'") . ' ORDER BY seat',
            [$squadId]
        ), 'user_id'));
    }

    /** Участники, занимающие места, в формате Matcher (из снимков пула). */
    public function candidatesOf(int $squadId): array
    {
        $rows = $this->kernel->db()->all(
            "SELECT p.* FROM squad_members m JOIN squad_pool p ON p.user_id = m.user_id
             WHERE m.squad_id = ? AND m.status <> 'left' ORDER BY m.seat",
            [$squadId]
        );
        return array_map([Pool::class, 'toCandidate'], $rows);
    }

    /**
     * Экран сквада для участника. Статусы — без осуждающих формулировок:
     * «на паузе», а не «пропустил». Очки и серии других участников не
     * показываются — только командный счёт (Р-16).
     */
    public function viewFor(int $userId, ?string $today = null): array
    {
        $today = $today ?? gmdate('Y-m-d');
        $pool  = $this->pool->find($userId);
        $squad = $this->squadOf($userId);

        if ($squad !== null && $squad['status'] === 'active') {
            $squad = $this->sweep((int) $squad['id'], $today);
            // После пересчёта человек мог выпасть из сквада.
            $squad = $this->squadOf($userId);
        }

        if ($squad === null || !in_array($squad['status'], ['active', 'finished'], true)) {
            $wave = !empty($pool['wave_id']) ? $this->wave((int) $pool['wave_id']) : null;
            return [
                'status'     => $pool === null ? 'none' : 'waiting',
                'prefs_set'  => $pool !== null && (int) $pool['prefs_set'] === 1,
                'mixed_ok'   => $pool !== null && (int) $pool['mixed_ok'] === 1,
                'commit'     => $pool !== null ? (int) $pool['commit_level'] : 2,
                'wave'       => $wave === null ? null : [
                    'start_date' => $wave['start_date'],
                    'days_left'  => max(0, Leadership::daysBetween($today, (string) $wave['start_date'])),
                ],
            ];
        }

        $auth    = $this->kernel->container->get(Auth::class);
        $members = [];
        $myRow   = null;
        foreach ($this->kernel->db()->all(
            "SELECT * FROM squad_members WHERE squad_id = ? AND status <> 'left' ORDER BY seat",
            [(int) $squad['id']]
        ) as $m) {
            $u = $auth->userById((int) $m['user_id']);
            $members[] = [
                'user_id'    => (int) $m['user_id'],
                'name'       => trim((string) ($u['name'] ?? '')) ?: '#' . $m['user_id'],
                'status'     => $m['status'],
                'anchor'     => $m['role'] === 'anchor',
                'leader'     => (int) $squad['leader_id'] === (int) $m['user_id'],
                'me'         => (int) $m['user_id'] === $userId,
                'needs_help' => (int) $m['needs_help'] === 1,
            ];
            if ((int) $m['user_id'] === $userId) {
                $myRow = $m;
            }
        }

        $term = $this->leadership->currentTerm((int) $squad['id']);

        return [
            'status'      => $squad['status'],
            'squad'       => [
                'id'          => (int) $squad['id'],
                'name'        => $this->kernel->i18n->t('squad.name', ['n' => (int) $squad['id']]),
                'lang'        => $squad['lang'],
                'started_on'  => $squad['started_on'],
                'day'         => Leadership::daysBetween((string) $squad['started_on'], $today) + 1,
                'chat_link'   => $squad['invite_link'],
                'members'     => $members,
                'leader_id'   => $squad['leader_id'] !== null ? (int) $squad['leader_id'] : null,
                'leader_until'=> $term !== null ? Leadership::addDays((string) $term['started_on'], Leadership::TERM_DAYS) : null,
            ],
            'i_am_leader' => $squad['leader_id'] !== null && (int) $squad['leader_id'] === $userId,
            'my_status'   => $myRow['status'] ?? null,
            'week'        => $this->scoring->currentWeek($squad, $today),
            'history'     => $this->scoring->history((int) $squad['id']),
        ];
    }

    /** Панель лидера: обязанность дня и кому написать. Черновики — шаблоны (ИИ — срез 5). */
    public function leaderPanel(int $userId, ?string $today = null): Result
    {
        $today = $today ?? gmdate('Y-m-d');
        $squad = $this->squadOf($userId);
        if ($squad === null || $squad['status'] !== 'active') {
            return Result::fail('no_squad');
        }
        $squad = $this->sweep((int) $squad['id'], $today);
        if ((int) ($squad['leader_id'] ?? 0) !== $userId) {
            return Result::fail('not_leader');
        }

        $this->leadership->touch((int) $squad['id'], $userId);
        $lang   = (string) $squad['lang'];
        $auth   = $this->kernel->container->get(Auth::class);
        $paused = [];
        foreach ($this->kernel->db()->all(
            "SELECT * FROM squad_members WHERE squad_id = ? AND status IN ('paused', 'recovery') ORDER BY seat",
            [(int) $squad['id']]
        ) as $m) {
            $name     = trim((string) ($auth->userById((int) $m['user_id'])['name'] ?? '')) ?: '#' . $m['user_id'];
            $paused[] = [
                'user_id' => (int) $m['user_id'],
                'name'    => $name,
                'status'  => $m['status'],
                'draft'   => $this->kernel->i18n->t('squad.draft.pause', ['name' => $name], $lang),
            ];
        }

        $duty   = Leadership::dutyFor($today);
        $duties = [];
        foreach (Leadership::DUTIES as $dow => $key) {
            $duties[] = [
                'key'   => $key,
                'dow'   => $dow,
                'today' => $key === $duty,
                'title' => $this->kernel->i18n->t('squad.duty.' . $key),
                'draft' => $this->kernel->i18n->t('squad.draft.' . $key, [], $lang),
            ];
        }

        return Result::ok([
            'squad_id'  => (int) $squad['id'],
            'duty'      => $duty,
            'duties'    => $duties,
            'paused'    => $paused,
            'chat_link' => $squad['invite_link'],
            'term_ends' => ($t = $this->leadership->currentTerm((int) $squad['id'])) ? Leadership::addDays((string) $t['started_on'], Leadership::TERM_DAYS) : null,
        ]);
    }

    /** Для модератора: состав с полями подбора, ценой и флагами. */
    public function adminView(int $squadId): ?array
    {
        $squad = $this->find($squadId);
        if ($squad === null) {
            return null;
        }
        $auth    = $this->kernel->container->get(Auth::class);
        $members = [];
        foreach ($this->kernel->db()->all(
            'SELECT m.*, p.goal_dir, p.sex, p.age, p.tier, p.steps, p.time_budget, p.bmi, p.window, p.social, p.experience, p.commit_level, p.mixed_ok
             FROM squad_members m LEFT JOIN squad_pool p ON p.user_id = m.user_id
             WHERE m.squad_id = ? ORDER BY m.status = \'left\', m.seat',
            [$squadId]
        ) as $m) {
            $u = $auth->userById((int) $m['user_id']);
            $members[] = [
                'user_id' => (int) $m['user_id'],
                'name'    => trim((string) ($u['name'] ?? '')) ?: '#' . $m['user_id'],
                'status'  => $m['status'],
                'role'    => $m['role'],
                'sex'     => $m['sex'],
                'age'     => (int) $m['age'],
                'tier'    => $m['tier'],
                'steps'   => (int) $m['steps'],
                'time'    => (int) $m['time_budget'],
                'bmi'     => (float) $m['bmi'],
                'window'  => $m['window'],
                'social'  => (int) $m['social'],
                'commit'  => (int) $m['commit_level'],
                'experience' => $m['experience'],
                'needs_help' => (int) $m['needs_help'] === 1,
            ];
        }

        return [
            'id'         => (int) $squad['id'],
            'code'       => $squad['code'],
            'status'     => $squad['status'],
            'wave_id'    => $squad['wave_id'] !== null ? (int) $squad['wave_id'] : null,
            'lang'       => $squad['lang'],
            'goal_dir'   => $squad['goal_dir'],
            'sex'        => $squad['sex'],
            'base_tier'  => $squad['base_tier'],
            'cost'       => (float) $squad['cost'],
            'flags'      => json_decode((string) $squad['flags'], true) ?: [],
            'rules'      => Matcher::violations($this->candidatesOf($squadId), $this->pool->related(), true),
            'leader_id'  => $squad['leader_id'] !== null ? (int) $squad['leader_id'] : null,
            'chat_bound' => !empty($squad['tg_chat_id']),
            'invite_link'=> $squad['invite_link'],
            'started_on' => $squad['started_on'],
            'first_contact_at'  => $squad['first_contact_at'],
            'first_reaction_at' => $squad['first_reaction_at'],
            'members'    => $members,
            'scores'     => $this->scoring->history($squadId),
        ];
    }

    public function listForWave(?int $waveId): array
    {
        $rows = $waveId === null
            ? $this->kernel->db()->all("SELECT id FROM squad_squads WHERE status = 'active' ORDER BY id")
            : $this->kernel->db()->all('SELECT id FROM squad_squads WHERE wave_id = ? ORDER BY id', [$waveId]);
        return array_values(array_filter(array_map(fn($r) => $this->adminView((int) $r['id']), $rows)));
    }

    public function setInviteLink(int $squadId, string $link): Result
    {
        $link = trim($link);
        if ($link !== '' && !preg_match('~^https://t\.me/\S+$~', $link)) {
            return Result::fail('bad_link');
        }
        $this->kernel->db()->update('squad_squads', ['invite_link' => $link !== '' ? $link : null], 'id = :id', ['id' => $squadId]);
        return Result::ok();
    }

    private function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // без 0/O и 1/I
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while ($this->kernel->db()->value('SELECT 1 FROM squad_squads WHERE code = ?', [$code]) !== null);
        return $code;
    }
}
