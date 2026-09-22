-- The work itself: projects, the epics that divide them, and the tickets people
-- actually pick up.
--
-- Three levels and no more. A fourth (sub-tasks) is the point where a tracker
-- starts needing a manual, and where every report has to decide whether a
-- parent's hours include its children's.

CREATE TABLE IF NOT EXISTS projects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- The short code a ticket carries: CT-14, ACME-3. Upper case and short, so
    -- it can be said out loud and typed into a search box.
    code VARCHAR(10) NOT NULL,

    name VARCHAR(160) NOT NULL,
    description TEXT NULL,

    -- The number the next ticket in this project gets. Kept here rather than
    -- counted with MAX(number)+1, because two people creating a ticket at the
    -- same moment would both read the same maximum and both write the same
    -- number. This column is incremented inside the same transaction that
    -- inserts the ticket, so the database decides the order.
    next_ticket_number INT UNSIGNED NOT NULL DEFAULT 1,

    -- Archived projects stay for their history; they are hidden from the lists
    -- and cannot take new tickets.
    is_archived TINYINT(1) NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY projects_code_unique (code),
    KEY projects_archived_name (is_archived, name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epics (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,

    -- An epic is open until its work is done; it is not a ticket and has no
    -- status beyond that.
    is_done TINYINT(1) NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY epics_project (project_id, is_done, title),

    -- Deleting a project takes its epics with it. That is the one place a
    -- cascade is right here: an epic outside a project has no meaning, whereas
    -- a ticket without an epic does.
    CONSTRAINT epics_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,

    -- The number within the project, which with the project's code makes the
    -- name everybody uses: CT-14.
    number INT UNSIGNED NOT NULL,

    -- A ticket may sit outside any epic — the small things always do, and
    -- forcing an "Everything else" epic on people is how that column fills with
    -- noise.
    epic_id INT UNSIGNED NULL DEFAULT NULL,

    title VARCHAR(250) NOT NULL,
    description TEXT NULL,

    -- The columns of the board, in the order work moves through them.
    status ENUM('backlog', 'todo', 'in_progress', 'review', 'done') NOT NULL DEFAULT 'backlog',
    priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal',

    -- Whose it is, and who asked for it. Both may outlive their user's active
    -- account, which is why users are deactivated rather than deleted.
    assignee_id INT UNSIGNED NULL DEFAULT NULL,
    reporter_id INT UNSIGNED NULL DEFAULT NULL,

    -- In minutes, like every other duration in this application. Hours as a
    -- decimal look friendlier until a day of quarter-hours adds up to 7.999999.
    estimate_minutes INT UNSIGNED NULL DEFAULT NULL,

    -- When it reached done. Kept as its own column rather than read from the
    -- history, because "what was finished last week" is a question asked far
    -- more often than anything else about a ticket's past.
    closed_at DATETIME NULL DEFAULT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY tickets_project_number (project_id, number),
    KEY tickets_board (project_id, status, priority),
    KEY tickets_assignee (assignee_id, status),
    KEY tickets_epic (epic_id),

    CONSTRAINT tickets_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,

    -- Deleting an epic leaves its tickets in the project, without an epic. The
    -- alternative is deleting work because the grouping around it was reorganised.
    CONSTRAINT tickets_epic_fk FOREIGN KEY (epic_id) REFERENCES epics (id) ON DELETE SET NULL,

    CONSTRAINT tickets_assignee_fk FOREIGN KEY (assignee_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT tickets_reporter_fk FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
