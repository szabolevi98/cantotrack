-- What kind of work an hour was: development, a meeting, support. Kept by the
-- administrators as a short list; an entry may say one, and the reports can
-- add the hours up by it. A type that is no longer used is retired rather
-- than deleted, so the hours that had it keep saying so.
CREATE TABLE IF NOT EXISTS work_types (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(60) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY work_types_name (name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

ALTER TABLE worklogs ADD COLUMN IF NOT EXISTS work_type_id INT UNSIGNED NULL DEFAULT NULL AFTER billable;
ALTER TABLE worklogs ADD CONSTRAINT worklogs_work_type_fk FOREIGN KEY IF NOT EXISTS (work_type_id) REFERENCES work_types (id) ON DELETE SET NULL;
