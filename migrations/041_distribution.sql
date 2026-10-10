-- 041: Distribution module — produced stock, distributors, hand-overs, sales.
--
-- Works for any shop product. Every batch / allocation / sale names the product
-- it is about (product_id) and, for a product with variants, the variant
-- (variant_id; NULL for products without variants). All stock figures are
-- worked out per product + variant in includes/distribution.php.
--
--   distribution_batches      stock produced or delivered (a print run, a
--                             delivery from the workshop…) — feeds the pool
--   distribution_allocations  stock taken out of the pool: to a distributor
--                             (with a numbered hand-over protocol PDF), to the
--                             online shop, sold in person, or given away free
--   distribution_sales        what a distributor reports as sold, and whether
--                             they have paid for it
--
-- Plain DDL that runs on both MariaDB 10.5 and MySQL 9; migrate.php makes a
-- re-run safe. INSERT IGNORE keeps the sequence row from failing a re-run.

CREATE TABLE IF NOT EXISTS distribution_distributors (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(150) NOT NULL,
  company_number  VARCHAR(20)  NOT NULL DEFAULT '',
  contact_name    VARCHAR(150) DEFAULT NULL,
  address         VARCHAR(255) DEFAULT NULL,
  email           VARCHAR(150) DEFAULT NULL,
  notes           TEXT,
  active          TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS distribution_batches (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  product_id   INT          NOT NULL,
  variant_id   INT          DEFAULT NULL,
  quantity     INT UNSIGNED NOT NULL,
  produced_on  DATE         NOT NULL,
  notes        TEXT,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dist_batches_item (product_id, variant_id),
  CONSTRAINT fk_dist_batches_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dist_batches_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- distributor_id is set only for destination = 'distributor'.
-- sample_recipient is set only for destination = 'sample'.
CREATE TABLE IF NOT EXISTS distribution_allocations (
  id                         INT AUTO_INCREMENT PRIMARY KEY,
  product_id                 INT          NOT NULL,
  variant_id                 INT          DEFAULT NULL,
  distributor_id             INT          DEFAULT NULL,
  destination                ENUM('distributor', 'online', 'personal', 'sample') NOT NULL DEFAULT 'distributor',
  sample_recipient           VARCHAR(150) DEFAULT NULL,
  quantity                   INT UNSIGNED NOT NULL,
  unit_price_eur             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  allocated_on               DATE         NOT NULL,
  notes                      TEXT,
  protocol_number            INT UNSIGNED DEFAULT NULL,
  protocol_formatted_number  VARCHAR(20)  DEFAULT NULL,
  protocol_file_path         VARCHAR(255) DEFAULT NULL,
  protocol_generated_at      DATETIME     DEFAULT NULL,
  created_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dist_alloc_item (product_id, variant_id),
  INDEX idx_dist_alloc_distributor (distributor_id),
  CONSTRAINT fk_dist_alloc_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dist_alloc_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dist_alloc_distributor FOREIGN KEY (distributor_id) REFERENCES distribution_distributors(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS distribution_sales (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  product_id           INT          NOT NULL,
  variant_id           INT          DEFAULT NULL,
  distributor_id       INT          NOT NULL,
  quantity             INT UNSIGNED NOT NULL,
  sale_date            DATE         NOT NULL,
  payment_received     TINYINT(1)   NOT NULL DEFAULT 0,
  payment_received_on  DATE         DEFAULT NULL,
  notes                TEXT,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_dist_sales_item (product_id, variant_id),
  INDEX idx_dist_sales_distributor (distributor_id),
  CONSTRAINT fk_dist_sales_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dist_sales_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
  CONSTRAINT fk_dist_sales_distributor FOREIGN KEY (distributor_id) REFERENCES distribution_distributors(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO document_sequences (type, last_number) VALUES ('distribution_protocol', 0);
