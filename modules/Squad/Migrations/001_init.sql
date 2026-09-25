-- Сквады. Владеет только модуль Squad.
-- Чужие данные (профиль, чек-ины) сюда не копируются целиком: в пул
-- кладётся снимок полей, нужных алгоритму подбора, на момент заявки.

-- Волна набора (Р-07): заявки копятся, в день X сквады стартуют вместе.
CREATE TABLE IF NOT EXISTS squad_waves (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL DEFAULT '',
    start_date  TEXT    NOT NULL,                 -- YYYY-MM-DD, день 1 для всех
    status      TEXT    NOT NULL DEFAULT 'open',  -- open | proposed | started
    created_at  TEXT    NOT NULL,
    matched_at  TEXT    NULL,
    started_at  TEXT    NULL
);

-- Пул кандидатов: снимок полей для алгоритма на момент заявки.
CREATE TABLE IF NOT EXISTS squad_pool (
    user_id      INTEGER PRIMARY KEY,
    wave_id      INTEGER NULL,
    status       TEXT    NOT NULL DEFAULT 'waiting', -- waiting | proposed | placed | withdrawn
    goal_dir     TEXT    NOT NULL,
    sex          TEXT    NOT NULL,
    age          INTEGER NOT NULL,
    lang         TEXT    NOT NULL DEFAULT 'ru',
    tier         TEXT    NOT NULL DEFAULT 'T0',
    steps        INTEGER NOT NULL DEFAULT 0,
    time_budget  INTEGER NOT NULL DEFAULT 30,
    bmi          REAL    NOT NULL DEFAULT 0,
    window       TEXT    NOT NULL DEFAULT 'evening',
    social       INTEGER NOT NULL DEFAULT 2,
    experience   TEXT    NOT NULL DEFAULT '',
    mixed_ok     INTEGER NOT NULL DEFAULT 0,      -- «не важно» про пол сквада
    commit_level INTEGER NOT NULL DEFAULT 2,      -- 1 пробую .. 3 настроен всерьёз
    prefs_set    INTEGER NOT NULL DEFAULT 0,
    joined_at    TEXT    NOT NULL,
    updated_at   TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_squad_pool_wave ON squad_pool(wave_id, status);

-- Коллеги и родня: никогда в одном скваде (Р-06). Пара хранится a < b.
CREATE TABLE IF NOT EXISTS squad_relations (
    user_a     INTEGER NOT NULL,
    user_b     INTEGER NOT NULL,
    kind       TEXT    NOT NULL DEFAULT 'known',
    created_at TEXT    NOT NULL,
    PRIMARY KEY (user_a, user_b)
);

CREATE TABLE IF NOT EXISTS squad_squads (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    wave_id           INTEGER NULL,
    code              TEXT    NOT NULL UNIQUE,    -- для привязки группы Telegram: /bind CODE
    lang              TEXT    NOT NULL,
    goal_dir          TEXT    NOT NULL,
    sex               TEXT    NOT NULL,           -- male | female | mixed
    base_tier         TEXT    NOT NULL,
    status            TEXT    NOT NULL DEFAULT 'proposed', -- proposed | approved | active | disbanded | finished
    cost              REAL    NOT NULL DEFAULT 0,
    flags             TEXT    NOT NULL DEFAULT '[]', -- JSON: что показать модератору
    leader_id         INTEGER NULL,
    leader_checked    INTEGER NOT NULL DEFAULT 0, -- выбор первого лидера по чату на 3-й день сделан
    tg_chat_id        TEXT    NULL UNIQUE,
    invite_link       TEXT    NULL,
    created_at        TEXT    NOT NULL,
    approved_at       TEXT    NULL,
    approved_by       INTEGER NULL,
    started_on        TEXT    NULL,               -- YYYY-MM-DD, день 1
    first_contact_at  TEXT    NULL,               -- карточки опубликованы в чате
    first_reaction_at TEXT    NULL,               -- первое сообщение живого участника
    low_since         TEXT    NULL,               -- с какого дня активных меньше трёх
    disbanded_at      TEXT    NULL
);

CREATE INDEX IF NOT EXISTS idx_squad_squads_status ON squad_squads(status, wave_id);

CREATE TABLE IF NOT EXISTS squad_members (
    squad_id          INTEGER NOT NULL,
    user_id           INTEGER NOT NULL,
    seat              INTEGER NOT NULL DEFAULT 0, -- порядок ротации лидера
    role              TEXT    NOT NULL DEFAULT 'member', -- member | anchor
    status            TEXT    NOT NULL DEFAULT 'active', -- active | paused | recovery | left
    status_since      TEXT    NOT NULL,
    joined_on         TEXT    NOT NULL,           -- YYYY-MM-DD
    left_on           TEXT    NULL,
    left_reason       TEXT    NULL,               -- silent | moved | removed | disbanded
    intro_at          TEXT    NULL,               -- новичка представили скваду
    first_reaction_at TEXT    NULL,               -- кто-то из сквада ответил новичку
    last_chat_at      TEXT    NULL,
    last_action_at    TEXT    NULL,               -- действия в панели лидера
    needs_help        INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (squad_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_squad_members_user ON squad_members(user_id, status);

-- Сроки лидерства: 14 дней, по кругу (§ 09).
CREATE TABLE IF NOT EXISTS squad_leader_terms (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    squad_id   INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    started_on TEXT    NOT NULL,
    ended_on   TEXT    NULL,
    end_reason TEXT    NULL,                      -- completed | declined | inactive | left | provisional
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_squad_leader_terms ON squad_leader_terms(squad_id, ended_on);

-- Командный счёт недели (Р-08). Пишется один раз за закрытую неделю.
CREATE TABLE IF NOT EXISTS squad_week_scores (
    squad_id       INTEGER NOT NULL,
    week_no        INTEGER NOT NULL,
    week_start     TEXT    NOT NULL,
    members        INTEGER NOT NULL,
    median_pct     REAL    NOT NULL,
    min_pct        REAL    NOT NULL,
    returned_share REAL    NOT NULL,
    score          REAL    NOT NULL,
    computed_at    TEXT    NOT NULL,
    PRIMARY KEY (squad_id, week_no)
);

-- Активность в чате по дням: для выбора первого лидера и метрики
-- «время до первой реакции». Текст сообщений не храним.
CREATE TABLE IF NOT EXISTS squad_chat_days (
    squad_id INTEGER NOT NULL,
    user_id  INTEGER NOT NULL,
    day      TEXT    NOT NULL,
    messages INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (squad_id, user_id, day)
);
