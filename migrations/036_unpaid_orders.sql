-- Unpaid online orders (card / IRIS) — see includes/payment/unpaid_orders.php.
-- payment_failed_email_at : when the shopper was emailed that the payment did not
--   go through (at most once per order).
-- unpaid_cancelled_at     : when the order was auto-cancelled after 24h unpaid and
--   its items were put back in stock.
-- stock_returned_at       : when a cancelled order's items were put back in stock
--   (auto or manual cancel); cleared when they are taken out again on reopen.
--   Guards against restocking twice — see includes/order_stock.php.
ALTER TABLE `orders`
    ADD COLUMN `payment_failed_email_at` datetime DEFAULT NULL,
    ADD COLUMN `unpaid_cancelled_at`     datetime DEFAULT NULL,
    ADD COLUMN `stock_returned_at`       datetime DEFAULT NULL;
