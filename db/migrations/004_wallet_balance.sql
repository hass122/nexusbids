-- NexusBids Migration 004: User wallet balance
ALTER TABLE `users`
ADD COLUMN IF NOT EXISTS `wallet_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
ADD COLUMN IF NOT EXISTS `wallet_currency` VARCHAR(3) NOT NULL DEFAULT 'PKR';
