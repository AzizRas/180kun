<?php
declare(strict_types=1);

use App\Kernel;
use App\Request;
use App\Response;
use App\Result;
use App\Router;
use Modules\Billing\Domain\Billing;

return static function (Router $router, Kernel $kernel): void {

    $billing = static fn(Kernel $k): Billing => $k->container->get(Billing::class);
    $reply   = static fn(Result $r, Kernel $k): Response => $r->ok
        ? Response::json(['ok' => true, 'data' => $r->data])
        : Response::json(['error' => $r->error, 'message' => $k->i18n->t('billing.error.' . $r->error)], 422);
    $isModerator = static fn(Request $r): bool => in_array((string) ($r->user()['role'] ?? ''), ['admin', 'moderator'], true);
    $forbidden   = static fn(): Response => Response::json(['error' => 'forbidden'], 403);

    // ---------------- участник ----------------

    $router->get('/api/billing', static function (Request $r, Kernel $k) use ($billing): Response {
        return Response::json(['ok' => true] + $billing($k)->offer((int) $r->userId()));
    }, ['auth' => true]);

    $router->post('/api/billing/checkout', static function (Request $r, Kernel $k) use ($billing, $reply): Response {
        return $reply($billing($k)->checkout((int) $r->userId(), $r->str('tariff'), $r->str('promo')), $k);
    }, ['auth' => true]);

    $router->post('/api/billing/payments/{id}/paid', static function (Request $r, Kernel $k) use ($billing, $reply): Response {
        return $reply($billing($k)->markPaid((int) $r->userId(), (int) $r->param('id'), $r->str('note')), $k);
    }, ['auth' => true]);

    $router->post('/api/billing/payments/{id}/cancel', static function (Request $r, Kernel $k) use ($billing, $reply): Response {
        return $reply($billing($k)->cancel((int) $r->userId(), (int) $r->param('id')), $k);
    }, ['auth' => true]);

    $router->post('/api/billing/referral', static function (Request $r, Kernel $k) use ($billing, $reply): Response {
        return $reply($billing($k)->applyReferral((int) $r->userId(), $r->str('code')), $k);
    }, ['auth' => true]);

    /** Код второго места «сезона вдвоём» — чтобы отдать партнёру. */
    $router->get('/api/billing/duo', static function (Request $r, Kernel $k): Response {
        return Response::json(['ok' => true, 'codes' => $k->db()->all(
            'SELECT code, partner_id IS NOT NULL AS used FROM billing_duo WHERE buyer_id = ?',
            [(int) $r->userId()]
        )]);
    }, ['auth' => true]);

    // ---------------- модератор ----------------

    $router->get('/api/admin/billing/payments', static function (Request $r, Kernel $k) use ($billing, $isModerator, $forbidden): Response {
        if (!$isModerator($r)) {
            return $forbidden();
        }
        $auth = $k->container->get(App\Contracts\Auth::class);
        $list = array_map(static function (array $p) use ($auth): array {
            $u = $auth->userById($p['user_id']);
            return $p + ['name' => trim((string) ($u['name'] ?? '')) ?: '#' . $p['user_id'], 'phone' => $u['phone'] ?? null];
        }, $billing($k)->pendingList());

        return Response::json([
            'ok'       => true,
            'payments' => $list,
            'revenue'  => (int) $k->db()->value("SELECT COALESCE(SUM(amount), 0) FROM billing_payments WHERE status = 'confirmed'", [], 0),
            'active'   => (int) $k->db()->value("SELECT COUNT(DISTINCT user_id) FROM billing_seasons WHERE status = 'active' AND ends_on >= ?", [gmdate('Y-m-d')], 0),
        ]);
    }, ['auth' => true]);

    $router->post('/api/admin/billing/payments/{id}/{action}', static function (Request $r, Kernel $k) use ($billing, $reply, $isModerator, $forbidden): Response {
        if (!$isModerator($r)) {
            return $forbidden();
        }
        $id = (int) $r->param('id');
        return match ((string) $r->param('action')) {
            'confirm' => $reply($billing($k)->confirm($id, (int) $r->userId()), $k),
            'reject'  => $reply($billing($k)->reject($id, (int) $r->userId(), $r->str('reason')), $k),
            default   => Response::json(['error' => 'not_found'], 404),
        };
    }, ['auth' => true]);

    $router->get('/api/admin/billing/promos', static function (Request $r, Kernel $k) use ($billing, $isModerator, $forbidden): Response {
        return $isModerator($r) ? Response::json(['ok' => true, 'promos' => $billing($k)->promos()]) : $forbidden();
    }, ['auth' => true]);

    $router->post('/api/admin/billing/promos', static function (Request $r, Kernel $k) use ($billing, $reply, $isModerator, $forbidden): Response {
        return $isModerator($r) ? $reply($billing($k)->createPromo($r->body(), (int) $r->userId()), $k) : $forbidden();
    }, ['auth' => true]);

    $router->post('/api/admin/billing/promos/{code}/disable', static function (Request $r, Kernel $k) use ($billing, $isModerator, $forbidden): Response {
        if (!$isModerator($r)) {
            return $forbidden();
        }
        $billing($k)->disablePromo((string) $r->param('code'), (int) $r->userId());
        return Response::json(['ok' => true]);
    }, ['auth' => true]);

    $router->post('/api/admin/billing/grant', static function (Request $r, Kernel $k) use ($billing, $reply, $isModerator, $forbidden): Response {
        return $isModerator($r) ? $reply($billing($k)->grant($r->int('user_id'), (int) $r->userId(), $r->str('reason')), $k) : $forbidden();
    }, ['auth' => true]);
};
