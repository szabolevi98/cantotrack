-- What a working week is, for whom, and which weeks are closed.

-- Days nobody is expected to work: public holidays, the office's own days off.
CREATE TABLE IF NOT EXISTS holidays (
    day DATE NOT NULL,
    name VARCHAR(120) NOT NULL,

    PRIMARY KEY (day)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- A person's own week, in minutes per day from Monday to Sunday — "240,240,
-- 240,240,240,0,0" for somebody on half days. Empty means the usual one:
-- work.hours_per_day from Monday to Friday.
ALTER TABLE users ADD COLUMN IF NOT EXISTS working_week VARCHAR(60) NULL DEFAULT NULL AFTER notify_email;

-- Days somebody is away: a holiday of their own, illness, anything else. A
-- day away is not expected to be full, and the timesheet says why.
CREATE TABLE IF NOT EXISTS absences (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    kind ENUM('vacation', 'sick', 'other') NOT NULL DEFAULT 'vacation',
    note VARCHAR(200) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY absences_user (user_id, starts_on, ends_on),
    CONSTRAINT absences_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- A week handed in, and what became of it. While a week is handed in or
-- approved, its hours do not change; a rejected one is open again, with the
-- reason next to it.
CREATE TABLE IF NOT EXISTS timesheet_weeks (
    user_id INT UNSIGNED NOT NULL,
    week_start DATE NOT NULL,
    state ENUM('submitted', 'approved', 'rejected') NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT UNSIGNED NULL DEFAULT NULL,
    reviewed_at DATETIME NULL DEFAULT NULL,
    comment VARCHAR(500) NULL DEFAULT NULL,

    PRIMARY KEY (user_id, week_start),
    KEY timesheet_weeks_state (state, week_start),
    CONSTRAINT timesheet_weeks_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT timesheet_weeks_reviewer_fk FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Settings an administrator changes in the application rather than in the
-- configuration file: the date up to which hours are closed, for one.
CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(60) NOT NULL,
    value VARCHAR(1000) NULL DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
