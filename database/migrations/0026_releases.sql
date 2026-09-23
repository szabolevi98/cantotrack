-- Releases: what goes out together, and when. A ticket is in at most one —
-- the one it ships in — and a release is either still coming, with the day
-- it is meant for, or out, with the moment it went.
--
-- Called releases rather than versions because tickets already have a
-- `version`: the counter that catches two people saving the same ticket.
CREATE TABLE IF NOT EXISTS releases (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,

    -- As the team says it: "1.4", "2026.10", "Autumn relaunch".
    name VARCHAR(60) NOT NULL,
    description TEXT NULL,

    -- When work on it starts, and the day it is meant to go out: what the
    -- roadmap draws. Both optional; a release can be a name and nothing else.
    starts_on DATE NULL DEFAULT NULL,
    release_on DATE NULL DEFAULT NULL,

    -- Set when it went out; a released release takes no new tickets.
    released_at DATETIME NULL DEFAULT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY releases_project_name (project_id, name),
    KEY releases_project_state (project_id, released_at, release_on),
    CONSTRAINT releases_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Deleting a release leaves its tickets where they are, in no release.
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS release_id INT UNSIGNED NULL DEFAULT NULL AFTER parent_id;
ALTER TABLE tickets ADD INDEX IF NOT EXISTS tickets_release (release_id);
ALTER TABLE tickets ADD CONSTRAINT tickets_release_fk FOREIGN KEY IF NOT EXISTS (release_id) REFERENCES releases (id) ON DELETE SET NULL;
