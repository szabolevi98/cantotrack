-- A client as more than a name on a project: who they are on paper, and who
-- to talk to. The address and the tax number are what a statement is
-- addressed to (see 0043).
ALTER TABLE clients ADD COLUMN IF NOT EXISTS billing_address TEXT NULL AFTER name;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS tax_number VARCHAR(40) NULL DEFAULT NULL AFTER billing_address;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS contact_name VARCHAR(120) NULL DEFAULT NULL AFTER tax_number;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS contact_email VARCHAR(190) NULL DEFAULT NULL AFTER contact_name;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS note TEXT NULL AFTER contact_email;
