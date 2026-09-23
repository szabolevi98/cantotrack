-- The first round of fixes after the audit.

-- Hours are no longer deleted along with the ticket they were logged against.
-- They are what gets invoiced, and one click on "delete ticket" used to take a
-- month of them with it. The application refuses the delete first, with a
-- sentence; this makes the database refuse it too, for anything that skips the
-- application.
ALTER TABLE worklogs DROP FOREIGN KEY IF EXISTS worklogs_ticket_fk;
ALTER TABLE worklogs
    ADD CONSTRAINT worklogs_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE RESTRICT;

-- Raised on every save of the edit form, and checked by it: two people editing
-- the same ticket used to mean the second save silently undid the first.
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS version INT UNSIGNED NOT NULL DEFAULT 1 AFTER closed_at;

-- What a person is called day to day, which is not always the first word of
-- their name: "Szabó Levente" is Levente, and the dashboard greeted him by his
-- surname. Plus the two preferences the profile page sets.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS short_name VARCHAR(60) NULL DEFAULT NULL AFTER name,
    ADD COLUMN IF NOT EXISTS locale VARCHAR(5) NULL DEFAULT NULL AFTER role,
    ADD COLUMN IF NOT EXISTS theme ENUM('system', 'light', 'dark') NOT NULL DEFAULT 'system' AFTER locale,
    ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL DEFAULT NULL AFTER password_hash;

-- Failed sign-ins, for the limit in front of the login form. Rows older than a
-- day are removed as new ones are written; nothing here is kept as a record.
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY login_attempts_email (email, attempted_at),
    KEY login_attempts_ip (ip, attempted_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
