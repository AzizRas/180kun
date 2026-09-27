-- Созвоны сквада. Владеет только модуль Calls.

CREATE TABLE IF NOT EXISTS calls_sessions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id      INTEGER NOT NULL,
    created_by   INTEGER NOT NULL,                -- лидер; 0 — аккаунт удалён
    starts_at    TEXT    NOT NULL,                -- UTC, ISO 8601
    duration_min INTEGER NOT NULL DEFAULT 30,
    topic        TEXT    NOT NULL DEFAULT 'week', -- week | support | plan | free
    note         TEXT    NOT NULL DEFAULT '',
    status       TEXT    NOT NULL DEFAULT 'scheduled', -- scheduled | cancelled
    provider     TEXT    NOT NULL,
    created_at   TEXT    NOT NULL,
    announced_at TEXT    NULL,
    reminded_at  TEXT    NULL,
    cancelled_at TEXT    NULL
);

CREATE INDEX IF NOT EXISTS idx_calls_sessions_team ON calls_sessions(team_id, status, starts_at);
CREATE INDEX IF NOT EXISTS idx_calls_sessions_due ON calls_sessions(status, reminded_at, starts_at);

CREATE TABLE IF NOT EXISTS calls_rsvp (
    session_id INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    answer     TEXT    NOT NULL,                  -- yes | no | maybe
    joined_at  TEXT    NULL,                      -- нажал «подключиться»
    updated_at TEXT    NOT NULL,
    PRIMARY KEY (session_id, user_id)
);
