-- The people who sign in.
--
-- Every statement here is written so that running the migration again changes
-- nothing: the installation on a live server is the same command as the one on
-- a laptop, and it has to be safe to repeat after a failed run.

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,

    -- 190 rather than 255: a unique index on utf8mb4 may be at most 3072 bytes,
    -- and older MySQL builds cap it at 767 — 190 characters fits both.
    email VARCHAR(190) NOT NULL,

    password_hash VARCHAR(255) NOT NULL,

    -- Two roles and no permission table. An admin sets up projects and people;
    -- a member works tickets and logs time. Anything finer than this in a
    -- tracker of this size is configuration nobody gets right.
    role ENUM('admin', 'member') NOT NULL DEFAULT 'member',

    -- People are deactivated, never deleted: tickets carry who reported them and
    -- worklogs carry whose hours they were, and both have to keep pointing at a
    -- real row.
    is_active TINYINT(1) NOT NULL DEFAULT 1,

    last_login_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY users_email_unique (email),
    KEY users_active_name (is_active, name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
