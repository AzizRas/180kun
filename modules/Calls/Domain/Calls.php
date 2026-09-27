<?php
declare(strict_types=1);

namespace Modules\Calls\Domain;

use App\Contracts\CallProvider;
use App\Contracts\Notifier;
use App\Contracts\Team;
use App\Kernel;
use App\Result;

/**
 * Созвоны сквада.
 *
 * Назначает лидер: это одно из его дел недели (§ 09), и созвон без
 * хозяина никто не начнёт. Остальные отвечают «приду / не смогу /
 * возможно» — не для учёта явки, а чтобы лидер знал, стоит ли звать.
 * Время вводится и показывается по Ташкенту, хранится в UTC.
 */
final class Calls
{
    public const TOPICS    = ['week', 'support', 'plan', 'free'];
    public const ANSWERS   = ['yes', 'no', 'maybe'];
    public const DURATIONS = [15, 30, 45, 60, 90];
    public const MIN_AHEAD = 1800;          // не раньше чем через 30 минут
    public const MAX_AHEAD = 14 * 86400;    // не дальше двух недель
    public const MAX_UPCOMING = 2;
    public const JOIN_EARLY = 900;          // подключаться можно за 15 минут
    public const REMIND_BEFORE = 3600;      // напоминание за час

    public function __construct(private Kernel $kernel)
    {
    }

    private function team(): Team { return $this->kernel->container->get(Team::class); }
    private function provider(): CallProvider { return $this->kernel->container->get(CallProvider::class); }

    private function tz(): \DateTimeZone
    {
        return new \DateTimeZone((string) $this->kernel->config->get('app.timezone', 'Asia/Tashkent'));
    }

    // ================= расписание =================

    public function schedule(int $userId, string $date, string $time, int $duration, string $topic, string $note = '', ?int $now = null): Result
    {
        $now  = $now ?? time();
        $team = $this->team()->teamOf($userId);
        if ($team === null) {
            return Result::fail('no_team');
        }
        if ($team['status'] !== 'active') {
            return Result::fail('finished');
        }
        if ($team['leader_id'] !== $userId) {
            return Result::fail('not_leader');
        }

        $local = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $this->tz());
        $errs  = \DateTimeImmutable::getLastErrors();
        if ($local === false || (is_array($errs) && ($errs['warning_count'] > 0 || $errs['error_count'] > 0))) {
            return Result::fail('bad_time');
        }
        $starts = $local->getTimestamp();
        if ($starts < $now + self::MIN_AHEAD) {
            return Result::fail('too_soon');
        }
        if ($starts > $now + self::MAX_AHEAD) {
            return Result::fail('too_far');
        }

        $upcoming = $this->kernel->db()->all(
            "SELECT starts_at FROM calls_sessions WHERE team_id = ? AND status = 'scheduled' AND starts_at > ?",
            [(int) $team['id'], gmdate('c', $now)]
        );
        if (count($upcoming) >= self::MAX_UPCOMING) {
            return Result::fail('too_many', ['max' => self::MAX_UPCOMING]);
        }
        foreach ($upcoming as $u) {
            if ($this->localDate((string) $u['starts_at']) === $local->format('Y-m-d')) {
                return Result::fail('same_day');
            }
        }

        $note = trim(preg_replace('/\s+/u', ' ', strip_tags($note)) ?? '');
        $id   = $this->kernel->db()->insert('calls_sessions', [
            'team_id'      => (int) $team['id'],
            'created_by'   => $userId,
            'starts_at'    => gmdate('c', $starts),
            'duration_min' => in_array($duration, self::DURATIONS, true) ? $duration : 30,
            'topic'        => in_array($topic, self::TOPICS, true) ? $topic : 'week',
            'note'         => mb_substr($note, 0, 200),
            'provider'     => $this->provider()->name(),
            'created_at'   => gmdate('c', $now),
        ]);
        $this->answer($userId, $id, 'yes', $now);

        $session = $this->find($id);
        if ($this->say($team, 'calls.announce', $session)) {
            $this->kernel->db()->update('calls_sessions', ['announced_at' => gmdate('c', $now)], 'id = :id', ['id' => $id]);
        }
        $this->kernel->events->emit('calls.scheduled', ['team_id' => (int) $team['id'], 'user_id' => $userId, 'session_id' => $id]);

