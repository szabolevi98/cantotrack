-- Files on a ticket: screenshots, mostly, and the occasional log or PDF.
--
-- The file itself lives under var/uploads, outside the document root, under a
-- random name of its own: it is only ever handed out by the application,
-- after checking who is asking, and never by the web server directly. The
-- name it was uploaded with is kept here, for showing and for downloading.
CREATE TABLE IF NOT EXISTS attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(255) NOT NULL,

    -- What the file is, as worked out from its contents at upload — never the
    -- type the browser claimed, which is whatever the uploader wanted it to be.
    mime VARCHAR(100) NOT NULL,
    size INT UNSIGNED NOT NULL,
    width SMALLINT UNSIGNED NULL DEFAULT NULL,
    height SMALLINT UNSIGNED NULL DEFAULT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY attachments_ticket (ticket_id, created_at),
    CONSTRAINT attachments_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT attachments_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
