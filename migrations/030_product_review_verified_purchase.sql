-- 030_product_review_verified_purchase.sql — email + confirmed-purchase label on product reviews
ALTER TABLE product_reviews ADD COLUMN author_email VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE product_reviews ADD COLUMN verified_purchase TINYINT(1) NOT NULL DEFAULT 0;
