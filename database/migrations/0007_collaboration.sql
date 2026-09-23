-- Talking about a ticket, and remembering what happened to it.

-- The name a person is mentioned by in a comment: @anna. Taken from the part of
-- their address before the @, with anything but letters and digits left out,
-- and made unique by adding the account's number where two would collide.
ALTER TABLE users ADD COLUMN IF NOT EXISTS handle VARCHAR(40) NULL DEFAULT NULL AFTER short_name;

UPDATE users
SET handle = LEFT(REGEXP_REPLACE(LOWER(SUBSTRING_INDEX(email, '@', 1)), '[^a-z0-9]', ''), 30)
WHERE handle IS NULL;

UPDATE users SET handle = CONCAT('user', id) WHERE handle = '';

UPDATE users u
JOIN (SELECT handle FROM users GROUP BY handle HAVING COUNT(*) > 1) twice ON twice.handle = u.handle
SET u.handle = CONCAT(u.handle, u.id);

ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS users_handle_unique (handle);

CREATE TABLE IF NOT EXISTS comments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    -- Markdown, as it was typed. It is turned into HTML when it is shown, so
    -- a fix to the renderer fixes every comment ever written.
    body TEXT NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Set only when the text itself changes, which is what "edited" means to
    -- the person reading it.
    edited_at DATETIME NULL DEFAULT NULL,

    PRIMARY KEY (id),
    KEY comments_ticket (ticket_id, created_at),
    CONSTRAINT comments_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT comments_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- What happened to a ticket, one row per thing: it was created, a field
-- changed from one value to another, it moved columns, somebody logged time
-- or linked something. Written by the services as the change is made, never
-- reconstructed afterwards — a history worked out later is a guess.
--
-- The old and new values are kept as the words people saw ("Márk Tóth",
-- "In progress"), not as ids: a history that says "assignee 7 → 12" needs the
-- people of that day to be read, and the people change.
CREATE TABLE IF NOT EXISTS ticket_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL DEFAULT NULL,
    kind VARCHAR(30) NOT NULL,
    field VARCHAR(30) NULL DEFAULT NULL,
    old_value VARCHAR(255) NULL DEFAULT NULL,
    new_value VARCHAR(255) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ticket_events_ticket (ticket_id, created_at),
    KEY ticket_events_recent (created_at),
    CONSTRAINT ticket_events_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT ticket_events_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
