-- NexusBids Migration 003: User profile enhancements
-- Adds city column to users table if not already present

ALTER TABLE `users`
ADD COLUMN IF NOT EXISTS `city` VARCHAR(100) NULL AFTER `phone`;
