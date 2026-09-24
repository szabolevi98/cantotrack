-- Which column a ticket may move to from which, and why a finished ticket
-- is finished.
--
-- Until now a ticket could go from any column to any other. A team that
-- wants every change looked at before it is done can now say so: a column
-- whose moves are limited lets its tickets go only to the columns listed for
-- it. A column that is not limited — every column, until somebody limits
-- one — lets them go anywhere, as before.
ALTER TABLE statuses ADD COLUMN IF NOT EXISTS moves_limited TINYINT(1) NOT NULL DEFAULT 0 AFTER wip_limit;

CREATE TABLE IF NOT EXISTS status_transitions (
    from_status_id INT UNSIGNED NOT NULL,
    to_status_id INT UNSIGNED NOT NULL,

    PRIMARY KEY (from_status_id, to_status_id),
    KEY status_transitions_to (to_status_id),
    CONSTRAINT status_transitions_from_fk FOREIGN KEY (from_status_id) REFERENCES statuses (id) ON DELETE CASCADE,
    CONSTRAINT status_transitions_to_fk FOREIGN KEY (to_status_id) REFERENCES statuses (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- A ticket in a done column is done — or was decided against, turned out to
-- be the same as another one, or could not be made to happen again. Empty
-- while the ticket is open; "done" unless somebody says otherwise.
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS resolution
    ENUM('done', 'wont_do', 'duplicate', 'cannot_reproduce') NULL DEFAULT NULL AFTER closed_at;

UPDATE tickets SET resolution = 'done' WHERE closed_at IS NOT NULL AND resolution IS NULL;
