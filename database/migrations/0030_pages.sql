-- A project's pages: what the team knows and has decided, next to the work
-- itself — how the project runs, the decisions and why, the release notes.
--
-- A page sits under another page, as deep as people like; every save keeps
-- the version before it, so nothing written is ever lost to an edit, and the
-- tickets a page mentions are remembered so the ticket can say where it is
-- written about.
CREATE TABLE IF NOT EXISTS pages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL DEFAULT NULL,
    title VARCHAR(200) NOT NULL,
    body MEDIUMTEXT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,

    -- Raised on every save: an edit made against an older one is refused
    -- rather than quietly written over somebody else's.
    version INT UNSIGNED NOT NULL DEFAULT 1,

    created_by INT UNSIGNED NULL DEFAULT NULL,
    updated_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY pages_tree (project_id, parent_id, position, title),
    CONSTRAINT pages_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT pages_parent_fk FOREIGN KEY (parent_id) REFERENCES pages (id) ON DELETE SET NULL,
    CONSTRAINT pages_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT pages_updated_by_fk FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_versions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    page_id INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    body MEDIUMTEXT NULL,
    user_id INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY page_versions_page_version (page_id, version),
    CONSTRAINT page_versions_page_fk FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE,
    CONSTRAINT page_versions_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_tickets (
    page_id INT UNSIGNED NOT NULL,
    ticket_id INT UNSIGNED NOT NULL,

    PRIMARY KEY (page_id, ticket_id),
    KEY page_tickets_ticket (ticket_id),
    CONSTRAINT page_tickets_page_fk FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE,
    CONSTRAINT page_tickets_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
