-- One's own dates the other way round: a private address a calendar
-- (Google, Outlook, a phone) subscribes to, with the due dates of one's
-- tickets, the releases coming and the ends of the sprints. Calendars do not
-- sign in, so the address itself is the key; only its hash is kept, and a
-- new one undoes the old.
ALTER TABLE users ADD COLUMN IF NOT EXISTS calendar_export_hash CHAR(64) NULL DEFAULT NULL AFTER calendar_feed;
ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS users_calendar_export (calendar_export_hash);
