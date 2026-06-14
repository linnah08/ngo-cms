ALTER TABLE campaign_rewards
    ADD COLUMN title_en       VARCHAR(200) NOT NULL DEFAULT '' AFTER title,
    ADD COLUMN description_en TEXT         NOT NULL AFTER description;
