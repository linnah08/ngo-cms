-- 047: in-person card sales from SumUp take their products out of the shop's stock.
--
-- sumup_sales     : one row per SumUp sale the site has seen. The unique
--                   transaction_id is what makes a sale take stock at most once,
--                   however often the nightly check runs.
--   lines           : [{name, quantity, product_id, variant_id, done, skipped}] —
--                     done = taken out of stock, skipped = the admin said it isn't a shop product.
--   needs_attention : 1 while a line (or a sale with no products at all) waits
--                     for the admin to say which product it was.
--   refunded_at     : when a refund in SumUp put the sale's items back.
-- sumup_name_map  : what the admin chose for a SumUp product name, so the next
--                   sale with that name is matched without asking.

CREATE TABLE IF NOT EXISTS sumup_sales (
    id               INT           NOT NULL AUTO_INCREMENT,
    transaction_id   VARCHAR(64)   NOT NULL,
    transaction_code VARCHAR(32)   NULL,
    sold_at          DATETIME      NOT NULL,
    amount           DECIMAL(10,2) NOT NULL DEFAULT 0,
    currency         VARCHAR(3)    NOT NULL DEFAULT 'EUR',
    `lines`          JSON          NOT NULL,
    needs_attention  TINYINT(1)    NOT NULL DEFAULT 0,
    refunded_at      DATETIME      NULL,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sumup_tx (transaction_id),
    INDEX idx_sumup_attention (needs_attention, sold_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sumup_name_map (
    name_key    VARCHAR(191) NOT NULL,
    product_id  INT          NOT NULL,
    variant_id  INT          NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
