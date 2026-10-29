-- A paid donation can be marked „Сертификатът не е нужен“ (e.g. old orders whose
-- certificate would come out wrong): it then leaves the dashboard list
-- „Дарения без сертификат“. NULL = a certificate is still expected.
-- Clearing the mark (order page → „Върни“) sets it back to NULL.
--
-- Portable to MySQL 9 and MariaDB 10.5; migrate.php makes a re-run safe.

ALTER TABLE orders ADD COLUMN donation_cert_not_needed_at DATETIME NULL DEFAULT NULL;
