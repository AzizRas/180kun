-- Временные облегчения плана (Р-13, Р-14). Сам план не переписывается:
-- на даты из диапазона нормы умножаются на коэффициент. Прошло — и план
-- снова такой, как был. Усложнение этим механизмом невозможно: factor ≤ 1.
CREATE TABLE IF NOT EXISTS planning_adjustments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    date_from  TEXT    NOT NULL,
    date_to    TEXT    NOT NULL,
    factor     REAL    NOT NULL,           -- 0 < factor ≤ 1
    level      TEXT    NOT NULL,           -- yellow | red | ramp
    reasons    TEXT    NOT NULL DEFAULT '[]',
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_planning_adjustments ON planning_adjustments(user_id, date_to);
