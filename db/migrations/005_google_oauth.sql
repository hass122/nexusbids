-- NexusBids Migration 005: Google OAuth Support
-- Adds google_id and auth_provider to users table
-- Makes password nullable for OAuth users

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `google_id` VARCHAR(255) NULL AFTER `email`,
  ADD COLUMN IF NOT EXISTS `auth_provider` VARCHAR(50) NOT NULL DEFAULT 'local' AFTER `google_id`,
  MODIFY COLUMN `password` VARCHAR(255) NULL DEFAULT NULL;

-- Unique index on google_id
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_google_id');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `users` ADD UNIQUE KEY `idx_users_google_id` (`google_id`)', 'SELECT 1');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
