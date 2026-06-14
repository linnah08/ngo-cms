ALTER TABLE products
    ADD COLUMN `type`     ENUM('standard','print') NOT NULL DEFAULT 'standard' AFTER `active`,
    ADD COLUMN `variants` JSON NULL                                            AFTER `type`;
