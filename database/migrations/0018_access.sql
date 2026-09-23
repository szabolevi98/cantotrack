-- Who sees which project.
--
-- A project is open to the team (every member) or private (its members and
-- the administrators). A guest — somebody from a client, say — sees only the
-- projects they were added to, whichever kind, and can read and comment but
-- not change the work.
ALTER TABLE users MODIFY role ENUM('admin', 'member', 'guest') NOT NULL DEFAULT 'member';

ALTER TABLE projects ADD COLUMN IF NOT EXISTS visibility ENUM('team', 'private') NOT NULL DEFAULT 'team' AFTER is_archived;

CREATE TABLE IF NOT EXISTS project_members (
    project_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (project_id, user_id),
    KEY project_members_user (user_id),
    CONSTRAINT project_members_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT project_members_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
