<?php
declare(strict_types=1);

namespace Modules\Billing\Domain;

use App\Contracts\Access;
use App\Kernel;
use App\Result;

/**
 * Оплата сезона (Р-20).
 *
 * До эквайринга (нужно юрлицо) — перевод на карту: человек получает сумму
 * и код для комментария к переводу, жмёт «Я оплатил», модератор сверяет
 * поступление и подтверждает. Промокоды и бонусы работают сразу.
 *
 * Сезон — 180 дней плюс 30 дней на ожидание своей волны (Р-07): оплатил
 * в сентябре, волна стартует в октябре — полгода сезона не сгорают.
 */
final class Billing implements Access
{
    public const TARIFFS      = ['season', 'installment', 'duo', 'second'];
    public const SEASON_DAYS  = 210;   // 180 + до 30 дней ожидания волны
    public const INSTALLMENT_EVERY = 30;
    public const GRACE_DAYS   = 7;     // просрочка рассрочки без потери доступа

    public function __construct(private Kernel $kernel)
    {
    }

    // ================= контракт Access =================

    public function hasSeason(int $userId, ?string $date = null): bool
    {
        return $this->activeSeason($userId, $date) !== null;
    }

    public function status(int $userId): array
    {
        $today  = gmdate('Y-m-d');
        $season = $this->activeSeason($userId, $today);
        if ($season !== null) {
            return ['status' => 'active', 'until' => $season['ends_on'], 'trial_left' => null, 'next_due' => $season['next_due']];
        }
        if ($this->pendingPayment($userId) !== null) {
            return ['status' => 'pending', 'until' => null, 'trial_left' => $this->trialLeft($userId)];
        }
        $had = $this->kernel->db()->value('SELECT 1 FROM billing_seasons WHERE user_id = ?', [$userId]);
        if ($had !== null) {
            return ['status' => 'expired', 'until' => null, 'trial_left' => null];
        }
        return ['status' => 'trial', 'until' => $this->account($userId)['trial_until'], 'trial_left' => $this->trialLeft($userId)];
    }

    private function activeSeason(int $userId, ?string $date): ?array
    {
        $date = $date ?? gmdate('Y-m-d');
        $row  = $this->kernel->db()->first(
            "SELECT * FROM billing_seasons WHERE user_id = ? AND status = 'active' AND starts_on <= ? AND ends_on >= ?
             ORDER BY ends_on DESC LIMIT 1",
            [$userId, $date, $date]
        );
        if ($row === null) {
            return null;
        }
        // Рассрочка: доступ держится, пока платежи не просрочены больше недели.
        if ($row['next_due'] !== null && self::addDays((string) $row['next_due'], self::GRACE_DAYS) < $date) {
            return null;
        }
        return $row;
    }

    // ================= счёт и Нулевой цикл =================

    public function account(int $userId): array
    {
        $row = $this->kernel->db()->first('SELECT * FROM billing_accounts WHERE user_id = ?', [$userId]);
        if ($row !== null) {
            return $row;
        }
        $this->kernel->db()->run(
            'INSERT OR IGNORE INTO billing_accounts (user_id, trial_until, ref_code, created_at) VALUES (?, ?, ?, ?)',
            [$userId, self::addDays(gmdate('Y-m-d'), (int) $this->kernel->config->get('billing.trial_days', 14)), $this->uniqueCode('billing_accounts', 'ref_code', 6), gmdate('c')]
        );
        return (array) $this->kernel->db()->first('SELECT * FROM billing_accounts WHERE user_id = ?', [$userId]);
    }

