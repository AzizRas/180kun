<?php
declare(strict_types=1);

namespace Modules\Checkin\Domain;

use App\Contracts\Planner;
use App\Kernel;

/**
 * Границы недели и её норма.
 *
 * Решение (принято автономно, обосновано ниже): неделя считается от даты
 * старта плана, а не от календарного понедельника. Причина — у человека
 * сезон начинается в его день, и «неделя 1» должна совпадать с первой
 * неделей плана, иначе первая неделя окажется огрызком в два дня и
 * норма 4 из 7 в ней недостижима. Если плана нет, откатываемся на
 * календарную неделю с понедельника.
 */
final class Week
{
    public const DEFAULT_NORM_DAYS     = 4;   // Р-09
    public const DEFAULT_NORM_CHECKINS = 3;
    public const MIN_NORM_DAYS         = 2;   // ниже не опускаемся даже при событиях

    public function __construct(private Kernel $kernel)
    {
    }

    /** Начало недели, в которую попадает дата. */
    public function startFor(int $userId, string $date): string
    {
        $plan = $this->plan($userId);

        if ($plan !== null && !empty($plan['start_date'])) {
            $start = strtotime($plan['start_date'] . ' 00:00:00 UTC');
            $point = strtotime($date . ' 00:00:00 UTC');

            if ($start !== false && $point !== false && $point >= $start) {
                $dayIndex = (int) floor(($point - $start) / 86400);
                $weekIdx  = intdiv($dayIndex, 7);
                return gmdate('Y-m-d', $start + $weekIdx * 7 * 86400);
            }
        }

        // Запасной путь: календарная неделя с понедельника.
        $ts = strtotime($date . ' 00:00:00 UTC');
        $ts = $ts === false ? time() : $ts;
        $dow = (int) gmdate('N', $ts);   // 1 = понедельник
        return gmdate('Y-m-d', $ts - ($dow - 1) * 86400);
    }

    /** @return array<int, string> семь дат недели */
    public function days(string $weekStart): array
    {
        $ts   = strtotime($weekStart . ' 00:00:00 UTC') ?: time();
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = gmdate('Y-m-d', $ts + $i * 86400);
        }
        return $days;
    }

    /**
     * Норма недели. Берётся из плана, если он есть, и снижается на число
     * дней, накрытых отмеченным событием: человек, у которого посреди
     * недели тўй и два дня в дороге, не должен выходить из графика
     * из-за обстоятельств, о которых он честно предупредил.
     *
     * @return array{days: int, checkins: int, excused: int, source: string}
     */
    public function norm(int $userId, string $weekStart): array
    {
        $days     = self::DEFAULT_NORM_DAYS;
        $checkins = self::DEFAULT_NORM_CHECKINS;
        $source   = 'default';

        $plan = $this->plan($userId);
        if ($plan !== null) {
            foreach ($plan['weeks'] ?? [] as $w) {
                $from = $this->dateOfPlanDay($plan, (int) $w['from_day']);
                if ($from === $weekStart) {
                    $days     = (int) $w['norm_days'];
                    $checkins = (int) $w['norm_checkins'];
                    $source   = 'plan';
                    break;
                }
            }
        }

        $excused = $this->excusedDays($userId, $weekStart);
        $days    = max(self::MIN_NORM_DAYS, $days - $excused);

        return ['days' => $days, 'checkins' => $checkins, 'excused' => $excused, 'source' => $source];
    }

    /** Сколько дней недели накрыты отмеченным событием. */
    public function excusedDays(int $userId, string $weekStart): int
    {
        $days  = $this->days($weekStart);
        $first = $days[0];
        $last  = $days[6];

        $events = $this->kernel->db()->all(
            'SELECT date_from, date_to FROM checkin_events
             WHERE user_id = ? AND date_to >= ? AND date_from <= ?',
            [$userId, $first, $last]
        );
        if ($events === []) {
            return 0;
        }

        $covered = [];
        foreach ($events as $e) {
            foreach ($days as $d) {
                if ($d >= $e['date_from'] && $d <= $e['date_to']) {
                    $covered[$d] = true;
                }
            }
        }
        return count($covered);
    }

    private function dateOfPlanDay(array $plan, int $dayNumber): string
    {
        $start = strtotime((string) $plan['start_date'] . ' 00:00:00 UTC') ?: time();
        return gmdate('Y-m-d', $start + ($dayNumber - 1) * 86400);
    }

    private function plan(int $userId): ?array
    {
        /** @var Planner $planner */
        $planner = $this->kernel->container->get(Planner::class);
        return $planner->currentPlan($userId);
    }
}
