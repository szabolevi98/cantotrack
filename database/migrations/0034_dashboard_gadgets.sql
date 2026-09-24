-- A dashboard of one's own: pieces a person adds to the page they land on,
-- each made of a query — the tickets it finds as a list, how many there
-- are, or how they split by a field (status, assignee, priority…).
CREATE TABLE IF NOT EXISTS dashboard_gadgets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    kind ENUM('list', 'count', 'breakdown') NOT NULL,
    title VARCHAR(120) NOT NULL,
    query VARCHAR(1000) NOT NULL DEFAULT '',
    group_by VARCHAR(30) NULL DEFAULT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY dashboard_gadgets_user (user_id, position),
    CONSTRAINT dashboard_gadgets_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
