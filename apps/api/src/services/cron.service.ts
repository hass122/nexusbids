import crypto from 'crypto';
import { withTransaction, query } from '../config/db.js';
import { PLATFORM_COMMISSION_PERCENT, ESCROW_AUTO_RELEASE_DAYS } from '@nexusbids/shared';
import { NotificationService } from './notification.service.js';
import { RealtimeService } from './realtime.service.js';

export class CronService {
  private static lastRun = 0;
  private static isRunning = false;

  /**
   * Automatically processes ended auctions if at least 15 seconds have passed
   * since the last execution. Safe, non-blocking, and idempotent.
   */
  static async checkAndProcessDueAuctions(): Promise<void> {
    const now = Date.now();
    if (now - this.lastRun < 15_000 || this.isRunning) {
      return;
    }
    this.lastRun = now;
    this.isRunning = true;
    try {
      await this.processEndedAuctions();
    } catch (err: any) {
      console.error('[CronService.checkAndProcessDueAuctions]', err?.message);
    } finally {
      this.isRunning = false;
    }
  }

  // Process all expired auctions, declare winners, create orders, and notify parties (Idempotent)
  static async processEndedAuctions(): Promise<{
    processed: number;
    winners: number;
    unmet: number;
    errors: string[];
  }> {
    const expired = await query<any[]>(
      `SELECT auction_id FROM auctions 
       WHERE auction_status = 'active' AND end_time <= NOW()`
    );

    let winnersCount = 0;
    let unmetCount = 0;
    const errors: string[] = [];

    for (const row of expired) {
      const auctionId = row.auction_id;

      try {
        await withTransaction(async (conn) => {
          // 1. Lock the auction FOR UPDATE. If another worker already processed it, status will no longer be 'active'
          const [auctionRows] = await conn.execute<any[]>(
            `SELECT auction_id, seller_id, title, starting_price, current_bid,
                    reserve_price, auction_status 
             FROM auctions WHERE auction_id = ? AND auction_status = 'active' FOR UPDATE`,
            [auctionId]
          );

          if (!auctionRows.length) return; // Already finalized by another process (Idempotent)
          const auction = auctionRows[0];

          // 2. Fetch highest bid
          const [highestBids] = await conn.execute<any[]>(
            `SELECT b.bid_id, b.bidder_id, b.bid_amount, u.full_name as bidder_name
             FROM bids b
             LEFT JOIN users u ON u.user_id = b.bidder_id
             WHERE b.auction_id = ? 
             ORDER BY b.bid_amount DESC, b.bid_time ASC LIMIT 1`,
            [auctionId]
          );

          // CASE 1: No bids placed at all
          if (!highestBids.length) {
            await conn.execute(
              "UPDATE auctions SET auction_status = 'unsold' WHERE auction_id = ?",
              [auctionId]
            );

            // Expire any pending auto-bids
            await conn.execute(
              "UPDATE auto_bids SET status = 'expired', is_active = 0 WHERE auction_id = ?",
              [auctionId]
            );

            // Notify seller that auction ended with no bids
            await NotificationService.notifyAuctionEnded(
              auction.seller_id,
              auctionId,
              auction.title,
              'unsold',
              0,
              conn
            );

            RealtimeService.broadcastStatus(auctionId, 'unsold');
            unmetCount++;
            return;
          }

          const topBid = highestBids[0];
          const winnerId = topBid.bidder_id;
          const winningBid = parseFloat(topBid.bid_amount);
          const reservePrice = auction.reserve_price ? parseFloat(auction.reserve_price) : 0;

          // CASE 2: Bids placed, but reserve price NOT met
          if (reservePrice > 0 && winningBid < reservePrice) {
            await conn.execute(
              "UPDATE auctions SET auction_status = 'unsold' WHERE auction_id = ?",
              [auctionId]
            );

            await conn.execute(
              "UPDATE auto_bids SET status = 'expired', is_active = 0 WHERE auction_id = ?",
              [auctionId]
            );

            // Notify seller
            await NotificationService.notifyAuctionEnded(
              auction.seller_id,
              auctionId,
              auction.title,
              'unsold',
              winningBid,
              conn
            );

            // Notify highest bidder that reserve was not met
            await NotificationService.notifyAuctionLost(
              winnerId,
              auctionId,
              auction.title,
              conn
            );

            RealtimeService.broadcastStatus(auctionId, 'unsold');
            unmetCount++;
            return;
          }

          // CASE 3: Auction successfully sold to highest bidder
          // 3a. Insert winner record (idempotent ON DUPLICATE KEY UPDATE)
          const [winnerResult] = await conn.execute<any>(
            `INSERT INTO winners (auction_id, user_id, winning_bid, status, created_at)
             VALUES (?, ?, ?, 'pending_payment', NOW())
             ON DUPLICATE KEY UPDATE winning_bid = VALUES(winning_bid), user_id = VALUES(user_id)`,
            [auctionId, winnerId, winningBid]
          );

          let winnerPk = winnerResult.insertId;
          if (!winnerPk) {
            const [wRows] = await conn.execute<any[]>(
              'SELECT winner_id FROM winners WHERE auction_id = ? LIMIT 1',
              [auctionId]
            );
            winnerPk = wRows[0]?.winner_id || 0;
          }

          // Also insert into auction_winners for legacy schema support
          await conn.execute(
            `INSERT INTO auction_winners (auction_id, user_id, winning_bid, won_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE winning_bid = VALUES(winning_bid)`,
            [auctionId, winnerId, winningBid]
          ).catch(() => { });

          // 3b. Mark auction as sold
          await conn.execute(
            "UPDATE auctions SET auction_status = 'sold', current_bid = ?, sold_at = NOW() WHERE auction_id = ?",
            [winningBid, auctionId]
          );

          // 3c. Expire all remaining auto_bids on this auction
          await conn.execute(
            "UPDATE auto_bids SET status = 'expired', is_active = 0 WHERE auction_id = ?",
            [auctionId]
          );

          // 3d. Create Order
          const platformFee = Math.round(winningBid * (PLATFORM_COMMISSION_PERCENT / 100) * 100) / 100;
          const sellerAmount = Math.round((winningBid - platformFee) * 100) / 100;
          const totalAmount = winningBid;
          // Add random suffix to prevent duplicate collisions
          const orderSuffix = crypto.randomBytes(3).toString('hex').toUpperCase();
          const orderNumber = `NB-${new Date().getFullYear()}-${String(auctionId).padStart(5, '0')}-${orderSuffix}`;

          const [orderResult] = await conn.execute<any>(
            `INSERT INTO orders 
              (order_number, auction_id, winner_id, buyer_id, seller_id,
               winning_bid, platform_fee, seller_amount, total_amount,
               order_status, payment_status, shipping_status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_payment', 'unpaid', 'not_started', NOW())
             ON DUPLICATE KEY UPDATE 
               winning_bid = VALUES(winning_bid),
               platform_fee = VALUES(platform_fee),
               seller_amount = VALUES(seller_amount),
               total_amount = VALUES(total_amount)`,
            [
              orderNumber,
              auctionId,
              winnerPk,
              winnerId,
              auction.seller_id,
              winningBid,
              platformFee,
              sellerAmount,
              totalAmount,
            ]
          );
          const orderId = orderResult.insertId;

          // 3e. Notifications
          // Notify Winner
          await NotificationService.notifyAuctionWon(
            winnerId,
            auctionId,
            auction.title,
            winningBid,
            orderId,
            conn
          );

          // Payment required notification for Winner
          await NotificationService.notifyPaymentRequired(
            winnerId,
            orderId,
            orderNumber,
            totalAmount,
            conn
          );

          // Notify Seller
          await NotificationService.notifyAuctionEnded(
            auction.seller_id,
            auctionId,
            auction.title,
            'sold',
            winningBid,
            conn
          );

          await NotificationService.notifyOrderCreated(
            auction.seller_id,
            orderId,
            orderNumber,
            true,
            conn
          );

          // Notify all losing bidders
          const [losingBidders] = await conn.execute<any[]>(
            'SELECT DISTINCT bidder_id FROM bids WHERE auction_id = ? AND bidder_id != ?',
            [auctionId, winnerId]
          );

          for (const loser of losingBidders) {
            await NotificationService.notifyAuctionLost(
              loser.bidder_id,
              auctionId,
              auction.title,
              conn
            );
          }

          // Broadcast realtime sold event
          RealtimeService.broadcastStatus(auctionId, 'sold', topBid.bidder_name);

          winnersCount++;
        });
      } catch (err: any) {
        errors.push(`Auction #${auctionId}: ${err.message}`);
      }
    }

    return {
      processed: expired.length,
      winners: winnersCount,
      unmet: unmetCount,
      errors,
    };
  }

