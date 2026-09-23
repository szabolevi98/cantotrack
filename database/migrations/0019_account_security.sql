-- A face for each account, and a second step at sign-in.

-- The file of the person's picture under var/uploads/avatars, or none.
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar VARCHAR(80) NULL DEFAULT NULL AFTER working_week;

-- Two-step sign-in with an authenticator app (TOTP, RFC 6238). The secret is
-- set when the person confirms a first code; the last time step used is kept
-- so the same code cannot be used twice.
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) NULL DEFAULT NULL AFTER avatar;
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_enabled_at DATETIME NULL DEFAULT NULL AFTER totp_secret;
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_last_step BIGINT UNSIGNED NULL DEFAULT NULL AFTER totp_enabled_at;

-- Ten single-use codes for the day the phone is lost, hashed like passwords.
CREATE TABLE IF NOT EXISTS recovery_codes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,

    PRIMARY KEY (id),
    KEY recovery_codes_user (user_id),
    CONSTRAINT recovery_codes_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
