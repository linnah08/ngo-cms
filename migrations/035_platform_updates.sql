-- Audit log for the self-update mechanism (includes/updater.php). One row per
-- update attempt (success, partial — some customized files were skipped, or
-- failed), so an admin can see what happened without digging through logs.
CREATE TABLE IF NOT EXISTS `platform_updates` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `from_version`   VARCHAR(50)  NOT NULL,
    `to_version`     VARCHAR(50)  NOT NULL,
    `status`         VARCHAR(20)  NOT NULL,
    `skipped_files`  TEXT NULL,
    `error_message`  TEXT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
