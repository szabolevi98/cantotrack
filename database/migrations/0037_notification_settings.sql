-- What each person wants to hear about, and how: for each kind of thing —
-- given a ticket, mentioned, a move or a comment on what they follow, other
-- changes — in the application and by email, in the application only, or
-- not at all. Kept as {"assigned": "email", …}; a kind not in it is "email".
ALTER TABLE users ADD COLUMN IF NOT EXISTS notify_prefs VARCHAR(500) NULL DEFAULT NULL AFTER notify_email;

-- A morning email of one's own: what a query finds, the tickets due this
-- week, and how many notifications wait unread. Sent once a day at most.
ALTER TABLE users ADD COLUMN IF NOT EXISTS digest_query VARCHAR(1000) NULL DEFAULT NULL AFTER notify_prefs;
ALTER TABLE users ADD COLUMN IF NOT EXISTS digest_sent_on DATE NULL DEFAULT NULL AFTER digest_query;
