-- NexusBids Migration 010: Performance Optimization Indexes
-- Safe, idempotent index additions for MariaDB 10.4+ / MySQL 8.0+
-- Improves high-frequency query latency, live bidding locks, and webhook reconciliation

-- 1. Accelerates auto-bid evaluation inside placeBid transaction locks
CREATE INDEX IF NOT EXISTS `idx_auto_bids_auction_status_max` 
  ON `auto_bids` (`auction_id`, `status`, `max_amount`);

-- 2. Eliminates full table scans on payment gateway webhook reconciliation
CREATE INDEX IF NOT EXISTS `idx_payments_transaction_id` 
  ON `payments` (`transaction_id`);

-- 3. Optimizes seller order dashboard sorting and eliminates filesorts
CREATE INDEX IF NOT EXISTS `idx_orders_seller_created` 
  ON `orders` (`seller_id`, `created_at`);

-- 4. Optimizes 7-day post-delivery escrow auto-release cron query
CREATE INDEX IF NOT EXISTS `idx_orders_status_delivered` 
  ON `orders` (`order_status`, `delivered_at`);

-- 5. Accelerates user participation lookups and fraud velocity checks
CREATE INDEX IF NOT EXISTS `idx_bids_bidder_auction` 
  ON `bids` (`bidder_id`, `auction_id`);

-- 6. Accelerates active held-escrow scans during cron and dispute checks
CREATE INDEX IF NOT EXISTS `idx_escrow_status_order` 
  ON `escrow_holds` (`status`, `order_id`);

-- 7. Eliminates correlated subquery table scans on payment disputes during escrow releases
CREATE INDEX IF NOT EXISTS `idx_disputes_order_status` 
  ON `payment_disputes` (`order_id`, `status`);

-- 8. Optimizes admin KYC pending review queue sorting
CREATE INDEX IF NOT EXISTS `idx_kyc_status_created` 
  ON `kyc_verifications` (`status`, `created_at`);

-- 9. Optimizes marketplace sorting by current price
CREATE INDEX IF NOT EXISTS `idx_auctions_status_price` 
  ON `auctions` (`auction_status`, `current_bid`);
