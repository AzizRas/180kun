<?php
declare(strict_types=1);

use App\Contracts;
use App\Kernel;
use App\Request;
use App\Response;
use App\Router;

return static function (Router $router, Kernel $kernel): void {

    /** Весь план: главы, недели, итоговые цифры. */
    $router->get('/api/plan', static function (Request $r, Kernel $k): Response {
        /** @var Contracts\Planner $planner */
        $planner = $k->container->get(Contracts\Planner::class);
        $plan    = $planner->currentPlan((int) $r->userId());

        if ($plan === null) {
            return Response::json(['plan' => null, 'message' => $k->i18n->t('plan.none')], 404);
        }

        return Response::json([
            'plan' => [
                'version'     => (int) $plan['version'],
                'goal_dir'    => $plan['goal_dir'],
                'tier'        => $plan['tier'],
                'start_date'  => $plan['start_date'],
                'current_day' => $plan['current_day'],
                'limited'     => (int) $plan['limited'] === 1,
                'meta'        => $plan['meta'],
                'chapters'    => array_map(static fn($c) => [
                    'n'             => (int) $c['n'],
                    'theme'         => $c['theme'],
                    'title'         => $k->i18n->t('chapter.' . $c['theme']),
                    'subtitle'      => $k->i18n->t('chapter.' . $c['theme'] . '.about'),
                    'from_day'      => (int) $c['from_day'],
                    'to_day'        => (int) $c['to_day'],
                    'weight_target' => $c['weight_target'] !== null ? (float) $c['weight_target'] : null,
                    'steps_target'  => (int) $c['steps_target'],
                    'minutes'       => (int) $c['minutes'],
                ], $plan['chapters']),
                'weeks' => array_map(static fn($w) => [
                    'n'             => (int) $w['n'],
                    'chapter'       => (int) $w['chapter'],
                    'from_day'      => (int) $w['from_day'],
                    'to_day'        => (int) $w['to_day'],
                    'deload'        => (int) $w['deload'] === 1,
                    'steps_target'  => (int) $w['steps_target'],
                    'minutes'       => (int) $w['minutes'],
                    'strength'      => (int) $w['strength'],
                    'weight_target' => $w['weight_target'] !== null ? (float) $w['weight_target'] : null,
                    'norm_days'     => (int) $w['norm_days'],
                ], $plan['weeks']),
            ],
        ]);
    }, ['auth' => true]);

    /** Что делать сегодня — самый частый запрос приложения. */
    $router->get('/api/plan/today', static function (Request $r, Kernel $k): Response {
        /** @var Contracts\Planner $planner */
        $planner = $k->container->get(Contracts\Planner::class);
        $today   = $planner->today((int) $r->userId(), $r->str('date') ?: null);

        if ($today === null) {
            return Response::json(['today' => null, 'message' => $k->i18n->t('plan.none')], 404);
        }

        if (!empty($today['action'])) {
            $key = $today['action']['key'];
            $today['action']['title'] = $k->i18n->t('action.' . $key);
            $today['action']['hint']  = $k->i18n->t('action.' . $key . '.hint');
        }
        if (!empty($today['theme'])) {
            $today['chapter_title'] = $k->i18n->t('chapter.' . $today['theme']);
        }

        return Response::json(['today' => $today]);
    }, ['auth' => true]);
};
