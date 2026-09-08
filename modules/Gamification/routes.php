<?php
declare(strict_types=1);

use App\Kernel;
use App\Request;
use App\Response;
use App\Router;
use Modules\Gamification\Domain\Ledger;

return static function (Router $router, Kernel $kernel): void {

    $router->get('/api/me/progress', static function (Request $r, Kernel $k): Response {
        /** @var Ledger $ledger */
        $ledger  = $k->container->get(Ledger::class);
        $profile = $ledger->profile((int) $r->userId());

        $recent = array_map(static fn($row) => [
            'reason' => $row['reason'],
            'amount' => (int) $row['amount'],
            'title'  => $k->i18n->t('xp.' . $row['reason']),
            'at'     => $row['created_at'],
        ], $ledger->recent((int) $r->userId(), 12));

        return Response::json(['progress' => $profile, 'recent' => $recent]);
    }, ['auth' => true]);
};
