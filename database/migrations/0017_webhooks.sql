-- Webhooks: addresses told about every change, signed with a secret of
-- their own so the receiver can tell the message is really from here.
CREATE TABLE IF NOT EXISTS webhooks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    url VARCHAR(500) NOT NULL,
    secret CHAR(64) NOT NULL,
    -- "*" for everything, or a comma-separated list of events.
    events VARCHAR(255) NOT NULL DEFAULT '*',
    -- One project's changes, or every project's when empty.
    project_id INT UNSIGNED NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY webhooks_project (project_id),
    CONSTRAINT webhooks_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT webhooks_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Every message sent, or still to be sent: what it said, what came back.
-- A failed one is tried again later, a few times, further apart each time.
CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    webhook_id INT UNSIGNED NOT NULL,
    event VARCHAR(40) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    state ENUM('pending', 'delivered', 'failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    response_status SMALLINT UNSIGNED NULL DEFAULT NULL,
    response_body VARCHAR(1000) NULL DEFAULT NULL,
    error VARCHAR(255) NULL DEFAULT NULL,
    duration_ms INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME NULL DEFAULT NULL,

    PRIMARY KEY (id),
    KEY webhook_deliveries_due (state, next_attempt_at),
    KEY webhook_deliveries_hook (webhook_id, id),
    CONSTRAINT webhook_deliveries_hook_fk FOREIGN KEY (webhook_id) REFERENCES webhooks (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
