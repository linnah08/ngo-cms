-- 022: Add speedy_shipment_id column to orders for Speedy courier shipments

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS speedy_shipment_id VARCHAR(100) DEFAULT NULL;
