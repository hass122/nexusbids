import { Router, Request, Response } from 'express';
import { MarketplaceController } from '../controllers/marketplace.controller.js';
import { requireAuth, requireSellerKyc } from '../middleware/auth.js';
import { validateRequest } from '../middleware/validate.js';
import { reviewSchema } from '@nexusbids/shared';
import { sendSuccess, sendError } from '../utils/response.js';
import { AnalyticsService } from '../services/analytics.service.js';
import { ReputationService } from '../services/reputation.service.js';

const router = Router();

// Watchlist
router.post('/watchlist/:auctionId', requireAuth, MarketplaceController.addToWatchlist);
router.delete('/watchlist/:auctionId', requireAuth, MarketplaceController.removeFromWatchlist);
router.get('/watchlist', requireAuth, MarketplaceController.getWatchlist);

// Buyer
router.get('/buyer/dashboard', requireAuth, MarketplaceController.getBuyerDashboard);
router.get('/buyer/bids', requireAuth, MarketplaceController.getBuyerBids);
router.get('/buyer/won-auctions', requireAuth, MarketplaceController.getWonAuctions);
router.get('/buyer/protection', requireAuth, MarketplaceController.getBuyerProtection);

// Seller
router.get('/seller/dashboard', requireAuth, MarketplaceController.getSellerDashboard);
router.get('/seller/auctions', requireAuth, MarketplaceController.getSellerAuctions);

// Orders
router.get('/orders/buyer', requireAuth, MarketplaceController.getBuyerOrders);
router.get('/orders/seller', requireAuth, MarketplaceController.getSellerOrders);
router.get('/orders/:id', requireAuth, MarketplaceController.getOrderById);
router.post('/orders/:id/confirm-receipt', requireAuth, MarketplaceController.confirmReceipt);
router.post('/orders/:id/confirm-delivery', requireAuth, MarketplaceController.confirmReceipt);

