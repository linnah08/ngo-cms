-- Communication history of an order: every email sent to its buyer
-- (confirmation, shipped, cancelled, payment problem, credit note, certificate,
-- ticket), recorded by send_order_mail() in includes/mailer.php and shown on the
-- order and pledge pages. sent_by / sent_by_name are NULL for automatic emails.
CREATE TABLE IF NOT EXISTS order_emails (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    order_id     INT          NOT NULL,
    recipient    VARCHAR(150) NOT NULL,
    subject      VARCHAR(255) NOT NULL,
    body         LONGTEXT     NOT NULL,
    template_key VARCHAR(50)  NULL,
    sent_by      INT          NULL,
    sent_by_name VARCHAR(150) NULL,
    status       ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_order (order_id),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
