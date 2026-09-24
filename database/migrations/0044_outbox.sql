-- Email waits here to be sent, rather than being sent while somebody waits
-- for their page: a slow or unreachable mail server then slows nothing
-- down, and a message it refused is tried again later instead of being lost.
-- bin/outbox.php sends what is due, every minute, from cron.
CREATE TABLE IF NOT EXISTS outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    to_address VARCHAR(255) NOT NULL,
    to_name VARCHAR(160) NOT NULL DEFAULT '',
    subject VARCHAR(255) NOT NULL,
    body MEDIUMTEXT NOT NULL,

    -- What it is — a notification, a digest, a lost password, a test — and,
    -- for a notification, which one, to be marked emailed once it has gone.
    kind VARCHAR(20) NOT NULL DEFAULT 'mail',
    notification_id BIGINT UNSIGNED NULL DEFAULT NULL,

    -- waiting: due at next_attempt_at; sending: taken by a run of the
    -- sender; sent; failed: tried as often as it will be.
    state ENUM('waiting', 'sending', 'sent', 'failed') NOT NULL DEFAULT 'waiting',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error VARCHAR(500) NULL DEFAULT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    taken_at DATETIME NULL DEFAULT NULL,
    sent_at DATETIME NULL DEFAULT NULL,

    PRIMARY KEY (id),
    KEY outbox_due (state, next_attempt_at),
    KEY outbox_sent (state, sent_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
