-- migrations/019_product_variants.sql

ALTER TABLE products
    MODIFY COLUMN `type` ENUM('standard','print','variant') NOT NULL DEFAULT 'standard',
    ADD COLUMN `variant_attributes` JSON NULL AFTER `variants`;

CREATE TABLE IF NOT EXISTS product_variants (
    id          INT          NOT NULL AUTO_INCREMENT,
    product_id  INT          NOT NULL,
    label_bg    VARCHAR(255) NOT NULL,
    label_en    VARCHAR(255) NULL,
    attributes  JSON         NOT NULL,
    image       VARCHAR(255) NULL,
    stock       INT          NOT NULL DEFAULT 0,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_product_id (product_id),
    CONSTRAINT fk_pv_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
