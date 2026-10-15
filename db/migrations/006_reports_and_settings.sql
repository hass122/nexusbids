-- NexusBids Migration 006: Reports and Platform Settings
-- Safe, non-destructive alterations for MariaDB 10.4

-- 1. Create table `reports`
CREATE TABLE IF NOT EXISTS `reports` (
  `id` VARCHAR(36) NOT NULL PRIMARY KEY,
  `reporter_id` VARCHAR(50) NULL,
  `target_type` VARCHAR(50) NOT NULL DEFAULT 'listing',
  `target_id` VARCHAR(50) NULL,
  `reason` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `status` ENUM('pending', 'investigating', 'resolved', 'escalated', 'dismissed') DEFAULT 'pending',
  `resolution_notes` TEXT NULL,
  `resolved_by` INT NULL,
  `resolved_at` DATETIME NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_reports_status` (`status`),
  INDEX `idx_reports_target` (`target_type`, `target_id`),
  INDEX `idx_reports_created` (`created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create table `platform_settings`
CREATE TABLE IF NOT EXISTS `platform_settings` (
  `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
  `setting_value` TEXT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Default platform settings values
INSERT IGNORE INTO `platform_settings` (`setting_key`, `setting_value`) VALUES
('platform_fee_percent', '5.0'),
('anti_sniping_window_sec', '120'),
('min_bid_increment', '100'),
('kyc_mandatory_for_selling', 'true'),
('currency', 'PKR'),
('escrow_auto_release_days', '3'),
('maintenance_mode', 'false');
