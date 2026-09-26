-- Notifications that arrive together, and a ticket somebody does not want
-- to hear about.
--
-- A notification by email no longer goes the moment it is made. It waits a
-- couple of minutes (`mail_after`) for whatever else happens to the same
-- ticket, and then everything one person has waiting about it goes in one
-- message: a status changed, a comment and a new due date are one email,
-- not three. `mail_batch_id` is the notification the message was sent for —
-- every one it carried points at it, and they are all marked emailed when it
-- has gone. One already read in the application by then is left out: it was
-- seen.
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS mail_after DATETIME NULL DEFAULT NULL AFTER read_at;
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS mail_batch_id INT UNSIGNED NULL DEFAULT NULL AFTER mail_after;
ALTER TABLE notifications ADD INDEX IF NOT EXISTS notifications_mail (mail_after);
ALTER TABLE notifications ADD INDEX IF NOT EXISTS notifications_batch (mail_batch_id);

-- Old read notifications are cleared after a while (see Notifier::prune);
-- this is what finds them.
ALTER TABLE notifications ADD INDEX IF NOT EXISTS notifications_age (created_at);

-- A ticket muted: its reporter and its assignee always heard about it, and
-- had no way to stop but turning a whole kind off. Muted, a person is not
-- one of its followers any more; being given it or @mentioned in it still
-- reaches them, since that is about them. Following it again unmutes it.
CREATE TABLE IF NOT EXISTS ticket_mutes (
    ticket_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (ticket_id, user_id),
    KEY ticket_mutes_user (user_id),
    CONSTRAINT ticket_mutes_ticket_fk FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
    CONSTRAINT ticket_mutes_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
