-- Ежедневные отметки. Одна строка на человека и дату.
-- Это самая горячая таблица продукта: сюда пишут каждый день все активные.

CREATE TABLE IF NOT EXISTS checkin_days (
    user_id     INTEGER NOT NULL,
    date        TEXT    NOT NULL,             -- YYYY-MM-DD
    day_number  INTEGER NULL,                 -- день плана, если план есть
    done        TEXT    NOT NULL,             -- yes | partial | no
    energy      INTEGER NULL,                 -- 1..5
    mood        INTEGER NULL,                 -- 1..5
    skip_reason TEXT    NULL,                 -- no_time | tired | sick | event | forgot | didnt_want
    value       REAL    NULL,                 -- число, если действие числовое
    note        TEXT    NULL,                 -- короткий ответ, если действие текстовое
    created_at  TEXT    NOT NULL,
    PRIMARY KEY (user_id, date)
);

CREATE INDEX IF NOT EXISTS idx_checkin_days_user ON checkin_days(user_id, date DESC);

-- Помехи: тўй, болезнь, командировка, пост, отпуск.
-- День, накрытый событием, не ломает серию (§ 01 досье — механика «Тўй»).
CREATE TABLE IF NOT EXISTS checkin_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    type       TEXT    NOT NULL,              -- toy | illness | trip | fasting | vacation
    date_from  TEXT    NOT NULL,
    date_to    TEXT    NOT NULL,
    note       TEXT    NULL,
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_checkin_events_user ON checkin_events(user_id, date_from, date_to);

-- Итог недели. Пишется при закрытии недели, чтобы не пересчитывать историю.
CREATE TABLE IF NOT EXISTS checkin_weeks (
    user_id     INTEGER NOT NULL,
    week_start  TEXT    NOT NULL,             -- понедельник, YYYY-MM-DD
    done_days   INTEGER NOT NULL DEFAULT 0,
    checkins    INTEGER NOT NULL DEFAULT 0,
    norm_days   INTEGER NOT NULL DEFAULT 4,
    kept        INTEGER NOT NULL DEFAULT 0,   -- норма выполнена
    shield_used INTEGER NOT NULL DEFAULT 0,   -- неделя спасена щитом
    closed_at   TEXT    NULL,
    PRIMARY KEY (user_id, week_start)
);

-- Щиты: два в календарный месяц (§ 06 досье).
CREATE TABLE IF NOT EXISTS checkin_shields (
    user_id    INTEGER NOT NULL,
    month      TEXT    NOT NULL,              -- YYYY-MM
    used       INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, month)
);

-- Возвраты после перерыва. Это источник главной метрики продукта —
-- Return Rate, поэтому хранится явно, а не выводится из истории.
CREATE TABLE IF NOT EXISTS checkin_returns (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    gap_days    INTEGER NOT NULL,
    gap_from    TEXT    NOT NULL,
    returned_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_checkin_returns_user ON checkin_returns(user_id);

-- Текущее состояние человека. Меняется при записи чек-ина и при
-- ночной проверке; хранится, чтобы не считать его на каждый запрос.
CREATE TABLE IF NOT EXISTS checkin_state (
    user_id     INTEGER PRIMARY KEY,
    state       TEXT    NOT NULL DEFAULT 'active', -- active | attention | recovery | dormant
    last_date   TEXT    NULL,
    gap_days    INTEGER NOT NULL DEFAULT 0,
    streak      INTEGER NOT NULL DEFAULT 0,   -- недель подряд с выполненной нормой
    best_streak INTEGER NOT NULL DEFAULT 0,
    updated_at  TEXT    NOT NULL
);
