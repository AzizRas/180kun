<?php
declare(strict_types=1);

namespace Modules\Checkin\Domain;

use App\Kernel;

/**
 * Четыре сценария пропуска (§ 06 досье) и фиксация возвратов.
 *
 * Главный принцип продукта: проигрыш не в падении, а в том, что
 * перестал возвращаться. Поэтому здесь нет ни одного наказания —
 * только сужение требований и смена тона.
 *
 *   active     0–1 день без чек-ина   ничего не происходит
 *   attention  2–4 дня                мягкий вопрос и уменьшенное действие
 *   recovery   5–10 дней              одно микродействие, рейтинги скрыты
 *   dormant    больше 10 дней         всё замирает, место придержано
 */
final class Recovery
{
    public const ATTENTION_FROM = 2;
    public const RECOVERY_FROM  = 5;
    public const DORMANT_FROM   = 11;

    /** С какого разрыва возвращение считается настоящим возвратом. */
    public const RETURN_GAP = 3;

    public function __construct(private Kernel $kernel)
    {
    }

    public static function stateForGap(int $gapDays): string
    {
        if ($gapDays >= self::DORMANT_FROM)  { return 'dormant'; }
        if ($gapDays >= self::RECOVERY_FROM) { return 'recovery'; }
        if ($gapDays >= self::ATTENTION_FROM){ return 'attention'; }
        return 'active';
    }

    public function row(int $userId): ?array
    {
        return $this->kernel->db()->first('SELECT * FROM checkin_state WHERE user_id = ?', [$userId]);
    }

    /** Разрыв в днях между последним чек-ином и датой. */
    public function gapDays(int $userId, ?string $today = null): int
    {
        $today = $today ?? gmdate('Y-m-d');
        $last  = $this->kernel->db()->value(
            'SELECT MAX(date) FROM checkin_days WHERE user_id = ?',
            [$userId]
        );
        if ($last === null) {
            return 0;   // ещё ни одного чек-ина — это не пропуск
        }

        $a = strtotime((string) $last . ' 00:00:00 UTC');
        $b = strtotime($today . ' 00:00:00 UTC');
        if ($a === false || $b === false) {
            return 0;
        }
        return max(0, (int) floor(($b - $a) / 86400));
    }

    /**
     * Пересчёт состояния. Возвращает [состояние, изменилось ли].
     *
     * @return array{state: string, gap: int, changed: bool, previous: ?string}
     */
    public function refresh(int $userId, ?string $today = null, int $streak = 0): array
    {
        $today = $today ?? gmdate('Y-m-d');
        $gap   = $this->gapDays($userId, $today);
        $state = self::stateForGap($gap);

        $row      = $this->row($userId);
        $previous = $row['state'] ?? null;
        $last     = $this->kernel->db()->value('SELECT MAX(date) FROM checkin_days WHERE user_id = ?', [$userId]);
        $best     = max((int) ($row['best_streak'] ?? 0), $streak);

        $data = [
            'state'       => $state,
            'last_date'   => $last,
            'gap_days'    => $gap,
            'streak'      => $streak,
            'best_streak' => $best,
            'updated_at'  => gmdate('c'),
        ];

        if ($row === null) {
            $this->kernel->db()->insert('checkin_state', $data + ['user_id' => $userId]);
        } else {
            $this->kernel->db()->update('checkin_state', $data, 'user_id = :uid', ['uid' => $userId]);
        }

        $changed = $previous !== null && $previous !== $state;
        if ($changed) {
            $this->kernel->events->emit('user.state_changed', [
                'user_id' => $userId, 'from' => $previous, 'to' => $state, 'gap_days' => $gap,
            ]);
        }

        return ['state' => $state, 'gap' => $gap, 'changed' => $changed, 'previous' => $previous];
    }

    /**
     * Фиксирует возврат, если чек-ин пришёл после разрыва в 3+ дня.
     * Это источник Return Rate — главной метрики продукта.
     *
     * @return int длина разрыва, 0 если возврата не было
     */
    public function registerReturn(int $userId, string $date): int
    {
        $last = $this->kernel->db()->value(
            'SELECT MAX(date) FROM checkin_days WHERE user_id = ? AND date < ?',
            [$userId, $date]
        );
        if ($last === null) {
            return 0;
        }

        $a   = strtotime((string) $last . ' 00:00:00 UTC');
        $b   = strtotime($date . ' 00:00:00 UTC');
        $gap = ($a === false || $b === false) ? 0 : (int) floor(($b - $a) / 86400);

        if ($gap < self::RETURN_GAP) {
            return 0;
        }

        // Один разрыв — одна запись. Иначе повторный вызов (правка того же
        // дня, перезапрос экрана) начислил бы возврат второй раз, а Return
        // Rate — главная метрика продукта, её нельзя раздувать.
        $already = $this->kernel->db()->value(
            'SELECT 1 FROM checkin_returns WHERE user_id = ? AND gap_from = ?',
            [$userId, (string) $last]
        );
        if ($already !== null) {
            return 0;
        }

        $this->kernel->db()->insert('checkin_returns', [
            'user_id'     => $userId,
            'gap_days'    => $gap,
            'gap_from'    => (string) $last,
            'returned_at' => gmdate('c'),
        ]);

        $this->kernel->events->emit('user.returned', [
            'user_id' => $userId, 'gap_days' => $gap, 'date' => $date,
        ]);

        return $gap;
    }

    /**
     * Return Rate по всей базе: доля разрывов в 3+ дня, которые
     * закончились возвращением в течение 7 дней.
     *
     * @return array{gaps: int, returns: int, rate: float}
     */
    public function returnRate(): array
    {
        $returns = (int) $this->kernel->db()->value(
            'SELECT COUNT(*) FROM checkin_returns WHERE gap_days <= 10',
            [],
            0
        );

        // Разрывы, которые ещё не закончились возвращением: люди в
        // состояниях recovery и dormant прямо сейчас.
        $open = (int) $this->kernel->db()->value(
            "SELECT COUNT(*) FROM checkin_state WHERE state IN ('recovery','dormant')",
            [],
            0
        );

        $gaps = $returns + $open;

        return [
            'gaps'    => $gaps,
            'returns' => $returns,
            'rate'    => $gaps > 0 ? round($returns / $gaps * 100, 1) : 0.0,
        ];
    }
}
