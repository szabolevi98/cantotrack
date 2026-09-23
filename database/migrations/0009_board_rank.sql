-- The order of the cards within a column, as people arrange it.
--
-- Until now a column was sorted by priority and then by age, which is an
-- order nobody chose. A rank lets the board say "this one next" by position:
-- dragging a card between two others gives it a rank between theirs.
--
-- Ranks are spaced 1024 apart, so a card can be dropped between two others
-- ten times over before the numbers run out; when they do, the application
-- renumbers that column and carries on. Only the order means anything, never
-- the numbers.
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS `rank` BIGINT NOT NULL DEFAULT 0 AFTER story_points;

-- The order the board showed until now, kept as the starting order.
SET @position := 0;
UPDATE tickets
SET `rank` = (@position := @position + 1024)
WHERE `rank` = 0
ORDER BY FIELD(priority, 'urgent', 'high', 'normal', 'low'), id DESC;

ALTER TABLE tickets ADD INDEX IF NOT EXISTS tickets_rank (project_id, status_id, `rank`);
