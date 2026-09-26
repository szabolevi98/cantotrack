-- Signing out everywhere else.
--
-- A session lasts a year from the last click (see config.ini.dist), which
-- is long for a laptop that was lost. A session remembers when it was signed
-- in; one signed in before `sessions_valid_from` is over. Changing one's own
-- password sets it (the browser it was changed in stays signed in), a lost
-- password reset from the email sets it, and so does the "sign out
-- everywhere else" button on the profile.
ALTER TABLE users ADD COLUMN IF NOT EXISTS sessions_valid_from DATETIME NULL DEFAULT NULL AFTER password_changed_at;
