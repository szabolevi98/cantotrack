-- The hours.
--
-- One row per stretch of work: who, on which ticket, on which day, for how
-- long. Everything the timesheet shows is a sum over this table — there is no
-- second place where a total is kept, because two places that hold the same
-- number eventually hold two different numbers.

CREATE TABLE IF NOT EXISTS worklogs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    -- The day the work happened, not the day it was typed in. Friday afternoon's
    -- hours are regularly entered on Monday, and a timesheet that files them
    -- under Monday is a timesheet nobody trusts.
    work_date DATE NOT NULL,

    -- Minutes, like every duration in this application.
    minutes INT UNSIGNED NOT NULL,

    note VARCHAR(500) NULL DEFAULT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- The timesheet's own query: one person, one week.
    KEY worklogs_user_date (user_id, work_date),
    KEY worklogs_ticket (ticket_id),
    KEY worklogs_date (work_date),

    -- Deleting a ticket takes its hours with it, which is why deleting one is
    -- an administrator's job and asks first.
    CONSTRAINT worklogs_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,

    -- A person who has logged time cannot be deleted at all. Accounts are
    -- deactivated instead, and the hours keep pointing at whoever worked them.
    CONSTRAINT worklogs_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
