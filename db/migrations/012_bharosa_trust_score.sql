-- Track bid cancellations (new signal for Bharosa score)
ALTER TABLE `bids` 
  ADD COLUMN IF NOT EXISTS `user_id` INT NULL,
  ADD COLUMN IF NOT EXISTS `is_cancelled` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `cancelled_at` DATETIME NULL;

-- Cache computed Bharosa scores (refresh periodically)
CREATE TABLE IF NOT EXISTS `bharosa_scores` (
  `user_id` INT NOT NULL PRIMARY KEY,
  `score` DECIMAL(5,2) NOT NULL DEFAULT 50.00,
  `components` JSON NULL COMMENT 'breakdown of score components',
  `computed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
