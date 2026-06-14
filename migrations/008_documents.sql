-- Add invoice/billing data to orders (B2B checkout + donation donor type)
ALTER TABLE orders ADD COLUMN invoice_data JSON NULL AFTER donation_message;

-- Sequential counters for document numbering (atomic, per type)
CREATE TABLE document_sequences (
    type        VARCHAR(30)   NOT NULL,
    last_number INT UNSIGNED  NOT NULL DEFAULT 0,
    PRIMARY KEY (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO document_sequences (type, last_number) VALUES
    ('invoice',       0),
    ('receipt',       0),
    ('donation_cert', 0);

-- Generated document records (one row per order × document type)
CREATE TABLE documents (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    order_id         INT          NOT NULL,
    type             ENUM('invoice','receipt','donation_cert') NOT NULL,
    number           INT UNSIGNED NOT NULL,
    formatted_number VARCHAR(20)  NOT NULL,
    file_path        VARCHAR(255) NOT NULL,
    generated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_order_type (order_id, type),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
