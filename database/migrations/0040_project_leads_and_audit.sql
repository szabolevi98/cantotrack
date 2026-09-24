-- A project of one's own to run, and a record of who changed what.
--
-- A member of a project can be its lead: they run its settings — its
-- columns and moves, its members, its fields, its cards — without being an
-- administrator of everything. Who sees a project and what its hours are
-- worth stay the administrators'.
ALTER TABLE project_members ADD COLUMN IF NOT EXISTS role ENUM('member', 'lead') NOT NULL DEFAULT 'member' AFTER user_id;

-- What was done to the installation and by whom: settings, people, access,
-- deletions, sign-ins. Written once and never changed; the name and the
-- thing's name are kept as they were then, so a deleted project or person
-- still reads in it.
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL DEFAULT NULL,
    user_name VARCHAR(160) NULL DEFAULT NULL,
    action VARCHAR(40) NOT NULL,
    subject_type VARCHAR(30) NULL DEFAULT NULL,
    subject_id INT UNSIGNED NULL DEFAULT NULL,
    subject_label VARCHAR(255) NULL DEFAULT NULL,
    details VARCHAR(1000) NULL DEFAULT NULL,
    ip VARCHAR(45) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY audit_log_latest (created_at),
    KEY audit_log_user (user_id, created_at),
    KEY audit_log_action (action, created_at),
    CONSTRAINT audit_log_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
