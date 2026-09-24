-- What an hour is worth, and how many a project has.
--
-- A person has a rate; a project can have one of its own — the one agreed
-- with its client — which then counts instead. A billable hour is worth
-- the rate that counts for it. A project can be given a budget in hours, in
-- money, or both, and says how much of it is used; past a share of it
-- (80 in a hundred unless said otherwise) the administrators are told.
ALTER TABLE users ADD COLUMN IF NOT EXISTS hourly_rate DECIMAL(10, 2) NULL DEFAULT NULL AFTER role;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS hourly_rate DECIMAL(10, 2) NULL DEFAULT NULL AFTER billable_default;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS budget_hours DECIMAL(10, 2) NULL DEFAULT NULL AFTER hourly_rate;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS budget_amount DECIMAL(12, 2) NULL DEFAULT NULL AFTER budget_hours;
ALTER TABLE projects ADD COLUMN IF NOT EXISTS budget_alert TINYINT UNSIGNED NOT NULL DEFAULT 80 AFTER budget_amount;
