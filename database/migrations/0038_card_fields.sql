-- What a card on a project's board shows: its priority, epic, labels,
-- parent and subtasks, assignee, due date, points and hours — or fewer, on a
-- board with a lot of cards. Kept as a list ("priority,assignee,due"); empty
-- means all of them, as before.
ALTER TABLE projects ADD COLUMN IF NOT EXISTS card_fields VARCHAR(200) NULL DEFAULT NULL AFTER visibility;
