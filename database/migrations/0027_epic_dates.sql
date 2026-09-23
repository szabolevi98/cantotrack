-- When an epic's work is meant to happen: what the roadmap draws. Both are
-- optional — an epic without them is drawn from its tickets instead, from
-- the first one written down to the last one due or finished.
ALTER TABLE epics
    ADD COLUMN IF NOT EXISTS starts_on DATE NULL DEFAULT NULL AFTER description,
    ADD COLUMN IF NOT EXISTS ends_on DATE NULL DEFAULT NULL AFTER starts_on;
