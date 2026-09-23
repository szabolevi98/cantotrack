-- What kind of work a ticket is, when it is due, how big it is, and the labels
-- that cut across projects and epics.

ALTER TABLE tickets
    -- Three kinds and no more: a bug is something that used to work, a story
    -- is something somebody will be able to do, and a task is everything else.
    ADD COLUMN IF NOT EXISTS type ENUM('task', 'bug', 'story') NOT NULL DEFAULT 'task' AFTER number,

    -- A day, not a moment: "due Friday" is a promise about a day, and a time
    -- zone deciding whether 23:30 was still Friday is not a question anybody
    -- asks about a ticket.
    ADD COLUMN IF NOT EXISTS due_on DATE NULL DEFAULT NULL AFTER estimate_minutes,

    -- A relative size, for planning a sprint — deliberately not hours. The
    -- estimate says how long; the points say how big compared to the rest.
    ADD COLUMN IF NOT EXISTS story_points TINYINT UNSIGNED NULL DEFAULT NULL AFTER due_on;

ALTER TABLE tickets ADD INDEX IF NOT EXISTS tickets_due (due_on);

-- Labels are shared by every project: "security" means the same thing in all
-- of them, and a label per project is how the same word ends up spelled three
-- ways.
CREATE TABLE IF NOT EXISTS labels (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY labels_name_unique (name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_labels (
    ticket_id INT UNSIGNED NOT NULL,
    label_id INT UNSIGNED NOT NULL,

    PRIMARY KEY (ticket_id, label_id),
    KEY ticket_labels_label (label_id),
    CONSTRAINT ticket_labels_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT ticket_labels_label_fk FOREIGN KEY (label_id) REFERENCES labels (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
