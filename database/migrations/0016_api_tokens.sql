-- Personal access tokens: what a script or another program signs in with
-- when it talks to the API. Only a hash is kept — the token itself is shown
-- once, when it is made — and the first characters, so a person can tell
-- their tokens apart in the list.
CREATE TABLE IF NOT EXISTS api_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    prefix CHAR(10) NOT NULL,
    last_used_at DATETIME NULL DEFAULT NULL,
    expires_on DATE NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY api_tokens_hash (token_hash),
    KEY api_tokens_user (user_id),
    CONSTRAINT api_tokens_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
