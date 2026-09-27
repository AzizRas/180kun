-- Данные браслета и телефона. Владеет только модуль Wearable.
-- Одна строка на человека и день. Калории и фазы сна не храним вовсе (§ 08).

CREATE TABLE IF NOT EXISTS wear_days (
    user_id        INTEGER NOT NULL,
    date           TEXT    NOT NULL,              -- YYYY-MM-DD
    steps          INTEGER NULL,
    active_min     INTEGER NULL,
    rhr            INTEGER NULL,                  -- ЧСС покоя
    sleep_min      INTEGER NULL,
    source         TEXT    NOT NULL DEFAULT 'manual', -- manual | import
    suspect        INTEGER NOT NULL DEFAULT 0,    -- шаги не прошли проверку правдоподобия
    suspect_reason TEXT    NULL,
    updated_at     TEXT    NOT NULL,
    PRIMARY KEY (user_id, date)
);

-- Вес — раз в неделю, в тренде (§ 08: дневные значения не показываем).
CREATE TABLE IF NOT EXISTS wear_weights (
    user_id    INTEGER NOT NULL,
    date       TEXT    NOT NULL,
    weight_kg  REAL    NOT NULL,
    waist_cm   REAL    NULL,
    created_at TEXT    NOT NULL,
    PRIMARY KEY (user_id, date)
);
