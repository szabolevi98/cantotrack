-- Searching by words with an index, rather than reading every ticket.
--
-- Until now the search box asked for LIKE '%words%', which reads every
-- title, description and page there is — fine for a few thousand, slow
-- after. A full-text index finds the rows that have the words at once, and
-- says how well each one matches, so the best match comes first. The title
-- has an index of its own, to weigh a word in the title above the same word
-- somewhere in the text.
ALTER TABLE tickets ADD FULLTEXT INDEX IF NOT EXISTS tickets_text (title, description);
ALTER TABLE tickets ADD FULLTEXT INDEX IF NOT EXISTS tickets_title_text (title);
ALTER TABLE comments ADD FULLTEXT INDEX IF NOT EXISTS comments_text (body);
ALTER TABLE pages ADD FULLTEXT INDEX IF NOT EXISTS pages_text (title, body);
ALTER TABLE pages ADD FULLTEXT INDEX IF NOT EXISTS pages_title_text (title);
