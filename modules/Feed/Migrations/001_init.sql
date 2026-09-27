-- Лента сквада. Владеет только модуль Feed.
-- Кто в скваде — не храним: спрашиваем у контракта Team при каждом
-- просмотре. Ушёл из сквада — его посты у бывших сокомандников пропадают.

CREATE TABLE IF NOT EXISTS feed_posts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL,
    team_id       INTEGER NOT NULL,
    media_id      INTEGER NULL,                     -- фото в модуле Media
    tag           TEXT    NOT NULL DEFAULT 'other', -- plate | gym | walk | workout | other
    caption       TEXT    NOT NULL DEFAULT '',
    status        TEXT    NOT NULL DEFAULT 'visible', -- visible | hidden | deleted
    hidden_reason TEXT    NULL,                     -- body | face | reports
    day           TEXT    NOT NULL,                 -- YYYY-MM-DD (UTC), для суточного лимита
    created_at    TEXT    NOT NULL,
    announced_at  TEXT    NULL,                     -- сообщили в чат сквада
    deleted_at    TEXT    NULL,
    deleted_by    TEXT    NULL                      -- owner | moderator
);

CREATE INDEX IF NOT EXISTS idx_feed_posts_team ON feed_posts(team_id, status, id);
CREATE INDEX IF NOT EXISTS idx_feed_posts_user ON feed_posts(user_id, day);

-- «Поддержал» — одна реакция, без счётчиков в интерфейсе.
CREATE TABLE IF NOT EXISTS feed_support (
    post_id    INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    created_at TEXT    NOT NULL,
    PRIMARY KEY (post_id, user_id)
);

CREATE TABLE IF NOT EXISTS feed_reports (
    post_id     INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    reason      TEXT    NOT NULL,                   -- body | face | offensive | spam | other
    created_at  TEXT    NOT NULL,
    resolved_at TEXT    NULL,
    resolution  TEXT    NULL,                       -- kept | removed
    PRIMARY KEY (post_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_feed_reports_open ON feed_reports(resolved_at, post_id);
