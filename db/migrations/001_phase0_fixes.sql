-- NexusBids Migration 001: Critical Schema Fixes identified in Phase 0 Audit
-- Safe, non-destructive alterations

-- 1. Fix auctions.auction_status ENUM to include 'completed'
-- This fixes the bug where finalizing auctions failed in strict MySQL mode
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
    'relisted'
) NOT NULL DEFAULT 'pending';

-- 2. Drop the conflicting database trigger that auto-creates orders on winner insertion
-- This prevents race conditions and conflicts with application-level order creation and platform fee calculation
DROP TRIGGER IF EXISTS `trg_winners_auto_order`;

-- 3. High-performance indexes for auction queries and bidding concurrency
ALTER TABLE `auctions` 
ADD INDEX IF NOT EXISTS `idx_status_endtime` (`auction_status`, `end_time`),
ADD INDEX IF NOT EXISTS `idx_seller_status` (`seller_id`, `auction_status`);

ALTER TABLE `bids` 
ADD INDEX IF NOT EXISTS `idx_auction_amount_time` (`auction_id`, `bid_amount` DESC, `bid_time` ASC);

ALTER TABLE `auto_bids` 
ADD INDEX IF NOT EXISTS `idx_auto_bids_lookup` (`auction_id`, `status`, `user_id`, `max_amount`);

-- 4. Harmonize dispute linking
ALTER TABLE `payment_disputes`
ADD COLUMN IF NOT EXISTS `dispute_id` INT(11) NULL AFTER `id`;
