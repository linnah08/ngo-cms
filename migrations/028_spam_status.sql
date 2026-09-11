-- Add a 'spam' status so bulk-marking spam in admin/comments.php is distinct
-- from a normal reject/archive, and only spam-tagged rows feed the
-- auto-learned domain blocklist (includes/spam_filter.php).
--
-- No prior migration creates the `comments` table — it is only ever
-- lazily created (identical schema) by api/comment-submit.php,
-- templates/comments.php, and admin/comments.php. On a fresh DB where
-- none of those has run yet, the ALTER TABLE below would fail with
-- "Table 'comments' doesn't exist" and halt migrate.php. Create it here
-- first (with the OLD enum) so this migration is self-sufficient; the
-- MODIFY COLUMN immediately after widens the enum whether the table was
-- just created or already existed.
--
-- contact_submissions IS created by migrations/012_contact_submissions.sql,
-- so no such guard is needed for it here.
CREATE TABLE IF NOT EXISTS comments (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    article_slug VARCHAR(255) NOT NULL,
    lang         VARCHAR(5)   NOT NULL DEFAULT 'bg',
    author_name  VARCHAR(100) NOT NULL,
    author_email VARCHAR(255) NOT NULL,
    content      TEXT         NOT NULL,
    status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    ip           VARCHAR(45)  NOT NULL DEFAULT '',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_slug_status (article_slug, status),
    INDEX idx_status_date (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE comments            MODIFY COLUMN status ENUM('pending','approved','rejected','spam') NOT NULL DEFAULT 'pending';
ALTER TABLE contact_submissions MODIFY COLUMN status ENUM('new','read','archived','spam')          NOT NULL DEFAULT 'new';
