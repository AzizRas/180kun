<?php
declare(strict_types=1);

use App\Kernel;
use App\Request;
use App\Response;
use App\Result;
use App\Router;
use Modules\Squad\Domain\Chat;
use Modules\Squad\Domain\Leadership;
use Modules\Squad\Domain\Pool;
use Modules\Squad\Domain\Squads;

return static function (Router $router, Kernel $kernel): void {

    $squads = static fn(Kernel $k): Squads => $k->container->get(Squads::class);
    $pool   = static fn(Kernel $k): Pool => $k->container->get(Pool::class);

    /** Ответ из Result: ошибки переводятся, коды правил отдаются как есть. */
    $reply = static function (Result $r, Kernel $k, int $failStatus = 422): Response {
        if ($r->ok) {
            return Response::json(['ok' => true, 'data' => $r->data]);
        }
        return Response::json([
            'error'   => $r->error,
            'message' => $k->i18n->t('squad.error.' . $r->error),
            'rules'   => array_map(static fn($rule) => [
                'code'  => $rule,
                'title' => $k->i18n->t('squad.rule.' . $rule),
            ], (array) ($r->meta['rules'] ?? [])),
        ], $failStatus);
    };

    $isModerator = static fn(Request $r): bool => in_array((string) ($r->user()['role'] ?? ''), ['admin', 'moderator'], true);
    $forbidden   = static fn(): Response => Response::json(['error' => 'forbidden'], 403);

    // ---------------- участник ----------------

    /**
     * Мой сквад. Если человек прошёл онбординг до того, как включили
     * модуль сквадов, ставим его в пул здесь: профиль спрашиваем у
     * онбординга событием, в его таблицы не лезем.
     */
    $router->get('/api/squad', static function (Request $r, Kernel $k) use ($squads, $pool): Response {
        $userId = (int) $r->userId();

        if ($pool($k)->find($userId) === null) {
            $answer = $k->events->emit('squad.profile_lookup', ['user_id' => $userId, 'profile' => null, 'baseline' => null]);
            if (is_array($answer['profile'] ?? null) && ($answer['profile']['status'] ?? '') === 'ready') {
                $pool($k)->join($userId, $answer['profile'], (array) ($answer['baseline'] ?? []));
            }
        }

        return Response::json(['ok' => true] + $squads($k)->viewFor($userId, $r->str('date') ?: null));
    }, ['auth' => true]);

    /** Два вопроса для подбора: смешанный сквад и серьёзность намерения. */
    $router->post('/api/squad/prefs', static function (Request $r, Kernel $k) use ($pool, $reply): Response {
        return $reply($pool($k)->setPrefs((int) $r->userId(), $r->input('mixed_ok'), $r->input('commit')), $k);
    }, ['auth' => true]);

    $router->get('/api/squad/leader', static function (Request $r, Kernel $k) use ($squads, $reply): Response {
        return $reply($squads($k)->leaderPanel((int) $r->userId(), $r->str('date') ?: null), $k, 403);
    }, ['auth' => true]);

    /** Отказ от роли — одним нажатием, без объяснений и последствий. */
    $router->post('/api/squad/leader/decline', static function (Request $r, Kernel $k) use ($squads): Response {
        $squad = $squads($k)->squadOf((int) $r->userId());
        $ok    = $squad !== null && $k->container->get(Leadership::class)->decline($squad, (int) $r->userId(), gmdate('Y-m-d'));
        return $ok ? Response::json(['ok' => true]) : Response::json(['error' => 'not_leader'], 403);
    }, ['auth' => true]);

    /** Лидер отметил, что выполнил обязанность дня. */
    $router->post('/api/squad/leader/done', static function (Request $r, Kernel $k) use ($squads): Response {
        $squad = $squads($k)->squadOf((int) $r->userId());
        if ($squad === null || (int) $squad['leader_id'] !== (int) $r->userId()) {
            return Response::json(['error' => 'not_leader'], 403);
        }
        $k->container->get(Leadership::class)->touch((int) $squad['id'], (int) $r->userId());
        return Response::json(['ok' => true]);
    }, ['auth' => true]);

    /** «Нужна помощь» — сигнал модератору. Исключать людей лидер не может. */
    $router->post('/api/squad/help', static function (Request $r, Kernel $k) use ($squads, $reply): Response {
        $squad = $squads($k)->squadOf((int) $r->userId());
        if ($squad === null || (int) $squad['leader_id'] !== (int) $r->userId()) {
            return Response::json(['error' => 'not_leader'], 403);
        }
        return $reply($squads($k)->flagHelp((int) $squad['id'], (int) $r->userId(), $r->int('user_id')), $k);
    }, ['auth' => true]);

    // ---------------- модератор ----------------

    $router->get('/api/admin/squad/waves', static function (Request $r, Kernel $k) use ($squads, $isModerator, $forbidden): Response {
        if (!$isModerator($r)) {
            return $forbidden();
        }
        $unassigned = (int) $k->db()->value("SELECT COUNT(*) FROM squad_pool WHERE status = 'waiting' AND wave_id IS NULL", [], 0);
        return Response::json(['ok' => true, 'waves' => $squads($k)->waves(), 'unassigned' => $unassigned]);
    }, ['auth' => true]);

    $router->post('/api/admin/squad/waves', static function (Request $r, Kernel $k) use ($squads, $isModerator, $forbidden, $reply): Response {
        if (!$isModerator($r)) {
            return $forbidden();
        }
        return $reply($squads($k)->createWave($r->str('start_date'), $r->str('title')), $k);
    }, ['auth' => true]);

    /** Волна целиком: предложенные составы и те, кому не нашлось места. */
    $router->get('/api/admin/squad/waves/{id}', static function (Request $r, Kernel $k) use ($squads, $pool, $isModerator, $forbidden): Response {
        if (!$isModerator($r)) {
            return $forbidden();
        }
        $waveId = (int) $r->param('id');
        $wave   = $squads($k)->wave($waveId);
        if ($wave === null) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $auth    = $k->container->get(App\Contracts\Auth::class);
        $waiting = array_map(static function (array $c) use ($auth): array {
            $u = $auth->userById($c['user_id']);
            return $c + ['name' => trim((string) ($u['name'] ?? '')) ?: '#' . $c['user_id']];
        }, $pool($k)->candidates($waveId));

        return Response::json([
            'ok'      => true,
            'wave'    => $wave,
            'squads'  => $squads($k)->listForWave($waveId),
            'waiting' => $waiting,
        ]);
    }, ['auth' => true]);

    $router->post('/api/admin/squad/waves/{id}/match', static function (Request $r, Kernel $k) use ($squads, $isModerator, $forbidden, $reply): Response {
        if (!$isModerator($r)) {
            return $forbidden();
        }
        return $reply($squads($k)->runMatching((int) $r->param('id')), $k);
    }, ['auth' => true]);

    $router->post('/api/admin/squad/waves/{id}/start', static function (Request $r, Kernel $k) use ($squads, $isModerator, $forbidden, $reply): Response {
        if (!$isModerator($r)) {
            return $forbidden();
        }
        return $reply($squads($k)->startWave((int) $r->param('id'), $r->str('date') ?: null), $k);
    }, ['auth' => true]);

    $router->get('/api/admin/squad/active', static function (Request $r, Kernel $k) use ($squads, $isModerator, $forbidden): Response {
        if (!$isModerator($r)) {
            return $forbidden();
        }
        foreach ($k->db()->all("SELECT id FROM squad_squads WHERE status = 'active'") as $row) {
            $squads($k)->sweep((int) $row['id']);
        }
        return Response::json(['ok' => true, 'squads' => $squads($k)->listForWave(null)]);
    }, ['auth' => true]);

    $router->get('/api/admin/squad/metrics', static function (Request $r, Kernel $k) use ($isModerator, $forbidden): Response {
        if (!$isModerator($r)) {
            return $forbidden();
        }
        $count = static fn(string $sql) => (int) $k->db()->value($sql, [], 0);
        return Response::json([
            'ok'        => true,
            'reaction_minutes' => $k->container->get(Chat::class)->reactionMetrics(),
            'active'    => $count("SELECT COUNT(*) FROM squad_squads WHERE status = 'active'"),
            'disbanded' => $count("SELECT COUNT(*) FROM squad_squads WHERE status = 'disbanded'"),
            'waiting'   => $count("SELECT COUNT(*) FROM squad_pool WHERE status = 'waiting'"),
            'needs_help'=> $count("SELECT COUNT(*) FROM squad_members WHERE needs_help = 1 AND status <> 'left'"),
        ]);
    }, ['auth' => true]);

    $router->post('/api/admin/squad/move', static function (Request $r, Kernel $k) use ($squads, $isModerator, $forbidden, $reply): Response {
        if (!$isModerator($r)) {
            return $forbidden();
        }
        return $reply($squads($k)->move($r->int('user_id'), $r->int('squad_id')), $k);
    }, ['auth' => true]);

    $router->post('/api/admin/squad/relate', static function (Request $r, Kernel $k) use ($pool, $isModerator, $forbidden): Response {
        if (!$isModerator($r)) {
            return $forbidden();
        }
        $pool($k)->relate($r->int('a'), $r->int('b'), $r->str('kind') ?: 'known');
        return Response::json(['ok' => true]);
    }, ['auth' => true]);

    $router->get('/api/admin/squad/{id}', static function (Request $r, Kernel $k) use ($squads, $isModerator, $forbidden): Response {
        if (!$isModerator($r)) {
            return $forbidden();
        }
        $view = $squads($k)->adminView((int) $r->param('id'));
        return $view === null ? Response::json(['error' => 'not_found'], 404) : Response::json(['ok' => true, 'squad' => $view]);
    }, ['auth' => true]);

    $router->post('/api/admin/squad/{id}/{action}', static function (Request $r, Kernel $k) use ($squads, $isModerator, $forbidden, $reply): Response {
        if (!$isModerator($r)) {
            return $forbidden();
        }
        $id = (int) $r->param('id');
        $s  = $squads($k);

        return match ((string) $r->param('action')) {
            'approve' => $reply($s->approve($id, (int) $r->userId()), $k),
            'reject'  => $reply($s->reject($id), $k),
            'disband' => $reply($s->disband($id, 'moderator'), $k),
            'remove'  => $reply($s->removeMember($id, $r->int('user_id'), $r->str('reason') ?: 'removed'), $k),
            'link'    => $reply($s->setInviteLink($id, $r->str('invite_link')), $k),
            default   => Response::json(['error' => 'not_found'], 404),
        };
    }, ['auth' => true]);
};