    public function trialLeft(int $userId): int
    {
        $until = (string) $this->account($userId)['trial_until'];
        return max(0, (int) floor((strtotime($until . ' 00:00:00 UTC') - strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC')) / 86400));
    }

    /** Тарифы, которые человеку доступны, с ценами и его бонусами. */
    public function offer(int $userId): array
    {
        $prices  = (array) $this->kernel->config->get('billing.prices', []);
        $credit  = $this->creditBalance($userId);
        $tariffs = [
            ['key' => 'season',      'price' => (int) ($prices['season'] ?? 390000)],
            ['key' => 'installment', 'price' => (int) ($prices['installment'] ?? 79000), 'times' => (int) $this->kernel->config->get('billing.installments', 6)],
            ['key' => 'duo',         'price' => (int) ($prices['duo'] ?? 690000)],
        ];
        if ($this->secondSeasonEligible($userId)) {
            array_unshift($tariffs, ['key' => 'second', 'price' => (int) ($prices['second'] ?? 290000)]);
        }
        return [
            'tariffs' => $tariffs,
            'credit'  => $credit,
            'status'  => $this->status($userId),
            'pending' => ($p = $this->pendingPayment($userId)) ? $this->paymentView($p) : null,
            'instructions' => $p ? $this->instructions($p) : null,
            'ref_code'=> $this->account($userId)['ref_code'],
        ];
    }

    /** Второй сезон со скидкой — тем, кто дошёл до конца первого. */
    public function secondSeasonEligible(int $userId): bool
    {
        return $this->kernel->db()->value(
            "SELECT 1 FROM billing_seasons WHERE user_id = ? AND status IN ('active', 'completed') AND starts_on <= ?",
            [$userId, self::addDays(gmdate('Y-m-d'), -180)]
        ) !== null;
    }

    // ================= оформление =================

    /**
     * Выбор тарифа. Скидка по промокоду и бонусы применяются сразу; если
     * платить нечего — сезон включается без модератора.
     */
    public function checkout(int $userId, string $tariff, string $promo = ''): Result
    {
        if (!in_array($tariff, self::TARIFFS, true)) {
            return Result::fail('bad_tariff');
        }
        if ($tariff === 'second' && !$this->secondSeasonEligible($userId)) {
            return Result::fail('second_not_eligible');
        }
        // Действующий сезон: докупить можно только очередной платёж рассрочки.
        $active = $this->kernel->db()->first(
            "SELECT * FROM billing_seasons WHERE user_id = ? AND status = 'active' AND ends_on >= ? ORDER BY id DESC LIMIT 1",
            [$userId, gmdate('Y-m-d')]
        );
        if ($active !== null && ($active['next_due'] === null || $tariff !== 'installment')) {
            return Result::fail('already_active');
        }
        if (($pending = $this->pendingPayment($userId)) !== null) {
            return Result::ok(['payment' => $this->paymentView($pending), 'instructions' => $this->instructions($pending)]);
        }

        $prices = (array) $this->kernel->config->get('billing.prices', []);
        $list   = (int) ($prices[$tariff] ?? 0);
        $amount = $list;
        $promo  = strtoupper(trim($promo));

        // Промокод «сезон вдвоём» от партнёра.
        if ($promo !== '' && str_starts_with($promo, 'DUO-')) {
            return $this->redeemDuo($userId, $promo);
        }

        $promoRow = null;
        if ($promo !== '') {
            $promoRow = $this->validPromo($promo, $userId);
            if ($promoRow === null) {
                return Result::fail('bad_promo');
            }
            if ($promoRow['kind'] === 'free_season') {
                $this->usePromo($promo, $userId);
                $season = $this->createSeason($userId, 'season', 'promo');
                $this->audit(null, 'promo_redeemed', $userId, ['code' => $promo]);
                return Result::ok(['activated' => true, 'season' => $season]);
            }
            $amount = $promoRow['kind'] === 'percent'
                ? (int) round($list * (100 - min(100, (int) $promoRow['value'])) / 100)
                : max(0, $list - (int) $promoRow['value']);
        }

        // Бонусы (рефералка) — только на разовую оплату, не на долю рассрочки.
        $credit = $tariff === 'installment' ? 0 : min($amount, $this->creditBalance($userId));
        $amount -= $credit;

        if ($amount <= 0) {
            if ($promoRow !== null) {
                $this->usePromo($promo, $userId);
            }
            $paymentId = $this->insertPayment($userId, $tariff, 0, $list, $promo ?: null, $credit, 'confirmed', $this->nextInstallmentNo($userId, $tariff));
            $this->spendCredits($userId, $credit, $paymentId);
            $season = $this->activate($paymentId);
            return Result::ok(['activated' => true, 'season' => $season]);
        }

        $paymentId = $this->insertPayment($userId, $tariff, $amount, $list, $promo ?: null, $credit, 'pending', $this->nextInstallmentNo($userId, $tariff));
        if ($promoRow !== null) {
            $this->usePromo($promo, $userId);
        }
        $payment = $this->payment($paymentId);
        $this->kernel->events->emit('billing.payment_created', ['user_id' => $userId, 'payment_id' => $paymentId, 'amount' => $amount]);

        return Result::ok(['payment' => $this->paymentView($payment), 'instructions' => $this->instructions($payment)]);
    }

    /** «Я оплатил» — модератор увидит платёж первым в очереди. */
    public function markPaid(int $userId, int $paymentId, string $note = ''): Result
    {
        $p = $this->payment($paymentId);
        if ($p === null || (int) $p['user_id'] !== $userId || $p['status'] !== 'pending') {
            return Result::fail('not_found');
        }
        $this->kernel->db()->update('billing_payments', [
            'payer_note'     => mb_substr(trim($note), 0, 120) ?: null,
            'paid_marked_at' => gmdate('c'),
        ], 'id = :id', ['id' => $paymentId]);
        return Result::ok($this->paymentView($this->payment($paymentId)));
    }

    public function cancel(int $userId, int $paymentId): Result
    {
        $p = $this->payment($paymentId);
        if ($p === null || (int) $p['user_id'] !== $userId || $p['status'] !== 'pending') {
            return Result::fail('not_found');
        }
        $this->kernel->db()->update('billing_payments', ['status' => 'cancelled', 'decided_at' => gmdate('c')], 'id = :id', ['id' => $paymentId]);
        $this->releasePromo($p);
        return Result::ok();
    }

    // ================= модератор =================

    public function confirm(int $paymentId, int $moderatorId): Result
    {
        $p = $this->payment($paymentId);
        if ($p === null || $p['status'] !== 'pending') {
            return Result::fail('not_pending');
        }
        $this->kernel->db()->update('billing_payments', [
            'status' => 'confirmed', 'decided_at' => gmdate('c'), 'decided_by' => $moderatorId,
        ], 'id = :id', ['id' => $paymentId]);
        $this->spendCredits((int) $p['user_id'], (int) $p['credit_used'], $paymentId);
        $season = $this->activate($paymentId);
        $this->audit($moderatorId, 'payment_confirmed', (int) $p['user_id'], ['payment' => $paymentId, 'amount' => (int) $p['amount']]);
        return Result::ok(['season' => $season]);
    }

    public function reject(int $paymentId, int $moderatorId, string $reason): Result
    {
        $p = $this->payment($paymentId);
        if ($p === null || $p['status'] !== 'pending') {
            return Result::fail('not_pending');
        }
        $this->kernel->db()->update('billing_payments', [
            'status' => 'rejected', 'decided_at' => gmdate('c'), 'decided_by' => $moderatorId,
            'reject_reason' => mb_substr(trim($reason), 0, 200) ?: null,
        ], 'id = :id', ['id' => $paymentId]);
        $this->releasePromo($p);
        $this->audit($moderatorId, 'payment_rejected', (int) $p['user_id'], ['payment' => $paymentId]);
        return Result::ok();
    }

    /** Сезон в подарок (лидеры сообществ, пилот, компенсация). */
    public function grant(int $userId, int $moderatorId, string $reason = ''): Result
    {
        if ($this->hasSeason($userId)) {
            return Result::fail('already_active');
        }
        $season = $this->createSeason($userId, 'grant', 'grant');
        $this->audit($moderatorId, 'season_granted', $userId, ['reason' => mb_substr($reason, 0, 120)]);
        return Result::ok(['season' => $season]);
    }

    public function pendingList(): array
    {
        // Сначала те, кто нажал «Я оплатил», — их надо сверить в первую очередь.
        return array_map([$this, 'paymentView'], $this->kernel->db()->all(
            "SELECT * FROM billing_payments WHERE status = 'pending' ORDER BY paid_marked_at IS NULL, paid_marked_at, created_at"
        ));
    }

    // ================= промокоды =================

    public function createPromo(array $input, int $moderatorId): Result
    {
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $kind = (string) ($input['kind'] ?? '');
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{2,23}$/', $code) || str_starts_with($code, 'DUO-')) {
            return Result::fail('bad_code');
        }
        if (!in_array($kind, ['free_season', 'percent', 'fixed'], true)) {
            return Result::fail('bad_kind');
        }
        $value = (int) ($input['value'] ?? 0);
        if (($kind === 'percent' && ($value < 1 || $value > 100)) || ($kind === 'fixed' && $value < 1000)) {
            return Result::fail('bad_value');
        }
        if ($this->kernel->db()->value('SELECT 1 FROM billing_promos WHERE code = ?', [$code]) !== null) {
            return Result::fail('code_taken');
        }
        $this->kernel->db()->insert('billing_promos', [
            'code'        => $code,
            'kind'        => $kind,
            'value'       => $kind === 'free_season' ? 0 : $value,
            'max_uses'    => max(1, (int) ($input['max_uses'] ?? 1)),
            'valid_until' => preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($input['valid_until'] ?? '')) ? $input['valid_until'] : null,
            'note'        => mb_substr(trim((string) ($input['note'] ?? '')), 0, 120),
            'created_by'  => $moderatorId,
            'created_at'  => gmdate('c'),
        ]);
        $this->audit($moderatorId, 'promo_created', null, ['code' => $code, 'kind' => $kind]);
        return Result::ok($this->kernel->db()->first('SELECT * FROM billing_promos WHERE code = ?', [$code]));
    }

    public function promos(): array
    {
        return $this->kernel->db()->all('SELECT * FROM billing_promos ORDER BY created_at DESC');
    }

    public function disablePromo(string $code, int $moderatorId): void
    {
        $this->kernel->db()->update('billing_promos', ['active' => 0], 'code = :c', ['c' => strtoupper($code)]);
        $this->audit($moderatorId, 'promo_disabled', null, ['code' => strtoupper($code)]);
    }

    private function validPromo(string $code, int $userId): ?array
    {
        $row = $this->kernel->db()->first('SELECT * FROM billing_promos WHERE code = ? AND active = 1', [$code]);
        if ($row === null || (int) $row['used'] >= (int) $row['max_uses']) {
            return null;
        }
        if ($row['valid_until'] !== null && $row['valid_until'] < gmdate('Y-m-d')) {
            return null;
        }
        if ($this->kernel->db()->value('SELECT 1 FROM billing_promo_uses WHERE code = ? AND user_id = ?', [$code, $userId]) !== null) {
            return null;   // один человек — один раз
        }
        return $row;
    }

    private function usePromo(string $code, int $userId): void
    {
        $this->kernel->db()->run('INSERT OR IGNORE INTO billing_promo_uses (code, user_id, created_at) VALUES (?, ?, ?)', [$code, $userId, gmdate('c')]);
        $this->kernel->db()->run('UPDATE billing_promos SET used = used + 1 WHERE code = ?', [$code]);
    }

    private function releasePromo(array $payment): void
    {
        if (empty($payment['promo_code'])) {
            return;
        }
        $this->kernel->db()->run('DELETE FROM billing_promo_uses WHERE code = ? AND user_id = ?', [$payment['promo_code'], (int) $payment['user_id']]);
        $this->kernel->db()->run('UPDATE billing_promos SET used = MAX(0, used - 1) WHERE code = ?', [$payment['promo_code']]);
    }

    // ================= рефералы и бонусы =================

    /** Человек пришёл по коду. Только новичок и только один раз. */
    public function applyReferral(int $userId, string $code): Result
    {
        $code     = strtoupper(trim($code));
        $referrer = $this->kernel->db()->first('SELECT user_id FROM billing_accounts WHERE ref_code = ?', [$code]);
        if ($referrer === null || (int) $referrer['user_id'] === $userId) {
            return Result::fail('bad_referral');
        }
        if ($this->kernel->db()->value('SELECT 1 FROM billing_referrals WHERE referred_id = ?', [$userId]) !== null) {
            return Result::fail('already_referred');
        }
        if ($this->kernel->db()->value('SELECT 1 FROM billing_seasons WHERE user_id = ?', [$userId]) !== null) {
            return Result::fail('not_new');
        }
        $this->account($userId);
        $this->kernel->db()->insert('billing_referrals', [
            'referred_id' => $userId,
            'referrer_id' => (int) $referrer['user_id'],
            'created_at'  => gmdate('c'),
        ]);
        return Result::ok();
    }

    /**
     * Приглашённый дошёл до дня 30 — бонус обоим. Привязка к дню 30, а не
     * к оплате: чтобы не приводили кого попало (Р-20).
     */
    public function rewardReferral(int $referredId): bool
    {
        $ref = $this->kernel->db()->first('SELECT * FROM billing_referrals WHERE referred_id = ? AND rewarded_at IS NULL', [$referredId]);
        if ($ref === null) {
            return false;
        }
        $bonus = (int) $this->kernel->config->get('billing.referral_bonus', 50000);
        foreach ([(int) $ref['referrer_id'], $referredId] as $uid) {
            $this->kernel->db()->run(
                'INSERT OR IGNORE INTO billing_credits (user_id, amount, reason, ref, created_at) VALUES (?, ?, ?, ?, ?)',
                [$uid, $bonus, 'referral', (string) $referredId, gmdate('c')]
            );
        }
        $this->kernel->db()->update('billing_referrals', ['rewarded_at' => gmdate('c')], 'referred_id = :r', ['r' => $referredId]);
        return true;
    }

    public function creditBalance(int $userId): int
    {
        return (int) $this->kernel->db()->value('SELECT COALESCE(SUM(amount), 0) FROM billing_credits WHERE user_id = ? AND spent_in IS NULL', [$userId], 0);
    }

    /** Бонусы списываются целыми записями по порядку; остаток не сгорает — он у второй записи. */
    private function spendCredits(int $userId, int $amount, int $paymentId): void
    {
        if ($amount <= 0) {
            return;
        }
        foreach ($this->kernel->db()->all('SELECT * FROM billing_credits WHERE user_id = ? AND spent_in IS NULL ORDER BY id', [$userId]) as $c) {
            if ($amount <= 0) {
                break;
            }
            $this->kernel->db()->update('billing_credits', ['spent_in' => $paymentId], 'id = :id', ['id' => (int) $c['id']]);
            $amount -= (int) $c['amount'];
        }
    }

    // ================= сезон вдвоём =================

    private function redeemDuo(int $userId, string $code): Result
    {
        $duo = $this->kernel->db()->first('SELECT * FROM billing_duo WHERE code = ? AND partner_id IS NULL', [$code]);
        if ($duo === null || (int) $duo['buyer_id'] === $userId) {
            return Result::fail('bad_promo');
        }
        if ($this->hasSeason($userId)) {
            return Result::fail('already_active');
        }
        $this->kernel->db()->update('billing_duo', ['partner_id' => $userId, 'redeemed_at' => gmdate('c')], 'code = :c', ['c' => $code]);
        $season = $this->createSeason($userId, 'duo', 'duo');
        // Родня и коллеги — в разные сквады (Р-06). Модуль сквадов слушает.
        $this->kernel->events->emit('people.related', ['a' => (int) $duo['buyer_id'], 'b' => $userId, 'kind' => 'duo']);
        return Result::ok(['activated' => true, 'season' => $season]);
    }

    // ================= внутреннее =================

    private function activate(int $paymentId): array
    {
        $p      = (array) $this->payment($paymentId);
        $userId = (int) $p['user_id'];
        $tariff = (string) $p['tariff'];

        if ($tariff === 'installment') {
            $season = $this->kernel->db()->first(
                "SELECT * FROM billing_seasons WHERE user_id = ? AND tariff = 'installment' AND status = 'active' ORDER BY id DESC LIMIT 1",
                [$userId]
            );
            if ($season !== null) {
                $paid  = (int) $season['paid_installments'] + 1;
                $total = (int) $this->kernel->config->get('billing.installments', 6);
                $this->kernel->db()->update('billing_seasons', [
                    'paid_installments' => $paid,
                    'next_due'          => $paid >= $total ? null : self::addDays((string) $season['next_due'], self::INSTALLMENT_EVERY),
                ], 'id = :id', ['id' => (int) $season['id']]);
                return (array) $this->kernel->db()->first('SELECT * FROM billing_seasons WHERE id = ?', [(int) $season['id']]);
            }
        }

        $season = $this->createSeason($userId, $tariff, 'payment');
        if ($tariff === 'duo') {
            $this->kernel->db()->insert('billing_duo', [
                'code'       => 'DUO-' . $this->uniqueCode('billing_duo', 'code', 6, 'DUO-'),
                'buyer_id'   => $userId,
                'payment_id' => $paymentId,
                'created_at' => gmdate('c'),
            ]);
        }
        return $season;
    }

    private function createSeason(int $userId, string $tariff, string $source): array
    {
        $today = gmdate('Y-m-d');
        $this->kernel->db()->insert('billing_seasons', [
            'user_id'           => $userId,
            'tariff'            => $tariff,
            'starts_on'         => $today,
            'ends_on'           => self::addDays($today, self::SEASON_DAYS),
            'status'            => 'active',
            'source'            => $source,
            'paid_installments' => $tariff === 'installment' ? 1 : 0,
            'next_due'          => $tariff === 'installment' ? self::addDays($today, self::INSTALLMENT_EVERY) : null,
            'created_at'        => gmdate('c'),
        ]);
        $season = (array) $this->kernel->db()->first('SELECT * FROM billing_seasons WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
        $this->kernel->events->emit('billing.season_activated', ['user_id' => $userId, 'tariff' => $tariff, 'source' => $source]);
        return $season;
    }

    private function insertPayment(int $userId, string $tariff, int $amount, int $list, ?string $promo, int $credit, string $status, ?int $installmentNo): int
    {
        return (int) $this->kernel->db()->insert('billing_payments', [
            'user_id'        => $userId,
            'tariff'         => $tariff,
            'amount'         => $amount,
            'list_price'     => $list,
            'code'           => 'L180-' . $this->uniqueCode('billing_payments', 'code', 5, 'L180-'),
            'promo_code'     => $promo,
            'credit_used'    => $credit,
            'installment_no' => $installmentNo,
            'status'         => $status,
            'decided_at'     => $status === 'confirmed' ? gmdate('c') : null,
            'created_at'     => gmdate('c'),
        ]);
    }

    private function nextInstallmentNo(int $userId, string $tariff): ?int
    {
        if ($tariff !== 'installment') {
            return null;
        }
        $season = $this->kernel->db()->first(
            "SELECT paid_installments FROM billing_seasons WHERE user_id = ? AND tariff = 'installment' AND status = 'active' ORDER BY id DESC LIMIT 1",
            [$userId]
        );
        return $season === null ? 1 : (int) $season['paid_installments'] + 1;
    }

    public function pendingPayment(int $userId): ?array
    {
        return $this->kernel->db()->first("SELECT * FROM billing_payments WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$userId]);
    }

    public function payment(int $id): ?array
    {
        return $this->kernel->db()->first('SELECT * FROM billing_payments WHERE id = ?', [$id]);
    }

    public function paymentView(array $p): array
    {
        return [
            'id'          => (int) $p['id'],
            'user_id'     => (int) $p['user_id'],
            'tariff'      => $p['tariff'],
            'amount'      => (int) $p['amount'],
            'list_price'  => (int) $p['list_price'],
            'credit_used' => (int) $p['credit_used'],
            'promo_code'  => $p['promo_code'],
            'code'        => $p['code'],
            'installment_no' => $p['installment_no'] !== null ? (int) $p['installment_no'] : null,
            'status'      => $p['status'],
            'payer_note'  => $p['payer_note'],
            'marked_paid' => $p['paid_marked_at'] !== null,
            'created_at'  => $p['created_at'],
        ];
    }

    private function instructions(array $payment): array
    {
        $cards = array_values(array_filter(array_map('trim', explode('|', (string) $this->kernel->config->get('billing.cards', '')))));
        return [
            'amount'  => (int) $payment['amount'],
            'code'    => $payment['code'],
            'cards'   => $cards,
            'contact' => (string) $this->kernel->config->get('billing.contact', ''),
        ];
    }

    /** Код оплаты, промокод партнёра, реферальный код. Без 0/O и 1/I. */
    private function uniqueCode(string $table, string $column, int $length, string $prefix = ''): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while ($this->kernel->db()->value("SELECT 1 FROM {$table} WHERE {$column} = ?", [$prefix . $code]) !== null);
        return $code;
    }

    private function audit(?int $actor, string $action, ?int $target, array $meta = []): void
    {
        $this->kernel->events->emit('admin.action', [
            'actor_id' => $actor, 'module' => 'billing', 'action' => $action, 'target_id' => $target, 'meta' => $meta,
        ]);
    }

    public static function addDays(string $date, int $days): string
    {
        return gmdate('Y-m-d', strtotime($date . ' 00:00:00 UTC') + $days * 86400);
    }
}
