-- What a client is billed for: a statement of one client's billable hours
-- over a span of days — a month, nearly always.
--
-- A draft claims its hours (worklogs.statement_id), so the same hour cannot
-- go on two statements, and is added up from them whenever it is looked at.
-- Issuing one gives it its number and writes down what each hour was worth
-- then (worklogs.billed_rate) and the totals: a rate changed next year does
-- not change what was sent this year, and its hours no longer change at all.
CREATE TABLE IF NOT EXISTS statements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    period_from DATE NOT NULL,
    period_to DATE NOT NULL,

    -- Given when it is issued: 2026-007, counted within the year.
    number VARCHAR(20) NULL DEFAULT NULL,
    state ENUM('draft', 'issued') NOT NULL DEFAULT 'draft',

    -- Who it is addressed to, as it is printed — the client's name and
    -- address, taken from their last statement.
    bill_to TEXT NULL,
    note TEXT NULL,

    currency CHAR(3) NULL DEFAULT NULL,
    minutes INT UNSIGNED NULL DEFAULT NULL,
    amount DECIMAL(12, 2) NULL DEFAULT NULL,

    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    issued_by INT UNSIGNED NULL DEFAULT NULL,
    issued_at DATETIME NULL DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY statements_number_unique (number),
    KEY statements_client (client_id, period_from),
    CONSTRAINT statements_client_fk FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
    CONSTRAINT statements_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT statements_issued_by_fk FOREIGN KEY (issued_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE worklogs ADD COLUMN IF NOT EXISTS statement_id INT UNSIGNED NULL DEFAULT NULL AFTER work_type_id;
ALTER TABLE worklogs ADD COLUMN IF NOT EXISTS billed_rate DECIMAL(10, 2) NULL DEFAULT NULL AFTER statement_id;
ALTER TABLE worklogs ADD INDEX IF NOT EXISTS worklogs_statement (statement_id);
ALTER TABLE worklogs
    ADD CONSTRAINT worklogs_statement_fk FOREIGN KEY IF NOT EXISTS (statement_id) REFERENCES statements (id) ON DELETE SET NULL;
