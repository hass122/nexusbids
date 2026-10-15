-- Run once on production. Safe: only adds tables/columns, nothing dropped.

-- ============================================================================
-- 1. Create table `admin_activity_log`
-- ============================================================================
CREATE TABLE IF NOT EXISTS `admin_activity_log` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT NOT NULL,
  `action_type` VARCHAR(50) NOT NULL,
  `target_type` VARCHAR(30) NULL,
  `target_id` INT NULL,
  `meta` JSON NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_admin_activity_created` (`created_at` DESC),
  INDEX `idx_admin_activity_admin_created` (`admin_id`, `created_at` DESC),
  INDEX `idx_admin_activity_target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. Create table `admin_notifications`
-- ============================================================================
CREATE TABLE IF NOT EXISTS `admin_notifications` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT NULL COMMENT 'NULL = broadcast to all admins',
  `type` VARCHAR(40) NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `link_url` VARCHAR(255) NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_admin_notifications_lookup` (`admin_id`, `is_read`, `created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. Add columns to `auctions` table
-- ============================================================================
ALTER TABLE `auctions`
  ADD COLUMN IF NOT EXISTS `report_count` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `watch_count` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `bid_count` INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `moderated_by` INT NULL,
  ADD COLUMN IF NOT EXISTS `moderated_at` TIMESTAMP NULL;

-- ============================================================================
-- 4. Add columns to `orders` table
-- ============================================================================
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `commission_amount` DECIMAL(12,2) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `escrow_released_at` TIMESTAMP NULL;
