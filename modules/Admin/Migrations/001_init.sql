-- Журнал действий модераторов: у участника есть право на объяснение (§ 11),
-- значит, каждое решение должно быть записано.
CREATE TABLE IF NOT EXISTS admin_audit (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id   INTEGER NULL,
    module     TEXT    NOT NULL,
    action     TEXT    NOT NULL,
    target_id  INTEGER NULL,
    meta       TEXT    NULL,
    created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_admin_audit_created ON admin_audit(created_at);
CREATE INDEX IF NOT EXISTS idx_admin_audit_target ON admin_audit(target_id);
