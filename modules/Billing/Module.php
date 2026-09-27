<?php
declare(strict_types=1);

namespace Modules\Billing;

use App\BaseModule;
use App\Container;
use App\Contracts;
use App\Kernel;
use Modules\Billing\Domain\Billing;

final class Module extends BaseModule
{
    public function register(Container $container, Kernel $kernel): void
    {
        $container->singleton(Billing::class, static fn() => new Billing($kernel), 'billing');
        $container->singleton(Contracts\Access::class, static fn(Container $c) => $c->get(Billing::class), 'billing');
    }

    public function boot(Kernel $kernel): void
    {
        // Нулевой цикл начинается с регистрации.
        $kernel->events->on('user.registered', static function (array $p) use ($kernel): array {
            $id = (int) ($p['user']['id'] ?? 0);
            if ($id > 0) {
                $kernel->container->get(Billing::class)->account($id);
            }
            return $p;
        }, 'billing');

        // Приглашённый дошёл до дня 30 — бонус обоим (Р-20).
        $kernel->events->on('checkin.recorded', static function (array $p) use ($kernel): array {
            if ((int) ($p['day_number'] ?? 0) >= 30 && !empty($p['user_id'])) {
                $kernel->container->get(Billing::class)->rewardReferral((int) $p['user_id']);
            }
            return $p;
        }, 'billing');


        // Деньги: доля пришедших по приглашению среди платящих (§ 13, цель ≥ 35%).
        $kernel->events->on('analytics.collect', static function (array $p) use ($kernel): array {
            $paying   = (int) $kernel->db()->value("SELECT COUNT(DISTINCT user_id) FROM billing_seasons WHERE source IN ('payment', 'promo', 'duo')", [], 0);
            $referred = (int) $kernel->db()->value("SELECT COUNT(DISTINCT s.user_id) FROM billing_seasons s JOIN billing_referrals r ON r.referred_id = s.user_id WHERE s.source IN ('payment', 'promo', 'duo')", [], 0);
            $p['metrics'][] = ['group' => 'business', 'key' => 'referral_share', 'value' => $paying > 0 ? 100.0 * $referred / $paying : null, 'target' => 35, 'n' => $paying];
            return $p;
        }, 'billing');

        // Права на данные (Р-19): выгрузка и удаление — только своих таблиц.
        \App\UserData::register($kernel, 'billing', 'billing_', [
            'billing_accounts'   => [],
            'billing_seasons'    => [],
            'billing_credits'    => [],
            'billing_promo_uses' => [],
            'billing_referrals'  => ['where' => 'referred_id = ? OR referrer_id = ?'],
            // Платежи — бухгалтерский документ: сумма и дата остаются, человек — нет.
            'billing_payments'   => ['erase' => 'UPDATE billing_payments SET user_id = 0, payer_note = NULL WHERE user_id = ?'],
            'billing_duo'        => ['where' => 'buyer_id = ? OR partner_id = ?',
                                     'erase' => 'UPDATE billing_duo SET buyer_id = CASE WHEN buyer_id = ? THEN 0 ELSE buyer_id END, partner_id = CASE WHEN partner_id = ? THEN 0 ELSE partner_id END'],
        ]);
    }
}
