-- Журнал событий для метрик (§ 13: «то, что нельзя восстановить задним
-- числом»). Только факты и числа — ни текста, ни имён. При удалении
-- аккаунта user_id обнуляется: агрегаты остаются, человек — нет (Р-19).
CREATE TABLE IF NOT EXISTS analytics_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NULL,
    name       TEXT    NOT NULL,
    day        TEXT    NOT NULL,             -- YYYY-MM-DD, к которому относится событие
    value      REAL    NULL,
    meta       TEXT    NULL,                 -- JSON: коды, не текст
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_analytics_name_day ON analytics_events(name, day);
CREATE INDEX IF NOT EXISTS idx_analytics_user ON analytics_events(user_id, name);
