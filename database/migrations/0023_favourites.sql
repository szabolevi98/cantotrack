-- The tickets somebody keeps coming back to: starred on the ticket, a row of
-- their own in the week's grid, and first when "Log time" offers tickets.
CREATE TABLE IF NOT EXISTS ticket_favourites (
    user_id INT UNSIGNED NOT NULL,
    ticket_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id, ticket_id),
    KEY ticket_favourites_ticket (ticket_id),
    CONSTRAINT ticket_favourites_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT ticket_favourites_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
