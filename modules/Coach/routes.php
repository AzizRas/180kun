<?php
declare(strict_types=1);

use App\Kernel;
use App\Request;
use App\Response;
use App\Router;
use Modules\Coach\Domain\CoachService;

return static function (Router $router, Kernel $kernel): void {

    $coach = static fn(Kernel $k): CoachService => $k->container->get(CoachService::class);

    $router->get('/api/coach/state', static function (Request $r, Kernel $k) use ($coach): Response {
        $c = $coach($k);
        return Response::json([
            'ok'            => true,
            'live'          => $c->isLive(),
            'consent_ai'    => $c->hasConsent((int) $r->userId()),
            'consent_asked' => $c->consentAsked((int) $r->userId()),
        ]);
    }, ['auth' => true]);

    /** Отдельное явное согласие на передачу обезличенных данных ИИ (Р-19). Отзывается так же. */
    $router->post('/api/coach/consent', static function (Request $r, Kernel $k) use ($coach): Response {
        $coach($k)->setConsent((int) $r->userId(), $r->bool('ai'));
        return Response::json(['ok' => true, 'consent_ai' => $r->bool('ai')]);
    }, ['auth' => true]);

    /** Недельный обзор. Один на неделю, пересчитывать нечего. */
    $router->get('/api/coach/week', static function (Request $r, Kernel $k) use ($coach): Response {
        $review = $coach($k)->weeklyReview(['user_id' => (int) $r->userId(), 'date' => $r->str('date') ?: null]);
        unset($review['facts']);
        return Response::json(['ok' => true, 'review' => $review]);
    }, ['auth' => true]);

    /** «Полезно / не очень» — один вопрос после обзора (§ 07). */
    $router->post('/api/coach/feedback', static function (Request $r, Kernel $k) use ($coach): Response {
        $ok = $coach($k)->feedback((int) $r->userId(), $r->int('id'), $r->bool('useful'));
        return $ok ? Response::json(['ok' => true]) : Response::json(['error' => 'not_found'], 404);
    }, ['auth' => true]);

    /**
     * Качество тренера для модератора: доля «полезно» по обзорам за две
     * недели (ниже 60% — промпт переписывается), доля ответов модели и
     * почему ответы модели отклонялись.
     */
    $router->get('/api/admin/coach/metrics', static function (Request $r, Kernel $k): Response {
        if (!in_array((string) ($r->user()['role'] ?? ''), ['admin', 'moderator'], true)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $since = gmdate('c', time() - 14 * 86400);
        $rated = $k->db()->first(
            "SELECT COUNT(*) AS n, SUM(useful) AS yes FROM coach_msgs WHERE kind = 'week' AND useful IS NOT NULL AND created_at >= ?",
            [$since]
        );
        $bySource = $k->db()->all('SELECT source, COUNT(*) AS n FROM coach_msgs WHERE created_at >= ? GROUP BY source', [$since]);
        $reasons  = [];
        foreach ($k->db()->all('SELECT codes FROM coach_rejections WHERE created_at >= ?', [$since]) as $row) {
            foreach (json_decode((string) $row['codes'], true) ?: [] as $code) {
                $reasons[$code] = ($reasons[$code] ?? 0) + 1;
            }
        }
        arsort($reasons);
        $n = (int) ($rated['n'] ?? 0);
        return Response::json([
            'ok'          => true,
            'useful_pct'  => $n > 0 ? round(100 * (int) $rated['yes'] / $n) : null,
            'rated'       => $n,
            'by_source'   => array_column($bySource, 'n', 'source'),
            'rejected'    => $reasons,
            'tokens'      => $k->db()->first('SELECT COALESCE(SUM(tokens_in),0) AS input, COALESCE(SUM(tokens_out),0) AS output FROM coach_msgs WHERE created_at >= ?', [$since]),
        ]);
    }, ['auth' => true]);
};
