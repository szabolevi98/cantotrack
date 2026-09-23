-- Who the work is for, and which of the hours are billed.

CREATE TABLE IF NOT EXISTS clients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY clients_name_unique (name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE projects
    ADD COLUMN IF NOT EXISTS client_id INT UNSIGNED NULL DEFAULT NULL AFTER description,
    -- Whether an hour logged in this project is billed unless somebody says
    -- otherwise. Internal projects say no, client work says yes.
    ADD COLUMN IF NOT EXISTS billable_default TINYINT(1) NOT NULL DEFAULT 1 AFTER client_id;

ALTER TABLE projects
    ADD CONSTRAINT projects_client_fk FOREIGN KEY IF NOT EXISTS (client_id) REFERENCES clients (id) ON DELETE SET NULL;

-- Each hour carries its own answer, set when it is logged: a project's
-- default can change later without changing what was already invoiced.
ALTER TABLE worklogs ADD COLUMN IF NOT EXISTS billable TINYINT(1) NOT NULL DEFAULT 1 AFTER minutes;
