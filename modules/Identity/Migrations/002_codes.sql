-- Одноразовые коды: подтверждение номера при регистрации и сброс пароля.
-- Хранится только хеш кода — из дампа базы код не достать.

CREATE TABLE IF NOT EXISTS identity_codes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    phone       TEXT    NOT NULL,
    purpose     TEXT    NOT NULL,              -- signup | reset
    code_hash   TEXT    NOT NULL,
    attempts    INTEGER NOT NULL DEFAULT 0,
    verified_at TEXT    NULL,
    created_at  TEXT    NOT NULL,
    expires_at  TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_identity_codes_lookup ON identity_codes(phone, purpose, expires_at);

-- Telegram: связка аккаунта с Mini App. tg_id уже есть в identity_users,
-- добавляем данные профиля, которые отдаёт Telegram.
ALTER TABLE identity_users ADD COLUMN tg_username TEXT NULL;
ALTER TABLE identity_users ADD COLUMN tg_linked_at TEXT NULL;

-- Пароль перестаёт быть обязательным: вход через Telegram его не требует.
-- SQLite не умеет менять NOT NULL, поэтому договорённость на уровне кода:
-- пустая строка в password_hash = «пароль не задан, вход только через Telegram».
