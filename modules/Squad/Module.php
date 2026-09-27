<?php
declare(strict_types=1);

namespace Modules\Squad;

use App\BaseModule;
use App\Container;
use App\Kernel;
use Modules\Squad\Domain\Chat;
use Modules\Squad\Domain\Leadership;
use Modules\Squad\Domain\Pool;
use Modules\Squad\Domain\Scoring;
use Modules\Squad\Domain\Squads;
use Modules\Squad\Domain\TeamView;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(Pool::class, static fn() => new Pool($kernel), 'squad');
        $container->singleton(Leadership::class, static fn() => new Leadership($kernel), 'squad');
        $container->singleton(Scoring::class, static fn() => new Scoring($kernel), 'squad');
        $container->singleton(Chat::class, static fn() => new Chat($kernel), 'squad');
        $container->singleton(Squads::class, static fn(Container $c) => new Squads(
            $kernel,
            $c->get(Pool::class),
            $c->get(Leadership::class),
            $c->get(Scoring::class),
            $c->get(Chat::class),
        ), 'squad');
        $container->singleton(TeamView::class, static fn(Container $c) => new TeamView($kernel, $c->get(Squads::class), $c->get(Chat::class)), 'squad');
        $container->singleton(\App\Contracts\Team::class, static fn(Container $c) => $c->get(TeamView::class), 'squad');
    }

    public function boot(Kernel $kernel): void
    {
        // Онбординг завершён — человек сам попадает в пул ближайшей волны.
        // Онбординг про сквады ничего не знает: он просто сообщил, что готово.
        $kernel->events->on('onboarding.completed', static function (array $p) use ($kernel): array {
            $userId = (int) ($p['user_id'] ?? 0);
            if ($userId > 0) {
                $kernel->container->get(Pool::class)->join(
                    $userId,
                    (array) ($p['profile'] ?? []),
                    (array) ($p['baseline'] ?? [])
                );
            }
            return $p;
        }, 'squad');

        // Вернулся после срыва — место в скваде ещё за ним, снимаем паузу сразу.
        $kernel->events->on('user.returned', static function (array $p) use ($kernel): array {
            $userId = (int) ($p['user_id'] ?? 0);
            if ($userId > 0) {
                $kernel->container->get(Squads::class)->onUserReturned($userId, (string) ($p['date'] ?? gmdate('Y-m-d')));
            }
            return $p;
        }, 'squad');

        // Сообщение в группе сквада. Модуль Telegram разобрал апдейт и узнал
        // человека; мы считаем активность и отвечаем на /bind.
        $kernel->events->on('telegram.group_message', static function (array $p) use ($kernel): array {
            $reply = $kernel->container->get(Chat::class)->onMessage(
                (string) ($p['chat_id'] ?? ''),
                isset($p['user_id']) ? (int) $p['user_id'] : null,
                (string) ($p['text'] ?? ''),
                isset($p['at']) ? (string) $p['at'] : null
            );
            if ($reply !== null) {
                $p['reply'] = $reply;
            }
            return $p;
        }, 'squad');

        // Лидер назначил созвон — это его работа на неделе (§ 09), засчитываем.
        $kernel->events->on('calls.scheduled', static function (array $p) use ($kernel): array {
            if (!empty($p['team_id']) && !empty($p['user_id'])) {
                $kernel->container->get(Leadership::class)->touch((int) $p['team_id'], (int) $p['user_id']);
            }
            return $p;
        }, 'squad');

        // Такт планировщика: пересчитать действующие сквады, даже если
        // никто не открывал приложение (пауза, замена, смена лидера).
        $kernel->events->on('system.tick', static function (array $p) use ($kernel): array {
            $squads = $kernel->container->get(Squads::class);
            $n = 0;
            foreach ($kernel->db()->all("SELECT id FROM squad_squads WHERE status = 'active'") as $row) {
                $squads->sweep((int) $row['id']);
                $n++;
            }
            if ($n > 0) {
                $p['done'][] = 'squad: пересчитано ' . $n;
            }
            return $p;
        }, 'squad');

        // Коллеги и родня (например, «сезон вдвоём» из модуля оплаты) —
        // в разные сквады.
        $kernel->events->on('people.related', static function (array $p) use ($kernel): array {
            $kernel->container->get(Pool::class)->relate((int) ($p['a'] ?? 0), (int) ($p['b'] ?? 0), (string) ($p['kind'] ?? 'known'));
            return $p;
        }, 'squad');


        // Сквады в панели метрик: первая реакция и доживаемость (§ 13).
        $kernel->events->on('analytics.collect', static function (array $p) use ($kernel): array {
            $today = (string) ($p['today'] ?? gmdate('Y-m-d'));
            $r = $kernel->container->get(Chat::class)->reactionMetrics();
            $p['metrics'][] = ['group' => 'squads', 'key' => 'first_reaction', 'value' => $r['squads'], 'target' => 30, 'unit' => 'min', 'better' => 'lower', 'n' => (int) $r['samples']];
            foreach ([30 => 85, 90 => 65, 180 => 50] as $day => $target) {
                $mark = gmdate('Y-m-d', strtotime($today . ' 00:00:00 UTC') - $day * 86400);
                $base = (int) $kernel->db()->value("SELECT COUNT(*) FROM squad_squads WHERE started_on IS NOT NULL AND started_on <= ?", [$mark], 0);
                $dead = (int) $kernel->db()->value(
                    "SELECT COUNT(*) FROM squad_squads WHERE started_on IS NOT NULL AND started_on <= ? AND status = 'disbanded' AND substr(disbanded_at, 1, 10) < date(started_on, '+' || ? || ' days')",
                    [$mark, $day], 0
                );
                $p['metrics'][] = ['group' => 'squads', 'key' => 'alive_d' . $day, 'value' => $base > 0 ? 100.0 * ($base - $dead) / $base : null, 'target' => $target, 'n' => $base];
            }
            return $p;
        }, 'squad');

        // Права на данные (Р-19): выгрузка и удаление — только своих таблиц.
        \App\UserData::register($kernel, 'squad', 'squad_', [
            'squad_pool'         => [],
            'squad_relations'    => ['where' => 'user_a = ? OR user_b = ?'],
            'squad_chat_days'    => [],
            // Место в скваде освобождается, а не пропадает из истории сквада:
            // иначе сломаются командные счета прошлых недель у остальных.
            'squad_members'      => ['erase' => "UPDATE squad_members SET status = 'left', left_reason = 'erased', left_on = date('now') WHERE user_id = ? AND status <> 'left'"],
            'squad_leader_terms' => ['erase' => "UPDATE squad_leader_terms SET ended_on = date('now'), end_reason = 'left' WHERE user_id = ? AND ended_on IS NULL"],
        ]);
    }
}
