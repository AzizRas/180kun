-- Тренер. Владеет только модуль Coach.

-- Согласие на передачу обезличенных данных ИИ-провайдеру (Р-19).
-- Без него модель не вызывается — работают шаблоны.
CREATE TABLE IF NOT EXISTS coach_consent (
    user_id    INTEGER PRIMARY KEY,
    ai         INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT    NOT NULL
);

-- Все показанные сообщения тренера. Нужны для оценки полезности,
-- расхода токенов и разбора отклонённых ответов модели.
CREATE TABLE IF NOT EXISTS coach_msgs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    kind        TEXT    NOT NULL,             -- checkin | week
    ref         TEXT    NOT NULL,             -- дата или неделя
    state       TEXT    NULL,                 -- steady | obstacle | overload | drift | quit
    text        TEXT    NOT NULL,
    source      TEXT    NOT NULL,             -- model | template
    rejected    TEXT    NULL,                 -- JSON: почему ответ модели не показан
    useful      INTEGER NULL,                 -- 1 полезно / 0 не очень
    calls       INTEGER NOT NULL DEFAULT 0,   -- обращений к модели (для суточного лимита)
    tokens_in   INTEGER NOT NULL DEFAULT 0,
    tokens_out  INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL,
    UNIQUE (user_id, kind, ref)
);

CREATE INDEX IF NOT EXISTS idx_coach_msgs_user ON coach_msgs(user_id, created_at);

-- Почему ответы модели не показывались. Только коды правил, без текста
-- и без привязки к человеку: этого достаточно, чтобы переписать промпт.
CREATE TABLE IF NOT EXISTS coach_rejections (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    kind       TEXT    NOT NULL,
    codes      TEXT    NOT NULL,                 -- JSON
    created_at TEXT    NOT NULL
);
