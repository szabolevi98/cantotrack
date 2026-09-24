-- An epic as much a thing to talk about as a ticket: what was said about
-- it, what changed in it and by whom, who follows it, and the files that
-- belong to it — the design, the brief, the client's email.
CREATE TABLE IF NOT EXISTS epic_comments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    epic_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at DATETIME NULL DEFAULT NULL,

    PRIMARY KEY (id),
    KEY epic_comments_epic (epic_id, created_at),
    CONSTRAINT epic_comments_epic_fk FOREIGN KEY (epic_id) REFERENCES epics (id) ON DELETE CASCADE,
    CONSTRAINT epic_comments_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- The same shape as a ticket's history: one row a change, in the words
-- people saw.
CREATE TABLE IF NOT EXISTS epic_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    epic_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL DEFAULT NULL,
    kind VARCHAR(30) NOT NULL,
    field VARCHAR(30) NULL DEFAULT NULL,
    old_value VARCHAR(255) NULL DEFAULT NULL,
    new_value VARCHAR(255) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY epic_events_epic (epic_id, created_at),
    CONSTRAINT epic_events_epic_fk FOREIGN KEY (epic_id) REFERENCES epics (id) ON DELETE CASCADE,
    CONSTRAINT epic_events_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epic_watchers (
    epic_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    PRIMARY KEY (epic_id, user_id),
    KEY epic_watchers_user (user_id),
    CONSTRAINT epic_watchers_epic_fk FOREIGN KEY (epic_id) REFERENCES epics (id) ON DELETE CASCADE,
    CONSTRAINT epic_watchers_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- A file can belong to an epic, as it can to a ticket or a page.
ALTER TABLE attachments ADD COLUMN IF NOT EXISTS epic_id INT UNSIGNED NULL DEFAULT NULL AFTER page_id;
ALTER TABLE attachments ADD INDEX IF NOT EXISTS attachments_epic (epic_id, created_at);
ALTER TABLE attachments
    ADD CONSTRAINT attachments_epic_fk FOREIGN KEY IF NOT EXISTS (epic_id) REFERENCES epics (id) ON DELETE CASCADE;

-- And a notification can be about an epic: a comment on one somebody
-- follows, being mentioned in one, one finished or opened again.
ALTER TABLE notifications MODIFY ticket_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS epic_id INT UNSIGNED NULL DEFAULT NULL AFTER ticket_id;
ALTER TABLE notifications
    ADD CONSTRAINT notifications_epic_fk FOREIGN KEY IF NOT EXISTS (epic_id) REFERENCES epics (id) ON DELETE CASCADE;
