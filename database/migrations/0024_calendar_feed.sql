-- The private iCal address of somebody's own calendar, to offer their
-- meetings as entries on the week's calendar. As good as a password to that
-- calendar, so it is shown back to them only in part.
ALTER TABLE users ADD COLUMN IF NOT EXISTS calendar_feed VARCHAR(500) NULL DEFAULT NULL AFTER totp_last_step;