        return Result::ok(['session' => $this->view($this->find($id), $userId, $team, $now)]);
    }

    public function cancel(int $userId, int $sessionId, ?int $now = null): Result
    {
        $now     = $now ?? time();
        $session = $this->find($sessionId);
        $team    = $this->team()->teamOf($userId);
        if ($session === null || $team === null || (int) $session['team_id'] !== (int) $team['id']) {
            return Result::fail('not_found');
        }
        if ($team['leader_id'] !== $userId) {
            return Result::fail('not_leader');
        }
        if ($session['status'] !== 'scheduled' || strtotime((string) $session['starts_at']) <= $now) {
            return Result::fail('not_cancellable');
        }
        $this->kernel->db()->update('calls_sessions', ['status' => 'cancelled', 'cancelled_at' => gmdate('c', $now)], 'id = :id', ['id' => $sessionId]);
        $this->say($team, 'calls.cancelled_announce', $session);
        $this->kernel->events->emit('calls.cancelled', ['team_id' => (int) $team['id'], 'session_id' => $sessionId]);
        return Result::ok();
    }

    public function rsvp(int $userId, int $sessionId, string $answer, ?int $now = null): Result
    {
        $now = $now ?? time();
        if (!in_array($answer, self::ANSWERS, true)) {
            return Result::fail('bad_answer');
        }
        $session = $this->openSession($userId, $sessionId);
        if ($session === null) {
            return Result::fail('not_found');
        }
        if ($this->endsAt($session) <= $now) {
            return Result::fail('ended');
        }
        $this->answer($userId, $sessionId, $answer, $now);
        return Result::ok(['answer' => $answer]);
    }

    /** Ссылка на звонок — только в окно созвона: за 15 минут до начала и до конца. */
    public function join(int $userId, int $sessionId, ?int $now = null): Result
    {
        $now     = $now ?? time();
        $session = $this->openSession($userId, $sessionId);
        if ($session === null) {
            return Result::fail('not_found');
        }
        $starts = strtotime((string) $session['starts_at']);
        if ($now < $starts - self::JOIN_EARLY) {
            return Result::fail('not_yet', ['opens_at' => gmdate('c', $starts - self::JOIN_EARLY)]);
        }
        if ($now > $this->endsAt($session)) {
            return Result::fail('ended');
        }

        $room = $this->provider()->room($this->team()->teamOf($userId), $session);
        $this->kernel->db()->run(
            'INSERT INTO calls_rsvp (session_id, user_id, answer, joined_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(session_id, user_id) DO UPDATE SET joined_at = COALESCE(calls_rsvp.joined_at, excluded.joined_at), updated_at = excluded.updated_at',
            [$sessionId, $userId, 'yes', gmdate('c', $now), gmdate('c', $now)]
        );
        $this->kernel->events->emit('calls.joined', ['session_id' => $sessionId, 'user_id' => $userId, 'team_id' => (int) $session['team_id']]);
        return Result::ok(['url' => $room['url'], 'how' => $this->kernel->i18n->t($room['how']), 'provider' => $this->provider()->name()]);
    }

    // ================= экран =================

    public function overview(int $userId, ?int $now = null): array
    {
        $now  = $now ?? time();
        $team = $this->team()->teamOf($userId);
        if ($team === null) {
            return ['team' => null, 'upcoming' => [], 'past' => [], 'i_am_leader' => false];
        }
        $this->remindDue($now);

        $upcoming = [];
        foreach ($this->kernel->db()->all(
            "SELECT * FROM calls_sessions WHERE team_id = ? AND status = 'scheduled' ORDER BY starts_at",
            [(int) $team['id']]
        ) as $s) {
            if ($this->endsAt($s) > $now) {
                $upcoming[] = $this->view($s, $userId, $team, $now);
            }
        }

        $past = [];
        foreach ($this->kernel->db()->all(
            "SELECT * FROM calls_sessions WHERE team_id = ? AND status = 'scheduled' AND starts_at < ? ORDER BY starts_at DESC LIMIT 8",
            [(int) $team['id'], gmdate('c', $now)]
        ) as $s) {
            if ($this->endsAt($s) <= $now && count($past) < 5) {
                $past[] = [
                    'id'         => (int) $s['id'],
                    'topic'      => $s['topic'],
                    'local_date' => $this->localDate((string) $s['starts_at']),
                    'joined'     => (int) $this->kernel->db()->value('SELECT COUNT(*) FROM calls_rsvp WHERE session_id = ? AND joined_at IS NOT NULL', [(int) $s['id']], 0),
                ];
            }
        }

        return [
            'team'        => ['id' => $team['id'], 'name' => $team['name'], 'status' => $team['status']],
            'i_am_leader' => $team['leader_id'] === $userId && $team['status'] === 'active',
            'provider'    => $this->provider()->name(),
            'upcoming'    => $upcoming,
            'past'        => $past,
            'topics'      => self::TOPICS,
            'durations'   => self::DURATIONS,
        ];
    }

    private function view(array $s, int $userId, array $team, int $now): array
    {
        $names  = array_column($team['members'], 'name', 'user_id');
        $rsvp   = $this->kernel->db()->all('SELECT user_id, answer FROM calls_rsvp WHERE session_id = ?', [(int) $s['id']]);
        $mine   = null;
        $coming = [];
        foreach ($rsvp as $a) {
            if ((int) $a['user_id'] === $userId) {
                $mine = $a['answer'];
            }
            if ($a['answer'] === 'yes' && isset($names[(int) $a['user_id']])) {
                $coming[] = $names[(int) $a['user_id']];
            }
        }
        $starts = strtotime((string) $s['starts_at']);
        $local  = (new \DateTimeImmutable('@' . $starts))->setTimezone($this->tz());

        return [
            'id'          => (int) $s['id'],
            'starts_at'   => $s['starts_at'],
            'local_date'  => $local->format('Y-m-d'),
            'local_time'  => $local->format('H:i'),
            'duration'    => (int) $s['duration_min'],
            'topic'       => $s['topic'],
            'note'        => $s['note'],
            'my_answer'   => $mine,
            'coming'      => $coming,
            'can_join'    => $now >= $starts - self::JOIN_EARLY && $now <= $this->endsAt($s),
            'live'        => $now >= $starts && $now <= $this->endsAt($s),
        ];
    }

    // ================= напоминания =================

    /** За час до начала — в чат сквада и лично тем, кто ответил «приду» или «возможно». */
    public function remindDue(?int $now = null): int
    {
        $now = $now ?? time();
        $n   = 0;
        foreach ($this->kernel->db()->all(
            "SELECT * FROM calls_sessions WHERE status = 'scheduled' AND reminded_at IS NULL AND starts_at > ? AND starts_at <= ?",
            [gmdate('c', $now), gmdate('c', $now + self::REMIND_BEFORE)]
        ) as $s) {
            // Помечаем до отправки: два такта подряд не должны напомнить дважды.
            $claimed = $this->kernel->db()->run(
                'UPDATE calls_sessions SET reminded_at = ? WHERE id = ? AND reminded_at IS NULL',
                [gmdate('c', $now), (int) $s['id']]
            )->rowCount();
            if ($claimed === 0) {
                continue;
            }
            $lang = $this->teamLang((int) $s['created_by']) ?? 'ru';
            $text = $this->text('calls.reminder', $s, $lang);
            $this->team()->announce((int) $s['team_id'], $text);

            $notifier = $this->kernel->container->get(Notifier::class);
            foreach ($this->kernel->db()->all("SELECT user_id FROM calls_rsvp WHERE session_id = ? AND answer IN ('yes', 'maybe')", [(int) $s['id']]) as $a) {
                if ($this->team()->isMember((int) $s['team_id'], (int) $a['user_id'])) {
                    $notifier->send((int) $a['user_id'], $text);
                }
            }
            $n++;
        }
        return $n;
    }

    // ================= метрики =================

    /** Сколько человек подключается к прошедшему созвону, медиана за 30 дней. */
    public function attendance(string $today): array
    {
        $to   = strtotime($today . ' 23:59:59 UTC');
        $from = $to - 30 * 86400;
        $vals = [];
        foreach ($this->kernel->db()->all(
            "SELECT s.id, COUNT(r.joined_at) AS n FROM calls_sessions s LEFT JOIN calls_rsvp r ON r.session_id = s.id
             WHERE s.status = 'scheduled' AND s.starts_at BETWEEN ? AND ? GROUP BY s.id",
            [gmdate('c', $from), gmdate('c', min($to, time()))]
        ) as $row) {
            $vals[] = (int) $row['n'];
        }
        sort($vals);
        $c = count($vals);
        $median = $c === 0 ? null : ($c % 2 ? (float) $vals[intdiv($c, 2)] : ($vals[$c / 2 - 1] + $vals[$c / 2]) / 2);
        return ['value' => $median, 'n' => $c];
    }

    // ================= служебное =================

    private function find(int $id): ?array
    {
        return $this->kernel->db()->first('SELECT * FROM calls_sessions WHERE id = ?', [$id]);
    }

    /** Созвон своей команды, который не отменён. */
    private function openSession(int $userId, int $sessionId): ?array
    {
        $s = $this->find($sessionId);
        if ($s === null || $s['status'] !== 'scheduled' || !$this->team()->isMember((int) $s['team_id'], $userId)) {
            return null;
        }
        return $s;
    }

    private function endsAt(array $s): int
    {
        return strtotime((string) $s['starts_at']) + (int) $s['duration_min'] * 60;
    }

    private function answer(int $userId, int $sessionId, string $answer, int $now): void
    {
        $this->kernel->db()->run(
            'INSERT INTO calls_rsvp (session_id, user_id, answer, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(session_id, user_id) DO UPDATE SET answer = excluded.answer, updated_at = excluded.updated_at',
            [$sessionId, $userId, $answer, gmdate('c', $now)]
        );
    }

    private function localDate(string $utc): string
    {
        return (new \DateTimeImmutable($utc))->setTimezone($this->tz())->format('Y-m-d');
    }

    private function teamLang(int $userId): ?string
    {
        $team = $userId > 0 ? $this->team()->teamOf($userId) : null;
        return $team['lang'] ?? null;
    }

    private function say(array $team, string $key, array $session): bool
    {
        return $this->team()->announce((int) $team['id'], $this->text($key, $session, (string) $team['lang']));
    }

    private function text(string $key, array $s, string $lang): string
    {
        $local = (new \DateTimeImmutable((string) $s['starts_at']))->setTimezone($this->tz());
        $i18n  = $this->kernel->i18n;
        return $i18n->t($key, [
            'date'     => $local->format('d.m'),
            'time'     => $local->format('H:i'),
            'topic'    => $i18n->t('calls.topic.' . $s['topic'], [], $lang),
            'duration' => (int) $s['duration_min'],
            'note'     => $s['note'] !== '' ? ' — ' . $s['note'] : '',
        ], $lang);
    }
}
