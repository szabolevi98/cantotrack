-- Tickets written the same way again and again: a template fills in the
-- new-ticket form — a bug report with its steps, a release checklist with
-- its subtasks — and a repeating ticket makes one from a template on its
-- day, every working day, every week or every month.
CREATE TABLE IF NOT EXISTS ticket_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,

    type VARCHAR(20) NOT NULL DEFAULT 'task',
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',

    -- {date}, {week} and {month} in the title become the day it is made
    -- for: "Server updates — {month}" is "Server updates — 2026-10".
    title VARCHAR(250) NOT NULL DEFAULT '',
    description TEXT NULL,
    labels VARCHAR(500) NULL DEFAULT NULL,
    estimate_minutes INT UNSIGNED NULL DEFAULT NULL,
    story_points SMALLINT UNSIGNED NULL DEFAULT NULL,

    -- The steps it is broken into, one a line; each becomes a subtask.
    subtasks TEXT NULL,

    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ticket_templates_project (project_id, name),
    CONSTRAINT ticket_templates_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT ticket_templates_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_tickets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_id INT UNSIGNED NOT NULL,
    assignee_id INT UNSIGNED NULL DEFAULT NULL,

    -- workdays: Monday to Friday, public holidays left out; weekly: on
    -- `weekday` (1 is Monday); monthly: on `month_day`, or the month's last
    -- day when it has fewer.
    frequency ENUM('workdays', 'weekly', 'monthly') NOT NULL,
    weekday TINYINT UNSIGNED NULL DEFAULT NULL,
    month_day TINYINT UNSIGNED NULL DEFAULT NULL,

    -- Due this many days after the day it is made for; empty for no due date.
    due_days SMALLINT UNSIGNED NULL DEFAULT NULL,

    -- The next day one is made for. A day missed — the server was down —
    -- is made up once, not once for every day missed.
    next_on DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_ticket_id INT UNSIGNED NULL DEFAULT NULL,
    last_made_at DATETIME NULL DEFAULT NULL,

    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY recurring_tickets_due (is_active, next_on),
    CONSTRAINT recurring_tickets_template_fk FOREIGN KEY (template_id) REFERENCES ticket_templates (id) ON DELETE CASCADE,
    CONSTRAINT recurring_tickets_assignee_fk FOREIGN KEY (assignee_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT recurring_tickets_last_fk FOREIGN KEY (last_ticket_id) REFERENCES tickets (id) ON DELETE SET NULL,
    CONSTRAINT recurring_tickets_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
