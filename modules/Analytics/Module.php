<?php
declare(strict_types=1);

namespace Modules\Analytics;

use App\BaseModule;
use App\Container;
use App\Kernel;
use Modules\Analytics\Domain\Dashboard;
use Modules\Analytics\Domain\Recorder;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(Recorder::class, static fn() => new Recorder($kernel), 'analytics');
        $container->singleton(Dashboard::class, static fn() => new Dashboard($kernel), 'analytics');
    }

    public function boot(Kernel $kernel): void
    {
        $rec = static fn(): Recorder => $kernel->container->get(Recorder::class);
        $on  = static function (string $event, callable $write) use ($kernel): void {
            $kernel->events->on($event, static function (array $p) use ($write): array {
                $write($p);
                return $p;
            }, 'analytics', 900);   // последними: к этому моменту всё уже записано владельцами
        };

        $on('user.registered', static fn($p) => $rec()->record((int) ($p['user']['id'] ?? 0) ?: null, 'registered'));
        $on('onboarding.completed', static fn($p) => $rec()->record((int) $p['user_id'], 'onboarding_completed', null, null, ['tier' => $p['profile']['tier'] ?? null]));
        $on('onboarding.rejected', static fn($p) => $rec()->record((int) $p['user_id'], 'onboarding_rejected', null, null, ['reason' => $p['reason'] ?? null]));
        $on('plan.built', static fn($p) => $rec()->record((int) $p['user_id'], 'plan_built'));
        $on('checkin.recorded', static fn($p) => $rec()->upsertDaily((int) $p['user_id'], 'checkin', (string) $p['date'],
            match ($p['done'] ?? '') { 'yes' => 1.0, 'partial' => 0.5, default => 0.0 },
            ['reason' => $p['skip_reason'] ?? null, 'day' => $p['day_number'] ?? null]));
        $on('user.returned', static fn($p) => $rec()->record((int) $p['user_id'], 'returned', (string) ($p['date'] ?? gmdate('Y-m-d')), (float) ($p['gap_days'] ?? 0)));
        $on('week.closed', static fn($p) => $rec()->record((int) $p['user_id'], 'week_closed', (string) ($p['week_start'] ?? gmdate('Y-m-d')),
            !empty($p['kept']) && (int) ($p['checkins'] ?? 0) >= 3 ? 1.0 : 0.0));
        $on('user.state_changed', static fn($p) => $rec()->record((int) $p['user_id'], 'state_changed', null, null, ['from' => $p['from'] ?? null, 'to' => $p['to'] ?? null]));
        $on('billing.season_activated', static fn($p) => $rec()->record((int) $p['user_id'], 'season_activated', null, null, ['tariff' => $p['tariff'] ?? null, 'source' => $p['source'] ?? null]));
        $on('squad.started', static fn($p) => $rec()->record(null, 'squad_started', (string) ($p['start_date'] ?? gmdate('Y-m-d')), (float) count((array) ($p['user_ids'] ?? [])), ['squad' => $p['squad_id'] ?? null]));
        $on('squad.disbanded', static fn($p) => $rec()->record(null, 'squad_disbanded', null, null, ['squad' => $p['squad_id'] ?? null]));
        $on('coach.reply', static fn($p) => $rec()->record((int) $p['user_id'], 'coach_reply', null, null, ['kind' => $p['kind'] ?? null, 'source' => $p['source'] ?? null]));
        $on('safety.alert', static fn($p) => $rec()->record(null, 'safety_alert', null, null, ['source' => $p['source'] ?? null]));

        // Удаление аккаунта: события остаются для агрегатов, человек — нет.
        \App\UserData::register($kernel, 'analytics', 'analytics_', [
            'analytics_events' => ['export' => false, 'erase' => 'UPDATE analytics_events SET user_id = NULL WHERE user_id = ?'],
        ]);
    }
}
