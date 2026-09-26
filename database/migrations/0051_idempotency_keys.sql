-- A request that may arrive twice.
--
-- A phone on a train sends "log 45 minutes", the connection drops before the
-- answer comes back, and the app cannot know whether the server heard it. It
-- sends the request again — and without this, the 45 minutes are there twice.
--
-- A request that carries an Idempotency-Key header is written down here under
-- that key, for a day. The same key again from the same account gets the
-- first answer again, word for word, and nothing is done a second time; the
-- same key with a different request is refused, as a client bug.
--
-- `fingerprint` is the SHA-256 of the method, the path and the body, to tell
-- a resend from a different request. `status` is NULL while the first one is
-- still being worked on. Only answers that succeeded are kept: a request
-- that failed changed nothing, so its key is let go and may be tried again.
CREATE TABLE IF NOT EXISTS api_idempotency_keys (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    idem_key VARCHAR(100) NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    status SMALLINT UNSIGNED NULL DEFAULT NULL,
    location VARCHAR(500) NULL DEFAULT NULL,
    body MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY api_idempotency_keys_user_key (user_id, idem_key),
    KEY api_idempotency_keys_created (created_at),
    CONSTRAINT api_idempotency_keys_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_bin;
