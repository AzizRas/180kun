-- Профиль, собранный на онбординге. Владеет только модуль Onboarding.

CREATE TABLE IF NOT EXISTS onboarding_profiles (
    user_id        INTEGER PRIMARY KEY,
    goal_dir       TEXT    NOT NULL,              -- lose | gain
    sex            TEXT    NOT NULL,              -- male | female
    birth_year     INTEGER NOT NULL,
    height_cm      INTEGER NOT NULL,
    weight_kg      REAL    NOT NULL,
    target_kg      REAL    NULL,
    time_budget    INTEGER NOT NULL DEFAULT 30,   -- минут в день на цель
    window         TEXT    NOT NULL DEFAULT 'evening', -- morning | day | evening
    social         INTEGER NOT NULL DEFAULT 2,    -- 1 тихо .. 3 активно
    experience     TEXT    NOT NULL DEFAULT '',   -- на чём срывался раньше
    constraints    TEXT    NOT NULL DEFAULT '',   -- травмы, «нельзя бегать»
    fasting        INTEGER NOT NULL DEFAULT 0,    -- соблюдает пост
    tier           TEXT    NULL,                  -- T0..T3, ставится после baseline
    status         TEXT    NOT NULL DEFAULT 'draft', -- draft | zero_cycle | ready | rejected
    reject_reason  TEXT    NULL,
    created_at     TEXT    NOT NULL,
    completed_at   TEXT    NULL
);

-- Анкета противопоказаний. Хранится отдельно: это чувствительные данные,
-- и они не должны попадать в общий профиль, который читают другие модули.
CREATE TABLE IF NOT EXISTS onboarding_screening (
    user_id     INTEGER PRIMARY KEY,
    answers     TEXT    NOT NULL,                 -- JSON: ключ вопроса => 0/1
    passed      INTEGER NOT NULL DEFAULT 0,
    needs_doctor INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);

-- Нулевой цикл: 7 дней измерений до старта.
CREATE TABLE IF NOT EXISTS onboarding_baseline (
    user_id     INTEGER PRIMARY KEY,
    steps_med   INTEGER NULL,
    workouts    INTEGER NOT NULL DEFAULT 0,       -- тренировок в неделю
    rhr         INTEGER NULL,                     -- ЧСС покоя
    sleep_min   INTEGER NULL,                     -- средняя длительность сна
    weight_kg   REAL    NULL,
    days        INTEGER NOT NULL DEFAULT 0,       -- сколько дней собрано
    source      TEXT    NOT NULL DEFAULT 'manual',-- manual | wearable
    captured_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_onboarding_profiles_status ON onboarding_profiles(status);
