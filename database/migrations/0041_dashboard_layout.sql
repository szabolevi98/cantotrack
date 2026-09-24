-- The whole dashboard made of pieces: not only the ones a person writes a
-- query for, but the ones every dashboard had — the numbers, one's own
-- tickets, one's week, the sprints and releases, what happened lately —
-- each shown or hidden, and each in the main column or the side one, in the
-- order it was put.
ALTER TABLE dashboard_gadgets MODIFY kind VARCHAR(20) NOT NULL;
ALTER TABLE dashboard_gadgets ADD COLUMN IF NOT EXISTS area ENUM('main', 'side') NOT NULL DEFAULT 'main' AFTER group_by;

-- Whether a person's dashboard was laid out: until it is, it is given the
-- pieces every dashboard starts with, around the ones they had made.
ALTER TABLE users ADD COLUMN IF NOT EXISTS dashboard_laid_out TINYINT(1) NOT NULL DEFAULT 0 AFTER digest_sent_on;
