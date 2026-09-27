<?php
declare(strict_types=1);

namespace Modules\Analytics\Domain;

use App\Kernel;

/** Пишет факты в свой журнал. Никаких текстов и имён — только коды и числа. */
final class Recorder
{
    public function __construct(private Kernel $kernel)
    {
    }

    public function record(?int $userId, string $name, ?string $day = null, ?float $value = null, array $meta = []): void
    {
        $this->kernel->db()->insert('analytics_events', [
            'user_id'    => $userId,
            'name'       => $name,
            'day'        => $day ?? gmdate('Y-m-d'),
            'value'      => $value,
            'meta'       => $meta ? json_encode($meta) : null,
            'created_at' => gmdate('c'),
        ]);
    }

    /** Одна запись на человека, событие и день: правка чек-ина обновляет, а не дублирует. */
    public function upsertDaily(int $userId, string $name, string $day, ?float $value, array $meta = []): void
    {
        $id = $this->kernel->db()->value(
            'SELECT id FROM analytics_events WHERE user_id = ? AND name = ? AND day = ?',
            [$userId, $name, $day]
        );
        if ($id === null) {
            $this->record($userId, $name, $day, $value, $meta);
            return;
        }
        $this->kernel->db()->update('analytics_events', [
            'value' => $value,
            'meta'  => $meta ? json_encode($meta) : null,
        ], 'id = :id', ['id' => (int) $id]);
    }
}
