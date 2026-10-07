-- 042: Pre-orders. A product with preorder_enabled stays buyable once its stock
-- runs out; the stock then goes below zero, and that negative number is how many
-- pre-ordered items are still waiting to be sent. preorder_note_bg / _en is the
-- admin's delivery estimate ("Expected: August"), shown on the product page,
-- cart, checkout and the order emails.
--
-- Plain ALTER TABLE (no IF NOT EXISTS — MariaDB-only); migrate.php skips
-- "Duplicate column" on a re-run.

ALTER TABLE products ADD COLUMN preorder_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN preorder_note_bg VARCHAR(255) NULL;
ALTER TABLE products ADD COLUMN preorder_note_en VARCHAR(255) NULL;
