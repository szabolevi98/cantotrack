-- Pictures in pages, and a conversation under each.
--
-- A file belonged to a ticket; it can now belong to a page instead — a
-- screenshot pasted into the page being written, stored and guarded the
-- same way as a ticket's. Exactly one of the two is set.
ALTER TABLE attachments MODIFY ticket_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE attachments ADD COLUMN IF NOT EXISTS page_id INT UNSIGNED NULL DEFAULT NULL AFTER ticket_id;
ALTER TABLE attachments ADD INDEX IF NOT EXISTS attachments_page (page_id, created_at);
ALTER TABLE attachments
    ADD CONSTRAINT attachments_page_fk FOREIGN KEY IF NOT EXISTS (page_id) REFERENCES pages (id) ON DELETE CASCADE;

-- What people say about a page, under it: a question about a decision, a
-- "this is out of date".
CREATE TABLE IF NOT EXISTS page_comments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    page_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY page_comments_page (page_id, created_at),
    CONSTRAINT page_comments_page_fk FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE,
    CONSTRAINT page_comments_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
