-- Фото. Владеет только модуль Media.
-- Файлы лежат в MEDIA_DIR (по умолчанию DATA_DIR/media), вне публичной
-- папки. Исходник не хранится: только пересжатые копии без метаданных.

CREATE TABLE IF NOT EXISTS media_files (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER NOT NULL,
    token          TEXT    NOT NULL UNIQUE,          -- имя на диске и в ссылке, 128 бит случайности
    purpose        TEXT    NOT NULL,                 -- feed
    width          INTEGER NOT NULL,
    height         INTEGER NOT NULL,
    bytes          INTEGER NOT NULL,                 -- полный размер + превью
    status         TEXT    NOT NULL DEFAULT 'active', -- active | deleted
    day            TEXT    NOT NULL,                 -- YYYY-MM-DD, для суточного лимита
    created_at     TEXT    NOT NULL,
    expires_at     TEXT    NULL,                     -- после этого файл стирается
    deleted_at     TEXT    NULL,
    deleted_reason TEXT    NULL                      -- owner | moderator | expired | erased
);

CREATE INDEX IF NOT EXISTS idx_media_files_user ON media_files(user_id, day);
CREATE INDEX IF NOT EXISTS idx_media_files_expiry ON media_files(status, expires_at);
