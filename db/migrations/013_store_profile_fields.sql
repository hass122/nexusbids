-- NexusBids Migration 013: Store Profile & Seller Identity Enhancements
-- Safe, non-destructive alterations for users table

ALTER TABLE `users`
ADD COLUMN IF NOT EXISTS `store_name` VARCHAR(150) NULL AFTER `full_name`,
ADD COLUMN IF NOT EXISTS `store_tagline` VARCHAR(255) NULL AFTER `store_name`,
ADD COLUMN IF NOT EXISTS `store_bio` MEDIUMTEXT NULL AFTER `store_tagline`,
ADD COLUMN IF NOT EXISTS `store_logo` MEDIUMTEXT NULL AFTER `store_bio`,
ADD COLUMN IF NOT EXISTS `store_banner` MEDIUMTEXT NULL AFTER `store_logo`,
ADD COLUMN IF NOT EXISTS `return_policy` VARCHAR(50) DEFAULT 'no_returns' AFTER `store_banner`,
ADD COLUMN IF NOT EXISTS `business_phone` VARCHAR(50) NULL AFTER `return_policy`,
ADD COLUMN IF NOT EXISTS `whatsapp` VARCHAR(50) NULL AFTER `business_phone`,
ADD COLUMN IF NOT EXISTS `website` VARCHAR(255) NULL AFTER `whatsapp`,
ADD COLUMN IF NOT EXISTS `facebook` VARCHAR(255) NULL AFTER `website`,
ADD COLUMN IF NOT EXISTS `instagram` VARCHAR(100) NULL AFTER `facebook`,
ADD COLUMN IF NOT EXISTS `date_of_birth` DATE NULL AFTER `instagram`;

