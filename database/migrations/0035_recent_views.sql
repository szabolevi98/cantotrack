-- What each person looked at lately — the tickets and pages they opened —
-- for the jump-anywhere box and the dashboard. One row a thing, the time
-- moved on each visit; the oldest are let go.
CREATE TABLE IF NOT EXISTS recent_views (
    user_id INT UNSIGNED NOT NULL,
    kind ENUM('ticket', 'page') NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (user_id, kind, item_id),
    KEY recent_views_latest (user_id, viewed_at),
    CONSTRAINT recent_views_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
