-- Модуль Identity владеет только таблицами с префиксом identity_.
-- Другие модули не обращаются к ним напрямую — только через контракт Auth.

CREATE TABLE IF NOT EXISTS identity_users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    phone         TEXT    NOT NULL UNIQUE,          -- в формате +998XXXXXXXXX
    email         TEXT    NULL,
    name          TEXT    NOT NULL DEFAULT '',
    password_hash TEXT    NOT NULL,
    lang          TEXT    NOT NULL DEFAULT 'ru',
    role          TEXT    NOT NULL DEFAULT 'user',  -- user | leader | moderator | admin
    status        TEXT    NOT NULL DEFAULT 'active',-- active | paused | blocked
    tg_id         TEXT    NULL UNIQUE,              -- заполнится в слайсе 2
    created_at    TEXT    NOT NULL,
    last_seen_at  TEXT    NULL
);

CREATE INDEX IF NOT EXISTS idx_identity_users_status ON identity_users(status);

CREATE TABLE IF NOT EXISTS identity_sessions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES identity_users(id) ON DELETE CASCADE,
    token_hash  TEXT    NOT NULL UNIQUE,            -- хранится только хеш токена
    created_at  TEXT    NOT NULL,
    expires_at  TEXT    NOT NULL,
    ip          TEXT    NULL,
    user_agent  TEXT    NULL
);

CREATE INDEX IF NOT EXISTS idx_identity_sessions_user ON identity_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_identity_sessions_exp  ON identity_sessions(expires_at);

-- Ограничение частоты входов: защита от перебора пароля.
CREATE TABLE IF NOT EXISTS identity_attempts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    ip         TEXT NOT NULL,
    phone      TEXT NULL,
    ok         INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_identity_attempts_ip ON identity_attempts(ip, created_at);
