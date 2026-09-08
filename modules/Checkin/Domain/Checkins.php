<?php
declare(strict_types=1);

namespace Modules\Checkin\Domain;

use App\Contracts\Planner;
use App\Kernel;
use App\Result;

/**
 * Ежедневный чек-ин — сердце продукта.
 *
 * Требование к сценарию: 40 секунд, ни одного обязательного поля
 * со свободным текстом. Всё, что можно посчитать за человека, здесь
 * не спрашивается.
 */
final class Checkins
{
    public const DONE   = ['yes', 'partial', 'no'];
    public const REASON = ['no_time', 'tired', 'sick', 'event', 'forgot', 'didnt_want'];
    public const EVENTS = ['toy', 'illness', 'trip', 'fasting', 'vacation'];

    /**
     * На сколько дней назад можно отметиться. Один день — компромисс:
     * позволяет закрыть вчерашний вечер утром, но не даёт задним числом
     * «дорисовать» неделю ради очков.
     */
    public const BACKFILL_DAYS = 1;

    public function __construct(
        private Kernel $kernel,
        private Week $week,
        private Streak $streak,
        private Recovery $recovery,
    ) {
    }

    public function find(int $userId, string $date): ?array
    {
        return $this->kernel->db()->first(
            'SELECT * FROM checkin_days WHERE user_id = ? AND date = ?',
            [$userId, $date]
        );
    }

    /**
     * Что показать на экране чек-ина: действие из плана, состояние дня,
     * прогресс недели, серия и щиты.
     */
    public function today(int $userId, ?string $date = null): array
    {
        $date = $this->normalizeDate($date);

        // Закрываем всё, что успело завершиться с прошлого визита.
        $closed = $this->streak->closeFinishedWeeks($userId, $date);
        foreach ($closed as $week) {
            $this->kernel->events->emit('week.closed', [
                'user_id'    => $userId,
                'week_start' => $week['week_start'],
                'kept'       => $week['kept'],
                'done_days'  => $week['done_days'],
                'checkins'   => $week['checkins'],
            ]);
        }

        $streakNow = $this->streak->current($userId);
        $stateInfo = $this->recovery->refresh($userId, $date, $streakNow);

        $weekStart = $this->week->startFor($userId, $date);
        $progress  = $this->streak->recompute($userId, $weekStart);

        /** @var Planner $planner */
        $planner = $this->kernel->container->get(Planner::class);
        $plan    = $planner->today($userId, $date);

        $existing = $this->find($userId, $date);

        return [
            'date'        => $date,
            'recorded'    => $existing !== null,
            'entry'       => $existing === null ? null : [
                'done'        => $existing['done'],
                'energy'      => $existing['energy'] !== null ? (int) $existing['energy'] : null,
                'mood'        => $existing['mood'] !== null ? (int) $existing['mood'] : null,
                'skip_reason' => $existing['skip_reason'],
                'value'       => $existing['value'],
                'note'        => $existing['note'],
            ],
            'plan'        => $plan,
            'state'       => $stateInfo['state'],
            'gap_days'    => $stateInfo['gap'],
            'reduced'     => in_array($stateInfo['state'], ['attention', 'recovery'], true),
            'week'        => [
                'start'     => $weekStart,
                'done_days' => $progress['done_days'],
                'checkins'  => $progress['checkins'],
                'norm_days' => $progress['norm']['days'],
                'excused'   => $progress['norm']['excused'],
                'kept'      => $progress['kept'],
                'days'      => $this->weekDayStates($userId, $weekStart),
            ],
            'streak'      => $streakNow,
            'shields'     => $this->streak->shieldsLeft($userId, $date),
            'events'      => $this->activeEvents($userId, $date),
        ];
    }

