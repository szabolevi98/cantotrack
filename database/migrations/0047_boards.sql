-- Boards: where sprints are planned, and what they are planned from.
--
-- Until now a sprint belonged to a project. A team that works on several
-- projects at once — a sprint holding tickets of CC2, PTS and OIL together —
-- had no way to say so. A board is a set of projects (and, if it likes, a
-- query narrowing them further), and a sprint belongs to a board.
--
-- Every project has a board of its own, holding only it: that is what its
-- Board and Backlog tabs are. `project_id` says whose own board it is, and a
-- board without one is shared, made by hand, with any projects in it.
CREATE TABLE IF NOT EXISTS boards (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    project_id INT UNSIGNED NULL DEFAULT NULL,

    -- A query in the ticket list's language, narrowing what the board shows
    -- of its projects: "labels = fejlesztés". None: all of them.
    query VARCHAR(1000) NULL DEFAULT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY boards_project (project_id),
    CONSTRAINT boards_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_projects (
    board_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,

    PRIMARY KEY (board_id, project_id),
    KEY board_projects_project (project_id),
    CONSTRAINT board_projects_board_fk FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE,
    CONSTRAINT board_projects_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- A shared board's columns. The projects' columns differ — each project has
-- its own — so a board says which of them go where. A status no column names
-- goes to the column called what it is called, and failing that by its
-- category: see Service\BoardColumns. A board with no columns of its own
-- takes every name its projects' columns have, in their order.
CREATE TABLE IF NOT EXISTS board_columns (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    board_id INT UNSIGNED NOT NULL,
    name VARCHAR(60) NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    KEY board_columns_board (board_id, position),
    CONSTRAINT board_columns_board_fk FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_column_statuses (
    column_id INT UNSIGNED NOT NULL,
    status_id INT UNSIGNED NOT NULL,

    PRIMARY KEY (column_id, status_id),
    KEY board_column_statuses_status (status_id),
    CONSTRAINT board_column_statuses_column_fk FOREIGN KEY (column_id) REFERENCES board_columns (id) ON DELETE CASCADE,
    CONSTRAINT board_column_statuses_status_fk FOREIGN KEY (status_id) REFERENCES statuses (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Every project that exists gets its own board.
INSERT INTO boards (name, project_id)
SELECT CONCAT(p.code, ' board'), p.id FROM projects p
WHERE NOT EXISTS (SELECT 1 FROM boards b WHERE b.project_id = p.id);

INSERT IGNORE INTO board_projects (board_id, project_id)
SELECT b.id, b.project_id FROM boards b WHERE b.project_id IS NOT NULL;

-- And its sprints move onto it.
ALTER TABLE sprints ADD COLUMN IF NOT EXISTS board_id INT UNSIGNED NULL DEFAULT NULL AFTER id;

UPDATE sprints sp JOIN boards b ON b.project_id = sp.project_id SET sp.board_id = b.id WHERE sp.board_id IS NULL;

ALTER TABLE sprints MODIFY board_id INT UNSIGNED NOT NULL;
ALTER TABLE sprints ADD INDEX IF NOT EXISTS sprints_board (board_id, state);
ALTER TABLE sprints
    ADD CONSTRAINT sprints_board_fk FOREIGN KEY IF NOT EXISTS (board_id) REFERENCES boards (id) ON DELETE CASCADE;

-- A sprint is no longer a project's. Last, so a run that stopped before it
-- still has the column the sprints were moved by.
ALTER TABLE sprints DROP FOREIGN KEY IF EXISTS sprints_project_fk;
ALTER TABLE sprints DROP INDEX IF EXISTS sprints_project;
ALTER TABLE sprints DROP COLUMN IF EXISTS project_id;
