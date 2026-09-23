-- Subtasks: a ticket broken into the steps that make it up, each its own
-- ticket with its own person, column and hours.
--
-- One level, and no deeper: a subtask has no subtasks of its own, and a
-- ticket that has subtasks is not one. That is the depth people can keep in
-- their heads, and it keeps "the parent's hours" one plain sum — its own and
-- its subtasks' — for every report that asks.
--
-- SET NULL is only the last line: the application refuses to delete a ticket
-- that still has subtasks, so none is orphaned without somebody deciding it.
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS parent_id INT UNSIGNED NULL DEFAULT NULL AFTER epic_id;
ALTER TABLE tickets ADD INDEX IF NOT EXISTS tickets_parent (parent_id);
ALTER TABLE tickets ADD CONSTRAINT tickets_parent_fk FOREIGN KEY IF NOT EXISTS (parent_id) REFERENCES tickets (id) ON DELETE SET NULL;
