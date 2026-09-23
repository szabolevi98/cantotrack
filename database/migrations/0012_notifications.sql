-- Hearing about what happens to the tickets one cares about.

-- Who follows a ticket. The reporter and the assignee always hear about it;
-- this is everybody else who asked to, and everybody who took part — whoever
-- comments on a ticket or is mentioned in one is added, because they are now
-- part of the conversation.
CREATE TABLE IF NOT EXISTS ticket_watchers (
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (ticket_id, user_id),
    KEY ticket_watchers_user (user_id),
    CONSTRAINT ticket_watchers_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT ticket_watchers_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- One row per person per thing they should hear about. The same fields as a
-- history row, so the same words describe it; `reason` says why this person
-- was told (they watch it, it was given to them, they were mentioned).
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    ticket_id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NULL DEFAULT NULL,
    reason ENUM('watching', 'assigned', 'mentioned') NOT NULL DEFAULT 'watching',
    kind VARCHAR(30) NOT NULL,
    field VARCHAR(30) NULL DEFAULT NULL,
    old_value VARCHAR(255) NULL DEFAULT NULL,
    new_value VARCHAR(255) NULL DEFAULT NULL,
    read_at DATETIME NULL DEFAULT NULL,
    emailed_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY notifications_user (user_id, read_at, created_at),
    CONSTRAINT notifications_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT notifications_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT notifications_actor_fk FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN IF NOT EXISTS notify_email TINYINT(1) NOT NULL DEFAULT 1 AFTER theme;

-- A way back in for somebody who lost their password, by email. The token
-- itself is only in the link that was sent; what is stored is its hash, so a
-- copy of this table opens no accounts.
CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY password_resets_token (token_hash),
    KEY password_resets_user (user_id),
    CONSTRAINT password_resets_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
