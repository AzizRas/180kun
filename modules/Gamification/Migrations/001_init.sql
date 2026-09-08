-- Журнал начислений. Каждое начисление привязано к поводу и ссылке,
-- поэтому повторный вызов не начисляет второй раз — это и есть
-- защита от накрутки, а не отдельная проверка где-то сбоку.

CREATE TABLE IF NOT EXISTS gami_ledger (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    reason     TEXT    NOT NULL,       -- checkin | action | week_kept | comeback | chapter | onboarding
    ref        TEXT    NOT NULL,       -- дата, номер недели, номер главы
    amount     INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    UNIQUE (user_id, reason, ref)
);

CREATE INDEX IF NOT EXISTS idx_gami_ledger_user ON gami_ledger(user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS gami_profile (
    user_id    INTEGER PRIMARY KEY,
    xp         INTEGER NOT NULL DEFAULT 0,
    level      INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT    NOT NULL
);
