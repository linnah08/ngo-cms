-- 029_product_reviews.sql — moderated product reviews (for aggregateRating/review schema)
CREATE TABLE IF NOT EXISTS product_reviews (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id  INT          NOT NULL,
    lang        VARCHAR(5)   NOT NULL DEFAULT 'bg',
    author_name VARCHAR(100) NOT NULL,
    rating      TINYINT UNSIGNED NOT NULL,
    content     TEXT         NOT NULL,
    status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    ip          VARCHAR(45)  NOT NULL DEFAULT '',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_status (product_id, status),
    INDEX idx_status_date (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
