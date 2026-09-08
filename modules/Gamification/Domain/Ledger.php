<?php
declare(strict_types=1);

namespace Modules\Gamification\Domain;

use App\Contracts\Gamification;
use App\Kernel;

/**
 * Начисление очков. Решение Р-15: награждаем регулярность и возвращение,
 * а не объём. Возврат после срыва стоит 100 — дороже идеальной недели,
 * потому что это самое трудное действие в продукте.
 *
 * Накрутка невозможна по построению: у каждого начисления есть повод и
 * ссылка (дата, неделя, глава), и пара «повод + ссылка» уникальна.
 * Повторный чек-ин за тот же день второй раз очков не даст.
 */
final class Ledger implements Gamification
{
    /** Таблица начислений Р-15. */
    public const RATES = [
        'checkin'    => 10,    // любой честный чек-ин, включая «не сделал»
        'action'     => 15,    // выполненное действие дня
        'action_half'=> 8,     // частично выполненное
        'week_kept'  => 80,    // закрытая недельная норма
        'comeback'   => 100,   // возврат после 3+ дней — дороже всего
        'chapter'    => 400,   // завершённая глава
        'onboarding' => 50,    // пройден онбординг и построен план
    ];

    /**
     * Потолок начислений за сутки. Считается по дате начисления и
     * защищает от любых будущих механик, которые начнут раздавать очки
     * пачками. Возврат плюс полный день укладывается: 100 + 10 + 15.
     */
    public const DAILY_CAP = 150;

    /** XP на один уровень. 180-й уровень достигается примерно к концу сезона. */
    public const XP_PER_LEVEL = 40;

    public function __construct(private Kernel $kernel)
    {
    }

    public function award(int $userId, string $reason, int $amount = 0): int
    {
        return $this->give($userId, $reason, gmdate('Y-m-d'), $amount);
    }

    /**
     * Начисление с явной ссылкой. Возвращает фактически начисленное
     * количество: 0 означает «уже начисляли» или «упёрлись в потолок».
     */
    public function give(int $userId, string $reason, string $ref, int $amount = 0): int
    {
        $amount = $amount > 0 ? $amount : (self::RATES[$reason] ?? 0);
        if ($amount <= 0) {
            return 0;
        }

        $today = gmdate('Y-m-d');
        $spent = (int) $this->kernel->db()->value(
            "SELECT COALESCE(SUM(amount), 0) FROM gami_ledger
             WHERE user_id = ? AND created_at LIKE ?",
            [$userId, $today . '%'],
            0
        );

        $room = self::DAILY_CAP - $spent;
        if ($room <= 0) {
            return 0;
        }
        $amount = min($amount, $room);

        try {
            $this->kernel->db()->insert('gami_ledger', [
                'user_id'    => $userId,
                'reason'     => $reason,
                'ref'        => $ref,
                'amount'     => $amount,
                'created_at' => gmdate('c'),
            ]);
        } catch (\Throwable) {
            // Нарушение уникальности: за этот повод уже начисляли.
            return 0;
        }

        $this->recalculate($userId);

        $this->kernel->events->emit('xp.awarded', [
            'user_id' => $userId, 'reason' => $reason, 'amount' => $amount, 'ref' => $ref,
        ]);

        return $amount;
    }

    private function recalculate(int $userId): void
    {
        $xp = (int) $this->kernel->db()->value(
            'SELECT COALESCE(SUM(amount), 0) FROM gami_ledger WHERE user_id = ?',
            [$userId],
            0
        );
        $level = self::levelFor($xp);

        $row = $this->kernel->db()->first('SELECT * FROM gami_profile WHERE user_id = ?', [$userId]);

        if ($row === null) {
            $this->kernel->db()->insert('gami_profile', [
                'user_id' => $userId, 'xp' => $xp, 'level' => $level, 'updated_at' => gmdate('c'),
            ]);
            return;
        }

        $this->kernel->db()->update(
            'gami_profile',
            ['xp' => $xp, 'level' => $level, 'updated_at' => gmdate('c')],
            'user_id = :uid',
            ['uid' => $userId]
        );

        if ($level > (int) $row['level']) {
            $this->kernel->events->emit('level.up', [
                'user_id' => $userId, 'from' => (int) $row['level'], 'to' => $level,
            ]);
        }
    }

    public static function levelFor(int $xp): int
    {
        return max(1, min(180, intdiv($xp, self::XP_PER_LEVEL) + 1));
    }

    public function profile(int $userId): array
    {
        $row = $this->kernel->db()->first('SELECT * FROM gami_profile WHERE user_id = ?', [$userId]);
        $xp  = (int) ($row['xp'] ?? 0);

        // Серией и щитами владеет модуль Checkin. Спрашиваем событием,
        // а не запросом к чужой таблице: правило проекта — модуль читает
        // только свои таблицы. Если Checkin выключен, придут нули.
        $extras = $this->kernel->events->emit('gamification.extras', [
            'user_id'     => $userId,
            'streak'      => 0,
            'best_streak' => 0,
            'shields'     => 0,
        ]);

        return [
            'xp'          => $xp,
            'level'       => (int) ($row['level'] ?? 1),
            'next_level'  => min(180, self::levelFor($xp) + 1),
            'xp_to_next'  => max(0, self::levelFor($xp) * self::XP_PER_LEVEL - $xp),
            'streak'      => (int) ($extras['streak'] ?? 0),
            'best_streak' => (int) ($extras['best_streak'] ?? 0),
            'shields'     => (int) ($extras['shields'] ?? 0),
        ];
    }

    /** @return array<int, array<string, mixed>> последние начисления */
    public function recent(int $userId, int $limit = 20): array
    {
        return $this->kernel->db()->all(
            'SELECT reason, ref, amount, created_at FROM gami_ledger
             WHERE user_id = ? ORDER BY id DESC LIMIT ?',
            [$userId, $limit]
        );
    }
}