    /** @return array<int, array{date: string, done: ?string, excused: bool, future: bool}> */
    private function weekDayStates(int $userId, string $weekStart): array
    {
        $days  = $this->week->days($weekStart);
        $today = gmdate('Y-m-d');

        $rows = $this->kernel->db()->all(
            'SELECT date, done FROM checkin_days WHERE user_id = ? AND date >= ? AND date <= ?',
            [$userId, $days[0], $days[6]]
        );
        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r['date']] = $r['done'];
        }

        $events = $this->kernel->db()->all(
            'SELECT date_from, date_to FROM checkin_events
             WHERE user_id = ? AND date_to >= ? AND date_from <= ?',
            [$userId, $days[0], $days[6]]
        );

        $out = [];
        foreach ($days as $d) {
            $excused = false;
            foreach ($events as $e) {
                if ($d >= $e['date_from'] && $d <= $e['date_to']) {
                    $excused = true;
                    break;
                }
            }
            $out[] = [
                'date'    => $d,
                'done'    => $byDate[$d] ?? null,
                'excused' => $excused,
                'future'  => $d > $today,
            ];
        }
        return $out;
    }

    /**
     * Запись чек-ина.
     *
     * @param array $input done, energy, mood, skip_reason, value, note, date
     */
    public function record(int $userId, array $input): Result
    {
        $date = $this->normalizeDate($input['date'] ?? null);

        $limit = gmdate('Y-m-d', time() - self::BACKFILL_DAYS * 86400);
        if ($date < $limit) {
            return Result::fail('too_old', ['oldest' => $limit]);
        }
        if ($date > gmdate('Y-m-d')) {
            return Result::fail('in_future');
        }

        $done = (string) ($input['done'] ?? '');
        if (!in_array($done, self::DONE, true)) {
            return Result::fail('bad_done');
        }

        $reason = (string) ($input['skip_reason'] ?? '');
        if ($done === 'no' && !in_array($reason, self::REASON, true)) {
            return Result::fail('reason_required');
        }
        if ($done !== 'no') {
            $reason = '';
        }

        $energy = $this->scale($input['energy'] ?? null);
        $mood   = $this->scale($input['mood'] ?? null);

        $existing = $this->find($userId, $date);
        $first    = $existing === null;

        // Возврат фиксируем до записи: после неё разрыв уже закрыт.
        $gap = $first ? $this->recovery->registerReturn($userId, $date) : 0;

        /** @var Planner $planner */
        $planner   = $this->kernel->container->get(Planner::class);
        $planToday = $planner->today($userId, $date);

        $data = [
            'day_number'  => $planToday['day'] ?? null,
            'done'        => $done,
            'energy'      => $energy,
            'mood'        => $mood,
            'skip_reason' => $reason !== '' ? $reason : null,
            'value'       => isset($input['value']) && is_numeric($input['value']) ? (float) $input['value'] : null,
            'note'        => mb_substr(trim((string) ($input['note'] ?? '')), 0, 300) ?: null,
        ];

        if ($first) {
            $this->kernel->db()->insert('checkin_days', $data + [
                'user_id'    => $userId,
                'date'       => $date,
                'created_at' => gmdate('c'),
            ]);
        } else {
            $this->kernel->db()->update(
                'checkin_days', $data,
                'user_id = :uid AND date = :d',
                ['uid' => $userId, 'd' => $date]
            );
        }

        $weekStart = $this->week->startFor($userId, $date);
        $progress  = $this->streak->recompute($userId, $weekStart);
        $streakNow = $this->streak->current($userId);
        $this->recovery->refresh($userId, gmdate('Y-m-d'), $streakNow);

        // Очки, уведомления и всё прочее навешиваются на это событие.
        // Сам модуль чек-ина не знает, слушает ли его кто-нибудь.
        $this->kernel->events->emit('checkin.recorded', [
            'user_id'    => $userId,
            'date'       => $date,
            'done'       => $done,
            'first_time' => $first,
            'returned'   => $gap > 0,
            'gap_days'   => $gap,
            'week'       => $progress,
            'day_number' => $data['day_number'],
        ]);

        return Result::ok([
            'date'     => $date,
            'first'    => $first,
            'returned' => $gap > 0,
            'gap_days' => $gap,
            'week'     => [
                'done_days' => $progress['done_days'],
                'checkins'  => $progress['checkins'],
                'norm_days' => $progress['norm']['days'],
                'kept'      => $progress['kept'],
            ],
            'streak'   => $streakNow,
        ]);
    }

    /** Отметка события-помехи: тўй, болезнь, поездка, пост, отпуск. */
    public function markEvent(int $userId, array $input): Result
    {
        $type = (string) ($input['type'] ?? '');
        if (!in_array($type, self::EVENTS, true)) {
            return Result::fail('bad_event_type');
        }

        $from = $this->normalizeDate($input['date_from'] ?? null);
        $to   = $this->normalizeDate($input['date_to'] ?? $from);
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        // Ограничение длины: событие на месяц — это уже не помеха,
        // а смена обстоятельств, и её обрабатывает пересборка плана.
        $span = (strtotime($to) - strtotime($from)) / 86400;
        if ($span > 14) {
            return Result::fail('event_too_long', ['max_days' => 14]);
        }

        $id = $this->kernel->db()->insert('checkin_events', [
            'user_id'    => $userId,
            'type'       => $type,
            'date_from'  => $from,
            'date_to'    => $to,
            'note'       => mb_substr(trim((string) ($input['note'] ?? '')), 0, 200) ?: null,
            'created_at' => gmdate('c'),
        ]);

        $this->kernel->events->emit('checkin.event_marked', [
            'user_id' => $userId, 'type' => $type, 'from' => $from, 'to' => $to,
        ]);

        // Норма недели могла измениться — пересчитываем.
        $this->streak->recompute($userId, $this->week->startFor($userId, $from));

        return Result::ok(['id' => $id, 'type' => $type, 'from' => $from, 'to' => $to]);
    }

    /** @return array<int, array<string, mixed>> */
    public function activeEvents(int $userId, string $date): array
    {
        return $this->kernel->db()->all(
            'SELECT id, type, date_from, date_to, note FROM checkin_events
             WHERE user_id = ? AND date_to >= ? ORDER BY date_from LIMIT 5',
            [$userId, $date]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function history(int $userId, int $days = 30): array
    {
        $from = gmdate('Y-m-d', time() - $days * 86400);
        return $this->kernel->db()->all(
            'SELECT date, done, energy, mood, skip_reason FROM checkin_days
             WHERE user_id = ? AND date >= ? ORDER BY date DESC',
            [$userId, $from]
        );
    }

    private function scale(mixed $raw): ?int
    {
        if (!is_numeric($raw)) {
            return null;
        }
        $v = (int) $raw;
        return $v >= 1 && $v <= 5 ? $v : null;
    }

    private function normalizeDate(mixed $raw): string
    {
        $date = is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : gmdate('Y-m-d');
        return $date;
    }
}
