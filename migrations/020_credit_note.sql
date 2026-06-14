-- Extend documents.type ENUM with credit_note and storno_receipt
ALTER TABLE documents
    MODIFY COLUMN type ENUM('invoice','receipt','donation_cert','credit_note','storno_receipt') NOT NULL;
