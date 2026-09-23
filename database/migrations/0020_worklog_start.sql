-- When in the day an entry started, for the week drawn as a calendar. Optional:
-- most hours are written down as "2h on Tuesday", and those still are; an
-- entry with a start has its place on the day's hours, one without sits above
-- them.
ALTER TABLE worklogs ADD COLUMN IF NOT EXISTS started_at TIME NULL DEFAULT NULL AFTER work_date;
