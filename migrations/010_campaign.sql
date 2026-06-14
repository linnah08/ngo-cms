-- Campaign reward tiers (admin-configurable, up to 4 active)
CREATE TABLE campaign_rewards (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    position    TINYINT      NOT NULL DEFAULT 0,
    title       VARCHAR(200) NOT NULL,
    description TEXT         NOT NULL,
    amount_eur  DECIMAL(10,2) NOT NULL,
    active      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed 4 placeholder rewards (admin edits them)
INSERT INTO campaign_rewards (position, title, description, amount_eur, active) VALUES
    (1, 'Малък приятел',    'Награден пакет — малък',   25.00, 1),
    (2, 'Среден приятел',   'Награден пакет — среден',  50.00, 1),
    (3, 'Голям приятел',    'Награден пакет — голям',  100.00, 1),
    (4, 'Голям поддръжник', 'Награден пакет — пълен',  250.00, 1);

-- Individual funding pledges
CREATE TABLE campaign_pledges (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    pledge_number    VARCHAR(30)  NOT NULL UNIQUE,
    name             VARCHAR(200) NOT NULL,
    email            VARCHAR(200) NOT NULL,
    amount_eur       DECIMAL(10,2) NOT NULL,
    reward_id        INT          NULL,
    delivery_address JSON         NULL,
    payment_status   ENUM('pending','paid','failed','reversed') NOT NULL DEFAULT 'pending',
    dsk_order_id     VARCHAR(100) NULL,
    cert_number      INT UNSIGNED NULL,
    cert_path        VARCHAR(255) NULL,
    reward_shipped   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reward_id) REFERENCES campaign_rewards(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
