-- Сигналы для модератора-человека. Текст сообщения НЕ хранится:
-- достаточно знать, кому и откуда нужна помощь.
CREATE TABLE IF NOT EXISTS safety_alerts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    source      TEXT    NOT NULL,             -- checkin_note | group | coach
    category    TEXT    NOT NULL,             -- self_harm | purging | food_refusal
    created_at  TEXT    NOT NULL,
    resolved_at TEXT    NULL,
    resolved_by INTEGER NULL
);

CREATE INDEX IF NOT EXISTS idx_safety_alerts_open ON safety_alerts(resolved_at, created_at);

-- Режим тишины: соревновательные элементы скрыты, пока модератор не снимет.
CREATE TABLE IF NOT EXISTS safety_state (
    user_id     INTEGER PRIMARY KEY,
    quiet_until TEXT    NOT NULL,
    updated_at  TEXT    NOT NULL
);
