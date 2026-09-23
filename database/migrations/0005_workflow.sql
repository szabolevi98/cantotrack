-- The board's columns become each project's own.
--
-- Until now there were five, fixed in an ENUM on the tickets table. A team that
-- tests before review, or has no review at all, had to live with five columns
-- somebody else chose. Each project now has its own list — named, coloured,
-- ordered — and every column belongs to one of three categories. The
-- categories are what the application reasons with: "done" sets the date a
-- ticket was finished, "open" means anything not in a done column, and the
-- burndown counts what is not yet done. The names are only for people.

CREATE TABLE IF NOT EXISTS statuses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(60) NOT NULL,
    category ENUM('todo', 'in_progress', 'done') NOT NULL,
    colour ENUM('slate', 'blue', 'amber', 'green', 'violet', 'red') NOT NULL DEFAULT 'slate',

    -- How many tickets the column should hold at most, or none. Over it, the
    -- column says so; it does not refuse the ticket — a limit that blocks work
    -- gets raised to 99 on the first busy day.
    wip_limit SMALLINT UNSIGNED NULL DEFAULT NULL,

    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    KEY statuses_project (project_id, position),
    CONSTRAINT statuses_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- The five columns every project had, given to every project that exists.
INSERT INTO statuses (project_id, name, category, colour, position)
SELECT p.id, d.name, d.category, d.colour, d.position
FROM projects p
CROSS JOIN (
    SELECT 'Backlog' AS name, 'todo' AS category, 'slate' AS colour, 1 AS position
    UNION ALL SELECT 'To do', 'todo', 'slate', 2
    UNION ALL SELECT 'In progress', 'in_progress', 'blue', 3
    UNION ALL SELECT 'Review', 'in_progress', 'amber', 4
    UNION ALL SELECT 'Done', 'done', 'green', 5
) d
WHERE NOT EXISTS (SELECT 1 FROM statuses s WHERE s.project_id = p.id);

ALTER TABLE tickets ADD COLUMN IF NOT EXISTS status_id INT UNSIGNED NULL DEFAULT NULL AFTER status;

-- Each ticket to the column of its project that matches the one it was in.
UPDATE tickets t
JOIN statuses s
    ON s.project_id = t.project_id
   AND s.position = FIELD(t.status, 'backlog', 'todo', 'in_progress', 'review', 'done')
SET t.status_id = s.id
WHERE t.status_id IS NULL;

ALTER TABLE tickets MODIFY status_id INT UNSIGNED NOT NULL;

-- A column with tickets in it cannot be deleted out from under them; the
-- settings page moves them somewhere first.
ALTER TABLE tickets
    ADD CONSTRAINT tickets_status_fk FOREIGN KEY IF NOT EXISTS (status_id) REFERENCES statuses (id) ON DELETE RESTRICT;

-- The two indexes that named the old column, for the new one. The assignee's
-- new index comes first: the old one is what the assignee's foreign key
-- stands on, and MySQL will not let it go until another can take its place.
ALTER TABLE tickets DROP INDEX IF EXISTS tickets_board;
ALTER TABLE tickets ADD INDEX IF NOT EXISTS tickets_board (project_id, status_id);
ALTER TABLE tickets ADD INDEX IF NOT EXISTS tickets_assignee_status (assignee_id, status_id);
ALTER TABLE tickets DROP INDEX IF EXISTS tickets_assignee;

ALTER TABLE tickets DROP COLUMN IF EXISTS status;
