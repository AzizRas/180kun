<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Request;

/**
 * Безопасные заглушки.
 *
 * Регистрируются ядром как fallback для каждого контракта. Если модуль,
 * который должен реализовать контракт, выключен или сломан — приложение
 * получает заглушку и продолжает работать в урезанном режиме вместо падения.
 *
 * Это и есть техническая гарантия обещания «убрал модуль — ничего не слетело».
 */
final class NullAuth implements Auth
{
    public function currentUser(Request $request): ?array { return null; }
    public function userById(int $id): ?array { return null; }
    public function issueSession(int $userId): ?array { return null; }
    public function revokeSession(string $token): void {}
}

final class NullCoach implements Coach
{
    public function replyToCheckin(array $context): array
    {
        return [
            'text'   => $this->template($context),
            'action' => null,
            'source' => 'template',
        ];
    }

    public function weeklyReview(array $context): array
    {
        return [
            'text'   => 'Итоги недели готовы. Разбор появится, когда будет подключён тренер.',
            'action' => null,
            'source' => 'template',
        ];
    }

    public function classifyState(array $context): string
    {
        // Грубая, но честная эвристика без ИИ.
        $missed = (int) ($context['missed_days'] ?? 0);
        if ($missed >= 7)  { return 'quit'; }
        if ($missed >= 3)  { return 'drift'; }
        if (($context['energy_avg'] ?? 5) <= 2) { return 'overload'; }
        return 'steady';
    }

    public function isLive(): bool { return false; }

    private function template(array $context): string
    {
        $done = (bool) ($context['done'] ?? false);
        return $done
            ? 'Отмечено. Завтра то же самое действие.'
            : 'Отмечено. Завтра начнём с уменьшенной версии.';
    }
}

/**
 * Доставка сообщений через шину событий.
 *
 * Это не совсем заглушка: реализация всегда одна, а каналы (Telegram, SMS)
 * подключаются модулями через подписку на события. Если ни один канал не
 * включён, отправка честно возвращает false и ничего не ломает —
 * ровно то поведение, которое нужно от заглушки.
 *
 * Так решается конфликт «два модуля хотят реализовать один контракт»:
 * они не конкурируют за контракт, а добавляют себя как каналы.
 */
final class EventNotifier implements Notifier
{
    public function __construct(private \App\Events $events)
    {
    }

    public function send(int $userId, string $text, array $options = []): bool
    {
        $result = $this->events->emit('notify.send', [
            'user_id'   => $userId,
            'text'      => $text,
            'options'   => $options,
            'channel'   => $options['channel'] ?? 'auto',
            'delivered' => false,
            'via'       => null,
        ]);
        return (bool) ($result['delivered'] ?? false);
    }

    public function sendCode(string $channel, string $address, string $code): bool
    {
        $result = $this->events->emit('notify.code', [
            'channel'   => $channel,
            'address'   => $address,
            'code'      => $code,
            'delivered' => false,
            'via'       => null,
        ]);
        return (bool) ($result['delivered'] ?? false);
    }

    public function channels(): array
    {
        $result = $this->events->emit('notify.channels', ['channels' => []]);
        return array_values(array_unique((array) ($result['channels'] ?? [])));
    }
}

final class NullWearable implements Wearable
{
    public function dayMetrics(int $userId, string $date): array
    {
        return ['steps' => null, 'active_min' => null, 'rhr' => null, 'sleep_min' => null, 'source' => 'none'];
    }

    public function baseline(int $userId, int $days = 7): array
    {
        return ['steps_med' => null, 'rhr' => null, 'sleep_avg' => null, 'days' => 0, 'source' => 'none'];
    }

    public function isConnected(int $userId): bool { return false; }
}

final class NullPlanner implements Planner
{
    public function currentPlan(int $userId): ?array { return null; }
    public function today(int $userId, ?string $date = null): ?array { return null; }
    public function build(int $userId, array $profile, array $baseline): ?array { return null; }
    public function hasPlan(int $userId): bool { return false; }
}

final class NullGamification implements Gamification
{
    public function award(int $userId, string $reason, int $amount = 0): int { return 0; }
    public function profile(int $userId): array
    {
        return ['xp' => 0, 'level' => 0, 'streak' => 0, 'shields' => 0];
    }
}
