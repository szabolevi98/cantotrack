-- Signing in from an app.
--
-- The mobile app signs in with the email address and password (and the code,
-- with two-step sign-in on) and gets a personal access token back, made for
-- it. Such a token is a signed-in device rather than a script: it is listed
-- with the others on the profile, and it ends with the browser sessions — a
-- new password or "sign out everywhere else" signs the phone out too. A token
-- made by hand for a script is left alone by both.
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS from_sign_in TINYINT(1) NOT NULL DEFAULT 0 AFTER name;
