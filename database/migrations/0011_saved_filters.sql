-- Ticket lists somebody wants to come back to: "my open bugs", "everything
-- due this week in WEB". A filter is the list's own query string, kept as it
-- was — the list reads its filters from the address, so an address is all a
-- filter has to be.
CREATE TABLE IF NOT EXISTS saved_filters (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    query VARCHAR(1000) NOT NULL,

    -- Shown in everybody's sidebar, not only its maker's: "the release
    -- blockers" is a list a whole team looks at.
    is_shared TINYINT(1) NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY saved_filters_user (user_id, name),
    KEY saved_filters_shared (is_shared, name),
    CONSTRAINT saved_filters_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
