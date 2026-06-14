CREATE TABLE IF NOT EXISTS contact_submissions (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(200) NOT NULL,
    email      VARCHAR(255) NOT NULL,
    topic      VARCHAR(200) NOT NULL DEFAULT '',
    message    TEXT         NOT NULL,
    status     ENUM('new','read','archived') NOT NULL DEFAULT 'new',
    ip         VARCHAR(45)  NOT NULL DEFAULT '',
    lang       VARCHAR(5)   NOT NULL DEFAULT 'bg',
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_date (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
