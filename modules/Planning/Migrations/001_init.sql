-- План на 180 дней. Владеет только модуль Planning.
-- Ежедневные действия не хранятся: они выводятся детерминированно
-- из недели и библиотеки, поэтому 180 строк на человека не нужны.

CREATE TABLE IF NOT EXISTS planning_plans (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    version      INTEGER NOT NULL DEFAULT 1,
    active       INTEGER NOT NULL DEFAULT 1,
    goal_dir     TEXT    NOT NULL,
    tier         TEXT    NOT NULL,
    start_date   TEXT    NOT NULL,           -- YYYY-MM-DD, день 1
    limited      INTEGER NOT NULL DEFAULT 0, -- ограничения по нагрузке
    meta         TEXT    NOT NULL,           -- JSON: итоговые цифры плана
    created_at   TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_planning_plans_user ON planning_plans(user_id, active);

CREATE TABLE IF NOT EXISTS planning_chapters (
    plan_id       INTEGER NOT NULL,
    n             INTEGER NOT NULL,
    theme         TEXT    NOT NULL,
    from_day      INTEGER NOT NULL,
    to_day        INTEGER NOT NULL,
    weight_target REAL    NULL,
    steps_target  INTEGER NOT NULL DEFAULT 0,
    minutes       INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (plan_id, n)
);

CREATE TABLE IF NOT EXISTS planning_weeks (
    plan_id       INTEGER NOT NULL,
    n             INTEGER NOT NULL,
    chapter       INTEGER NOT NULL,
    from_day      INTEGER NOT NULL,
    to_day        INTEGER NOT NULL,
    deload        INTEGER NOT NULL DEFAULT 0,
    steps_target  INTEGER NOT NULL DEFAULT 0,
    minutes       INTEGER NOT NULL DEFAULT 0,
    strength      INTEGER NOT NULL DEFAULT 0,
    weight_target REAL    NULL,
    norm_days     INTEGER NOT NULL DEFAULT 4,
    norm_checkins INTEGER NOT NULL DEFAULT 3,
    PRIMARY KEY (plan_id, n)
);
