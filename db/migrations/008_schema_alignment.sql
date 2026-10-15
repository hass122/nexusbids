-- NexusBids Migration 008: Schema Alignment & Integrity Fixes
-- Safe, non-destructive alterations for MariaDB 10.4

-- 1. Support 'suspended' status in auctions table
ALTER TABLE `auctions` 
MODIFY COLUMN `auction_status` ENUM(
    'draft',
    'pending',
    'approved',
    'rejected',
    'scheduled',
    'active',
    'ended',
    'completed',
    'sold',
    'unsold',
    'cancelled',
    'relisted',
    'suspended'
) NOT NULL DEFAULT 'pending';

-- 2. Add receipt_path to payments table for direct payment receipt references
ALTER TABLE `payments`
ADD COLUMN IF NOT EXISTS `receipt_path` VARCHAR(255) NULL AFTER `gateway_response`;

-- 3. Add username column and unique index to users table
ALTER TABLE `users`
ADD COLUMN IF NOT EXISTS `username` VARCHAR(50) NULL AFTER `full_name`;

SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_username');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `users` ADD UNIQUE KEY `idx_users_username` (`username`)', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Unique key on auto_bids (auction_id, user_id) so ON DUPLICATE KEY works accurately
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'auto_bids' AND index_name = 'idx_auto_bids_user_auction');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `auto_bids` ADD UNIQUE KEY `idx_auto_bids_user_auction` (`auction_id`, `user_id`)', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Add seller response and evidence path columns to disputes table
ALTER TABLE `disputes`
ADD COLUMN IF NOT EXISTS `seller_response` TEXT NULL,
ADD COLUMN IF NOT EXISTS `evidence_path` VARCHAR(255) NULL;

-- 6. Ensure login_attempts has complete columns from server migration 002
ALTER TABLE `login_attempts`
ADD COLUMN IF NOT EXISTS `role` VARCHAR(50) NULL,
ADD COLUMN IF NOT EXISTS `full_name` VARCHAR(100) NULL;