  // Auto-release escrow 7 days post delivery
  static async autoReleaseEscrow(): Promise<{ released: number; errors: string[] }> {
    const candidates = await query<any[]>(
      `SELECT e.id as escrow_id, e.order_id, e.seller_id, e.amount, o.order_number
       FROM escrow_holds e
       INNER JOIN orders o ON o.order_id = e.order_id
       WHERE e.status = 'held'
         AND o.order_status = 'delivered'
         AND o.delivered_at IS NOT NULL
         AND o.delivered_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
         AND o.buyer_confirmed_at IS NULL
         AND NOT EXISTS (
           SELECT 1 FROM payment_disputes pd 
           WHERE pd.order_id = e.order_id AND pd.status IN ('open', 'under_review', 'evidence_required')
         )
       LIMIT 100`,
      [ESCROW_AUTO_RELEASE_DAYS]
    );

    let releasedCount = 0;
    const errors: string[] = [];

    for (const item of candidates) {
      try {
        await withTransaction(async (conn) => {
          // Release escrow
          await conn.execute(
            "UPDATE escrow_holds SET status = 'released', released_at = NOW() WHERE id = ?",
            [item.escrow_id]
          );

          // Update order to completed
          await conn.execute(
            "UPDATE orders SET order_status = 'completed', escrow_released_at = NOW() WHERE order_id = ?",
            [item.order_id]
          );

          // Credit seller wallet
          const releaseAmount = parseFloat(item.amount);
          await conn.execute(
            `UPDATE seller_wallet SET 
              pending_balance = GREATEST(0, pending_balance - ?),
              available_balance = available_balance + ?,
              total_earned = total_earned + ?,
              updated_at = NOW()
             WHERE seller_id = ?`,
            [releaseAmount, releaseAmount, releaseAmount, item.seller_id]
          );

          const [walletRows] = await conn.execute<any[]>(
            'SELECT available_balance FROM seller_wallet WHERE seller_id = ?',
            [item.seller_id]
          );
          // Use ?? (not ||) — a legitimate 0 must not fall through
          const balAfter = parseFloat(walletRows[0]?.available_balance ?? releaseAmount);

          // Ledger entry
          await conn.execute(
            `INSERT INTO seller_ledger (seller_id, order_id, entry_type, entry_category, amount, balance_after, status, reference, description, created_at)
             VALUES (?, ?, 'release', 'escrow', ?, ?, 'released', ?, ?, NOW())`,
            [
              item.seller_id,
              item.order_id,
              releaseAmount,
              balAfter,
              `ESCROW-AUTO-${item.order_id}`,
              `Auto-released escrow for order ${item.order_number} after ${ESCROW_AUTO_RELEASE_DAYS} days post-delivery.`,
            ]
          );

          // Notify Seller
          await conn.execute(
            `INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
             VALUES (?, 'payment_received', 'Escrow Payment Released', ?, ?, 0, NOW())`,
            [
              item.seller_id,
              `Payment of Rs. ${parseFloat(item.amount).toLocaleString()} for order ${item.order_number} has been released to your available balance.`,
              'seller/earnings.html',
            ]
          );

          releasedCount++;
        });
      } catch (err: any) {
        errors.push(`Escrow #${item.escrow_id}: ${err.message}`);
      }
    }

    return { released: releasedCount, errors };
  }

  // Hourly check for KYC backlogs > 48 hours
  static async checkKycBacklog(): Promise<{ alerted: boolean; count: number }> {
    try {
      const rows = await query<any[]>(
        "SELECT COUNT(*) AS c FROM kyc_verifications WHERE status='pending' AND created_at < NOW() - INTERVAL 48 HOUR"
      );
      const count = Number(rows[0]?.c || 0);
      if (count === 0) return { alerted: false, count: 0 };

      const existing = await query<any[]>(
        "SELECT id FROM admin_notifications WHERE type='kyc_backlog' AND DATE(created_at) = CURDATE() LIMIT 1"
      );
      if (existing.length > 0) return { alerted: false, count };

      const message = `${count} KYC reviews have been pending for over 48 hours.`;
      await query(
        "INSERT INTO admin_notifications (admin_id, type, title, message, link_url, created_at) VALUES (NULL, 'kyc_backlog', 'KYC Backlog Alert', ?, '/admin/kyc.html', NOW())",
        [message]
      );
      return { alerted: true, count };
    } catch (err: any) {
      console.error('[CronService.checkKycBacklog]', err.message);
      return { alerted: false, count: 0 };
    }
  }
}

