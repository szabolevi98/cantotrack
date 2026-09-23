-- Time, the rest of it: how much is left, and a clock that runs while somebody
-- works.

-- How much work is left on a ticket, as the person doing it last said. Empty
-- means "the estimate less what has been logged", which is what it is until
-- somebody knows better — and then they say so when they log time.
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS remaining_minutes INT UNSIGNED NULL DEFAULT NULL AFTER estimate_minutes;

-- A running clock, at most one per person: the moment they started on a
-- ticket. Stopping it logs the time since then.
CREATE TABLE IF NOT EXISTS timers (
    user_id INT UNSIGNED NOT NULL,
    ticket_id INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,

    PRIMARY KEY (user_id),
    KEY timers_ticket (ticket_id),
    CONSTRAINT timers_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT timers_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
