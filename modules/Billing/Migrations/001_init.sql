-- Оплата. Владеет только модуль Billing. Суммы — в сумах, целыми.

CREATE TABLE IF NOT EXISTS billing_accounts (
    user_id      INTEGER PRIMARY KEY,
    trial_until  TEXT    NOT NULL,              -- конец Нулевого цикла
    ref_code     TEXT    NOT NULL UNIQUE,       -- свой реферальный код
    created_at   TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS billing_seasons (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id           INTEGER NOT NULL,
    tariff            TEXT    NOT NULL,         -- season | installment | duo | second | grant
    starts_on         TEXT    NOT NULL,
    ends_on           TEXT    NOT NULL,
    status            TEXT    NOT NULL DEFAULT 'active', -- active | completed | revoked
    source            TEXT    NOT NULL,         -- payment | promo | duo | grant
    paid_installments INTEGER NOT NULL DEFAULT 0,
    next_due          TEXT    NULL,             -- следующий платёж рассрочки
    created_at        TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_billing_seasons_user ON billing_seasons(user_id, status);

CREATE TABLE IF NOT EXISTS billing_payments (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER NOT NULL,
    tariff         TEXT    NOT NULL,
    amount         INTEGER NOT NULL,            -- к оплате после скидок и бонусов
    list_price     INTEGER NOT NULL,
    code           TEXT    NOT NULL UNIQUE,     -- указать в комментарии к переводу
    promo_code     TEXT    NULL,
    credit_used    INTEGER NOT NULL DEFAULT 0,
    installment_no INTEGER NULL,
    status         TEXT    NOT NULL DEFAULT 'pending', -- pending | confirmed | rejected | cancelled
    payer_note     TEXT    NULL,                -- «оплатил с карты …1234»
    paid_marked_at TEXT    NULL,
    decided_at     TEXT    NULL,
    decided_by     INTEGER NULL,
    reject_reason  TEXT    NULL,
    created_at     TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_billing_payments_status ON billing_payments(status, created_at);

CREATE TABLE IF NOT EXISTS billing_promos (
    code        TEXT    PRIMARY KEY,
    kind        TEXT    NOT NULL,               -- free_season | percent | fixed
    value       INTEGER NOT NULL DEFAULT 0,
    max_uses    INTEGER NOT NULL DEFAULT 1,
    used        INTEGER NOT NULL DEFAULT 0,
    valid_until TEXT    NULL,
    note        TEXT    NOT NULL DEFAULT '',
    active      INTEGER NOT NULL DEFAULT 1,
    created_by  INTEGER NULL,
    created_at  TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS billing_promo_uses (
    code       TEXT    NOT NULL,
    user_id    INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    PRIMARY KEY (code, user_id)
);

-- Кто кого привёл. Один приглашённый — один пригласивший.
CREATE TABLE IF NOT EXISTS billing_referrals (
    referred_id INTEGER PRIMARY KEY,
    referrer_id INTEGER NOT NULL,
    created_at  TEXT    NOT NULL,
    rewarded_at TEXT    NULL
);

-- Бонусы на следующий сезон. Одна запись на повод — начислить дважды нельзя.
CREATE TABLE IF NOT EXISTS billing_credits (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    amount     INTEGER NOT NULL,
    reason     TEXT    NOT NULL,
    ref        TEXT    NOT NULL,
    spent_in   INTEGER NULL,                   -- платёж, в котором списан
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, reason, ref)
);

-- «Сезон вдвоём»: второе место отдаётся кодом.
CREATE TABLE IF NOT EXISTS billing_duo (
    code       TEXT    PRIMARY KEY,
    buyer_id   INTEGER NOT NULL,
    partner_id INTEGER NULL,
    payment_id INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    redeemed_at TEXT   NULL
);
