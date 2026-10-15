-- NexusBids Migration 007: Payment Methods
-- Safe, non-destructive alterations for MariaDB 10.4

CREATE TABLE IF NOT EXISTS `payment_methods` (
  `pm_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `payment_type` VARCHAR(50) NOT NULL,
  `account_title` VARCHAR(100) NOT NULL,
  `account_number` VARCHAR(100) NOT NULL,
  `is_default` TINYINT(1) DEFAULT 0,
  `status` ENUM('active','deleted') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_pm_user_status` (`user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
