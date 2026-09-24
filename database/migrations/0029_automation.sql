-- Automation: rules that do the small things people keep forgetting — move
-- the parent on when its last subtask is done, hand an urgent bug to the
-- person on call, nudge a ticket the day after it was due.
--
-- A rule is a trigger (what happened), a condition in the query language
-- (which tickets it is for) and a short list of actions. Its log says what
-- it did, and to which ticket; the ticket's own history says so too.
CREATE TABLE IF NOT EXISTS automation_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- One project's, or every project's when empty.
    project_id INT UNSIGNED NULL DEFAULT NULL,

    name VARCHAR(120) NOT NULL,
    `trigger` VARCHAR(30) NOT NULL,
    `condition` VARCHAR(1000) NOT NULL DEFAULT '',

    -- [{"type": "status", "value": "Done"}, …]
    actions TEXT NOT NULL,

    is_active TINYINT(1) NOT NULL DEFAULT 1,
    run_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_run_at DATETIME NULL DEFAULT NULL,

    -- Who made it: its changes are made in their name, marked as the rule's.
    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY automation_rules_trigger (`trigger`, is_active),
    CONSTRAINT automation_rules_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT automation_rules_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_id INT UNSIGNED NOT NULL,
    ticket_id INT UNSIGNED NULL DEFAULT NULL,

    -- done, or failed with the reason
    outcome VARCHAR(10) NOT NULL,
    message VARCHAR(500) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY automation_log_rule (rule_id, created_at),
    KEY automation_log_ticket (ticket_id, rule_id, created_at),
    CONSTRAINT automation_log_rule_fk FOREIGN KEY (rule_id) REFERENCES automation_rules (id) ON DELETE CASCADE,
    CONSTRAINT automation_log_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- A line in a ticket's history that a rule wrote says which rule.
ALTER TABLE ticket_events ADD COLUMN IF NOT EXISTS via VARCHAR(120) NULL DEFAULT NULL AFTER new_value;
