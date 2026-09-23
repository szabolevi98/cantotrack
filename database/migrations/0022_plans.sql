-- The hours planned ahead: somebody, on a ticket (or a project, when the work
-- is not a ticket yet), so many minutes a day from one day to another. The
-- days are kept as a stretch rather than one row a day, so a plan can be
-- changed in one place; the working days in it are worked out when it is
-- read, from the person's own week and the calendar.
CREATE TABLE IF NOT EXISTS plans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    ticket_id INT UNSIGNED NULL DEFAULT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    minutes_per_day SMALLINT UNSIGNED NOT NULL,
    note VARCHAR(200) NULL DEFAULT NULL,
    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY plans_user_days (user_id, starts_on, ends_on),
    KEY plans_project (project_id),
    CONSTRAINT plans_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT plans_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT plans_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT plans_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
