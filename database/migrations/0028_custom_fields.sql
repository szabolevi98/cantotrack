-- A project's own fields: what this project needs to know about a ticket
-- that the others do not — the platform a bug is on, the clinic it is for.
--
-- A value is kept as text, in one form per kind (a number as digits, a day
-- as Y-m-d, a yes as 1), so a field can change its list of choices without
-- the values moving to another table, and the query language can compare
-- numbers and days as what they are.
CREATE TABLE IF NOT EXISTS custom_fields (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id INT UNSIGNED NOT NULL,
    name VARCHAR(60) NOT NULL,

    -- text, number, select (one of a list), date, checkbox
    kind VARCHAR(16) NOT NULL DEFAULT 'text',

    -- For a select: the choices, one a line.
    options TEXT NULL,

    is_required TINYINT(1) NOT NULL DEFAULT 0,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY custom_fields_project_name (project_id, name),
    KEY custom_fields_name (name),
    CONSTRAINT custom_fields_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_field_values (
    ticket_id INT UNSIGNED NOT NULL,
    field_id INT UNSIGNED NOT NULL,
    value VARCHAR(500) NOT NULL,

    PRIMARY KEY (ticket_id, field_id),
    KEY ticket_field_values_field (field_id, value(40)),
    CONSTRAINT ticket_field_values_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT ticket_field_values_field_fk FOREIGN KEY (field_id) REFERENCES custom_fields (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
