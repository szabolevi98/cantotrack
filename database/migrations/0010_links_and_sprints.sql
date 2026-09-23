-- How tickets depend on each other, and the sprints work is planned in.

-- One row per link, always stored in one direction: "CT-3 blocks CT-7" is
-- (source CT-3, target CT-7, blocks). "CT-7 is blocked by CT-3" is the same
-- row read from the other end, which is why there is no second kind for it.
CREATE TABLE IF NOT EXISTS ticket_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_id INT UNSIGNED NOT NULL,
    target_id INT UNSIGNED NOT NULL,
    kind ENUM('blocks', 'relates', 'duplicates') NOT NULL,
    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY ticket_links_unique (source_id, target_id, kind),
    KEY ticket_links_target (target_id, kind),
    CONSTRAINT ticket_links_source_fk FOREIGN KEY (source_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT ticket_links_target_fk FOREIGN KEY (target_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT ticket_links_user_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- A sprint: a stretch of time with a goal and the tickets meant to be done in
-- it. At most one per project is active at a time; the application keeps to
-- that, since MySQL has no partial unique index to say it.
CREATE TABLE IF NOT EXISTS sprints (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    goal VARCHAR(500) NULL DEFAULT NULL,
    starts_on DATE NULL DEFAULT NULL,
    ends_on DATE NULL DEFAULT NULL,
    state ENUM('planned', 'active', 'closed') NOT NULL DEFAULT 'planned',

    -- What the sprint set out to do, written down when it starts — the
    -- burndown and the velocity both measure against this, not against
    -- whatever the sprint holds by the end.
    committed_points SMALLINT UNSIGNED NULL DEFAULT NULL,
    committed_count SMALLINT UNSIGNED NULL DEFAULT NULL,

    -- And what it did, written down when it closes: a closed sprint's tickets
    -- move on, and the number would otherwise change after the fact.
    completed_points SMALLINT UNSIGNED NULL DEFAULT NULL,
    completed_count SMALLINT UNSIGNED NULL DEFAULT NULL,

    started_at DATETIME NULL DEFAULT NULL,
    closed_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY sprints_project (project_id, state),
    CONSTRAINT sprints_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE tickets ADD COLUMN IF NOT EXISTS sprint_id INT UNSIGNED NULL DEFAULT NULL AFTER epic_id;
ALTER TABLE tickets ADD INDEX IF NOT EXISTS tickets_sprint (sprint_id);
ALTER TABLE tickets
    ADD CONSTRAINT tickets_sprint_fk FOREIGN KEY IF NOT EXISTS (sprint_id) REFERENCES sprints (id) ON DELETE SET NULL;
