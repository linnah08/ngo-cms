-- A declined card or failed IRIS payment no longer cancels the order: it stays
-- 'new' ("Чака плащане" in the admin) until the unpaid-order cron cancels it 3
-- days after checkout. This reopens the orders an older release cancelled on a
-- decline that are still inside those 3 days, so the shopper's "Опитай отново"
-- link keeps working and the cron still cancels and restocks them on time.
--
-- Told apart from an order an admin cancelled: a decline emails the shopper
-- (payment_failed_email_at), and an admin cancel of a shop order returns its
-- stock (stock_returned_at). Nothing in this window is ever paid or shipped.
--
-- Portable to MySQL 9 and MariaDB 10.5; a re-run changes nothing.

UPDATE orders
   SET status = 'new'
 WHERE status = 'cancelled'
   AND payment_status = 'pending'
   AND payment_method IN ('card', 'iris')
   AND type IN ('physical', 'donation')
   AND payment_failed_email_at IS NOT NULL
   AND unpaid_cancelled_at IS NULL
   AND stock_returned_at IS NULL
   AND created_at > NOW() - INTERVAL 3 DAY;
