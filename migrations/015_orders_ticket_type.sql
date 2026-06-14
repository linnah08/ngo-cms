ALTER TABLE orders
    MODIFY COLUMN type ENUM('physical','donation','ticket') NOT NULL;