// Reviews
router.post('/reviews', requireAuth, validateRequest(reviewSchema), MarketplaceController.addReview);
router.get('/reviews/my', requireAuth, MarketplaceController.getMyReviews);
router.post('/reviews/:id/report', requireAuth, async (req: Request, res: Response) => {
  try {
    const reviewId = parseInt(req.params.id, 10);
    const userId = req.user!.userId;
    const { reason } = req.body;
    const result = await ReputationService.reportReview(userId, reviewId, reason);
    sendSuccess(res, result, result.message);
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// Public Seller Profile & Verified Reputation
router.get('/sellers/:id/reputation', async (req: Request, res: Response) => {
  try {
    const sellerId = parseInt(req.params.id, 10);
    if (isNaN(sellerId)) {
      sendError(res, 'Invalid seller ID', 400);
      return;
    }
    const result = await ReputationService.getSellerReputation(sellerId);
    sendSuccess(res, result);
  } catch (err: any) {
    sendError(res, err.message, 404);
  }
});

// Notifications
router.get('/notifications', requireAuth, MarketplaceController.getNotifications);
router.patch('/notifications/read-all', requireAuth, MarketplaceController.markAllNotificationsRead);
router.patch('/notifications/:id/read', requireAuth, MarketplaceController.markNotificationRead);

// ─── Seller Comprehensive Analytics ───────────────────────────────────────
router.get('/seller/analytics', requireAuth, async (req: Request, res: Response) => {
  try {
    const days = Math.min(30, parseInt((req.query?.days as string) || '7', 10));
    const sellerId = req.user!.userId;
    const data = await AnalyticsService.getSellerAnalytics(sellerId, days);
    sendSuccess(res, data);
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

// ─── Seller Bid Activity Feed (✅ NEW — Web Bid Activity Page) ─────────────
// GET /api/v1/seller/bid-activity?days=7&auction_id=123
router.get('/seller/bid-activity', requireAuth, async (req: Request, res: Response) => {
  try {
    const { query: dbq } = await import('../config/db.js');
    const sellerId = req.user!.userId;
    const days = Math.min(90, Math.max(1, parseInt((req.query.days as string) || '7', 10)));
    const auctionId = req.query.auction_id ? parseInt(req.query.auction_id as string, 10) : null;

    let sql = `
      SELECT
        b.bid_id,
        b.auction_id,
        b.bidder_id,
        b.bid_amount,
        b.bid_time,
        b.is_auto_bid AS is_auto,
        a.title       AS auction_title,
        a.image       AS auction_image,
        a.auction_status AS auction_status,
        a.current_bid AS auction_current_bid,
        u.full_name   AS bidder_name,
        u.email       AS bidder_email
      FROM bids b
      INNER JOIN auctions a ON b.auction_id = a.auction_id
      LEFT JOIN users u ON b.bidder_id = u.user_id
      WHERE a.seller_id = ?
        AND b.bid_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
    `;
    const params: any[] = [sellerId, days];

    if (auctionId && !isNaN(auctionId)) {
      sql += ` AND b.auction_id = ?`;
      params.push(auctionId);
    }

    sql += ` ORDER BY b.bid_time DESC LIMIT 500`;

    const rows = await dbq<any[]>(sql, params);

    // Optional enrichment: derive bid_status (winning/outbid) and reserve_met in JS
    // so the frontend gets cleaner data without an extra SQL join.
    const enriched = rows.map((r: any) => {
      const isHighest =
        r.auction_current_bid != null &&
        parseFloat(r.bid_amount) >= parseFloat(r.auction_current_bid);
      return {
        ...r,
        bid_status: isHighest ? 'winning' : 'outbid',
      };
    });

    sendSuccess(res, enriched);
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

// ─── Seller Payouts & Balance ──────────────────────────────────────────────
router.get('/seller/payouts', requireAuth, async (req: any, res: any) => {
  const { query: dbq } = await import('../config/db.js');
  const { sendSuccess, sendError } = await import('../utils/response.js');
  try {
    const sellerId = req.user.userId;

    let wallet = await dbq<any[]>('SELECT * FROM seller_wallet WHERE seller_id = ? LIMIT 1', [sellerId]);
    if (!wallet.length) {
      // Auto-initialize seller wallet if not yet created
      await dbq(
        `INSERT INTO seller_wallet (seller_id, available_balance, pending_balance, total_earned, total_withdrawn, total_commission_paid, created_at, updated_at)
         VALUES (?, 0.00, 0.00, 0.00, 0.00, 0.00, NOW(), NOW())
         ON DUPLICATE KEY UPDATE updated_at = NOW()`,
        [sellerId]
      ).catch(() => { });
      wallet = await dbq<any[]>('SELECT * FROM seller_wallet WHERE seller_id = ? LIMIT 1', [sellerId]);
    }

    const payouts = await dbq<any[]>(
      `SELECT payout_id, amount, fee, net_amount, status, method, reference, requested_at, processed_at
       FROM payouts WHERE seller_id = ? ORDER BY requested_at DESC LIMIT 50`,
      [sellerId]
    ).catch(() => []);

    const w = wallet[0] || {};
    sendSuccess(res, {
      available_balance: parseFloat(w.available_balance || 0),
      pending_balance: parseFloat(w.pending_balance || 0),
      total_earned: parseFloat(w.total_earned || 0),
      total_withdrawn: parseFloat(w.total_withdrawn || 0),
      payouts,
    });
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

router.post('/seller/payout-request', requireAuth, requireSellerKyc, async (req: any, res: any) => {
  const { query: dbq, withTransaction } = await import('../config/db.js');
  const { sendSuccess, sendError } = await import('../utils/response.js');
  try {
    const sellerId = req.user.userId;
    const { amount, method, account_title, account_number, notes } = req.body || {};

    const reqAmount = parseFloat(amount);
    if (isNaN(reqAmount) || reqAmount < 1000) {
      sendError(res, 'Minimum payout request amount is PKR 1,000.', 400);
      return;
    }
    if (!method || !account_title || !account_number) {
      sendError(res, 'Payment method, account title, and account number are required.', 400);
      return;
    }

    const ref = `PO-${sellerId}-${Date.now().toString().slice(-6)}`;
    let payoutId = 0;

    await withTransaction(async (conn) => {
      // 1. Lock wallet row FOR UPDATE
      const [walletRows]: any = await conn.execute(
        'SELECT available_balance FROM seller_wallet WHERE seller_id = ? FOR UPDATE',
        [sellerId]
      );
      const available = parseFloat(walletRows[0]?.available_balance || 0);

      if (available < reqAmount) {
        throw new Error(`Insufficient available balance. You have PKR ${available.toLocaleString()} available.`);
      }

      // 2. Prevent duplicate pending requests
      const [pendingRows]: any = await conn.execute(
        "SELECT payout_id FROM payouts WHERE seller_id = ? AND status IN ('pending', 'processing') LIMIT 1",
        [sellerId]
      );
      if (pendingRows.length > 0) {
        throw new Error('You already have a pending payout request under review.');
      }

      // 3. Deduct from seller_wallet available_balance
      await conn.execute(
        'UPDATE seller_wallet SET available_balance = available_balance - ?, updated_at = NOW() WHERE seller_id = ?',
        [reqAmount, sellerId]
      );

      // 4. Insert into payouts
      const [insertRes]: any = await conn.execute(
        `INSERT INTO payouts (seller_id, amount, fee, net_amount, status, method, reference, requested_at)
         VALUES (?, ?, 0.00, ?, 'pending', ?, ?, NOW())`,
        [sellerId, reqAmount, reqAmount, method, `${account_title} (${account_number}) - ${ref}`]
      );
      payoutId = insertRes.insertId;

      // 5. Log in seller_ledger
      await conn.execute(
        `INSERT INTO seller_ledger (seller_id, payout_id, entry_type, entry_category, amount, balance_after, status, reference, description, created_at)
         VALUES (?, ?, 'debit', 'payout', ?, ?, 'pending', ?, ?, NOW())`,
        [
          sellerId,
          payoutId,
          reqAmount,
          available - reqAmount,
          ref,
          `Payout requested via ${method}: ${account_title} (${account_number})`,
        ]
      );
    });

    sendSuccess(res, { payout_id: payoutId, amount: reqAmount, status: 'pending', reference: ref }, 'Payout request submitted successfully. Processing typically takes 24 business hours.', 201);
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// ─── Seller Transactions Ledger ────────────────────────────────────────────
router.get('/seller/transactions', requireAuth, async (req: any, res: any) => {
  const { query: dbq } = await import('../config/db.js');
  const { sendSuccess, sendError } = await import('../utils/response.js');
  try {
    const sellerId = req.user.userId;
    const rows = await dbq<any[]>(
      `SELECT id, order_id, payout_id, entry_type, entry_category, amount, balance_after, status, reference, description, created_at
       FROM seller_ledger WHERE seller_id = ? ORDER BY created_at DESC LIMIT 100`,
      [sellerId]
    ).catch(() => []);
    sendSuccess(res, rows);
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

// ─── Seller Disputes ───────────────────────────────────────────────────────
router.get('/seller/disputes', requireAuth, async (req: any, res: any) => {
  const { query: dbq } = await import('../config/db.js');
  const { sendSuccess, sendError } = await import('../utils/response.js');
  try {
    const sellerId = req.user.userId;
    const rows = await dbq<any[]>(
      `SELECT d.*, o.order_number, o.total_amount, a.title as auction_title,
              u.full_name as buyer_name, u.email as buyer_email
       FROM disputes d
       JOIN orders o ON o.order_id = d.order_id
       JOIN auctions a ON a.auction_id = o.auction_id
       LEFT JOIN users u ON u.user_id = d.buyer_id
       WHERE d.seller_id = ? OR o.seller_id = ?
       ORDER BY d.created_at DESC`,
      [sellerId, sellerId]
    ).catch(() => []);
    sendSuccess(res, rows);
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

router.post('/seller/disputes/:id/respond', requireAuth, async (req: any, res: any) => {
  const { query: dbq } = await import('../config/db.js');
  const { sendSuccess, sendError } = await import('../utils/response.js');
  try {
    const sellerId = req.user.userId;
    const disputeId = parseInt(req.params.id, 10);
    const { response, evidence_path } = req.body || {};

    if (!response || !response.trim()) {
      sendError(res, 'Response message is required.', 400);
      return;
    }

    const disputes = await dbq<any[]>(
      `SELECT d.dispute_id, d.description FROM disputes d
       JOIN orders o ON o.order_id = d.order_id
       WHERE d.dispute_id = ? AND (d.seller_id = ? OR o.seller_id = ?) LIMIT 1`,
      [disputeId, sellerId, sellerId]
    );

    if (!disputes.length) {
      sendError(res, 'Dispute not found or unauthorized.', 404);
      return;
    }

    const responseText = response.trim() + (evidence_path ? `\n[Evidence]: ${evidence_path}` : '');

    await dbq(
      "UPDATE disputes SET seller_response = ?, seller_responded_at = NOW(), updated_at = NOW() WHERE dispute_id = ?",
      [responseText, disputeId]
    );

    sendSuccess(res, { responded: true }, 'Dispute response recorded.');
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// ─── Seller Reviews & Ratings ──────────────────────────────────────────────
router.get('/seller/reviews', requireAuth, async (req: any, res: any) => {
  const { query: dbq } = await import('../config/db.js');
  const { sendSuccess, sendError } = await import('../utils/response.js');
  try {
    const sellerId = req.user.userId;
    const rows = await dbq<any[]>(
      `SELECT r.*, a.title as auction_title, o.order_number, u.full_name as buyer_name
       FROM reviews r
       JOIN orders o ON o.order_id = r.order_id
       JOIN auctions a ON a.auction_id = r.auction_id
       LEFT JOIN users u ON u.user_id = r.reviewer_id
       WHERE r.seller_id = ? OR r.reviewee_id = ?
       ORDER BY r.created_at DESC`,
      [sellerId, sellerId]
    );

    const count = rows.length;
    const avg = count > 0 ? (rows.reduce((acc, r) => acc + (parseFloat(r.rating) || 0), 0) / count).toFixed(1) : '5.0';

    sendSuccess(res, {
      average_rating: parseFloat(avg),
      total_reviews: count,
      reviews: rows,
    });
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

router.post('/seller/reviews/:id/reply', requireAuth, async (req: any, res: any) => {
  const { query: dbq } = await import('../config/db.js');
  const { sendSuccess, sendError } = await import('../utils/response.js');
  try {
    const sellerId = req.user.userId;
    const reviewId = parseInt(req.params.id, 10);
    const { reply } = req.body || {};

    if (!reply || !reply.trim()) {
      sendError(res, 'Reply text is required.', 400);
      return;
    }

    const review = await dbq<any[]>(
      'SELECT review_id FROM reviews WHERE review_id = ? AND (seller_id = ? OR reviewee_id = ?) LIMIT 1',
      [reviewId, sellerId, sellerId]
    );

    if (!review.length) {
      sendError(res, 'Review not found or unauthorized.', 404);
      return;
    }

    await dbq(
      'UPDATE reviews SET seller_reply = ?, replied_at = NOW() WHERE review_id = ?',
      [reply.trim(), reviewId]
    );

    sendSuccess(res, { replied: true }, 'Reply posted successfully.');
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// ─── Seller Messages ───────────────────────────────────────────────────────
router.get('/seller/messages', requireAuth, async (req: any, res: any) => {
  const { query: dbq } = await import('../config/db.js');
  const { sendSuccess, sendError } = await import('../utils/response.js');
  try {
    const sellerId = req.user.userId;
    const rows = await dbq<any[]>(
      `SELECT m.*, u.full_name as contact_name, u.email as contact_email
       FROM messages m
       LEFT JOIN users u ON u.user_id = IF(m.sender_id = ?, m.receiver_id, m.sender_id)
       WHERE m.sender_id = ? OR m.receiver_id = ?
       ORDER BY m.created_at DESC LIMIT 100`,
      [sellerId, sellerId, sellerId]
    ).catch(() => []);
    sendSuccess(res, rows);
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

router.post('/seller/messages', requireAuth, async (req: any, res: any) => {
  const { query: dbq } = await import('../config/db.js');
  const { sendSuccess, sendError } = await import('../utils/response.js');
  try {
    const senderId = req.user.userId;
    const { receiver_id, message } = req.body || {};

    if (!receiver_id || !message || !message.trim()) {
      sendError(res, 'Receiver ID and message are required.', 400);
      return;
    }

    const result = await dbq<any>(
      `INSERT INTO messages (sender_id, receiver_id, message, is_read, created_at)
       VALUES (?, ?, ?, 0, NOW())`,
      [senderId, parseInt(receiver_id, 10), message.trim()]
    );

    sendSuccess(res, { message_id: result.insertId }, 'Message sent successfully.', 201);
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

export default router;