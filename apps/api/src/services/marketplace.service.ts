import { query, withTransaction } from '../config/db.js';
import { DEFAULT_PAGE_LIMIT } from '@nexusbids/shared';

export class MarketplaceService {
  // ==========================================================
  // Watchlist
  // ==========================================================
  static async addToWatchlist(userId: number, auctionId: number) {
    // Verify auction exists and is eligible for watchlist
    const auctions = await query<any[]>(
      'SELECT auction_id, auction_status FROM auctions WHERE auction_id = ? LIMIT 1',
      [auctionId]
    );
    if (!auctions.length) throw new Error('Auction not found.');
    if (['draft', 'cancelled'].includes(auctions[0].auction_status)) {
      throw new Error(`Cannot add ${auctions[0].auction_status} auction to watchlist.`);
    }

    await query(
      `INSERT INTO watchlist (user_id, auction_id, created_at)
       VALUES (?, ?, NOW())
       ON DUPLICATE KEY UPDATE created_at = NOW()`,
      [userId, auctionId]
    );

    // Update watch counts on auction
    await query(
      `UPDATE auctions 
       SET watch_count = (SELECT COUNT(*) FROM watchlist WHERE auction_id = ?),
           watchlist_count = (SELECT COUNT(*) FROM watchlist WHERE auction_id = ?)
       WHERE auction_id = ?`,
      [auctionId, auctionId, auctionId]
    );

    return { watched: true };
  }

  static async removeFromWatchlist(userId: number, auctionId: number) {
    await query(
      'DELETE FROM watchlist WHERE user_id = ? AND auction_id = ?',
      [userId, auctionId]
    );

    await query(
      `UPDATE auctions 
       SET watch_count = (SELECT COUNT(*) FROM watchlist WHERE auction_id = ?),
           watchlist_count = (SELECT COUNT(*) FROM watchlist WHERE auction_id = ?)
       WHERE auction_id = ?`,
      [auctionId, auctionId, auctionId]
    );

    return { watched: false };
  }

  static async getWatchlist(userId: number) {
    const rows = await query<any[]>(
      `SELECT 
        w.watchlist_id as watchlist_id, w.created_at as added_at,
        a.auction_id, a.title, a.image, a.starting_price, a.current_bid,
        a.buy_now_price, a.end_time, a.auction_status, a.seller_id,
        u.full_name as seller_name,
        a.watch_count,
        (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.auction_id) as bid_count,
        c.category_name as category_name
       FROM watchlist w
       INNER JOIN auctions a ON a.auction_id = w.auction_id
       LEFT JOIN users u ON u.user_id = a.seller_id
       LEFT JOIN categories c ON c.category_id = a.category_id
       WHERE w.user_id = ?
       ORDER BY w.created_at DESC`,
      [userId]
    );
    return rows;
  }

  // ==========================================================
  // Buyer Dashboard & Activities
  // ==========================================================
  static async getBuyerDashboard(userId: number) {
    const activeBids = await query<any[]>(
      `SELECT COUNT(DISTINCT b.auction_id) as count 
       FROM bids b 
       INNER JOIN auctions a ON a.auction_id = b.auction_id
       WHERE b.bidder_id = ? AND a.auction_status = 'active'`,
      [userId]
    );

    const wonAuctions = await query<any[]>(
      'SELECT COUNT(*) as count FROM winners WHERE user_id = ?',
      [userId]
    );

    const watchlist = await query<any[]>(
      'SELECT COUNT(*) as count FROM watchlist WHERE user_id = ?',
      [userId]
    );

    const pendingOrders = await query<any[]>(
      `SELECT COUNT(*) as count FROM orders 
       WHERE buyer_id = ? AND order_status IN ('pending_payment', 'paid', 'processing', 'shipped')`,
      [userId]
    );

    return {
      active_bids_count: activeBids[0]?.count || 0,
      won_auctions_count: wonAuctions[0]?.count || 0,
      watchlist_count: watchlist[0]?.count || 0,
      pending_orders_count: pendingOrders[0]?.count || 0,
    };
  }

  static async getBuyerBids(userId: number) {
    const rows = await query<any[]>(
      `SELECT 
        b.bid_id, b.bid_amount, b.bid_time, b.is_auto_bid,
        a.auction_id, a.title, a.image, a.current_bid, a.end_time, a.auction_status,
        (SELECT bidder_id FROM bids b2 WHERE b2.auction_id = a.auction_id ORDER BY b2.bid_amount DESC, b2.bid_time ASC LIMIT 1) = b.bidder_id as is_winning
       FROM bids b
       INNER JOIN auctions a ON a.auction_id = b.auction_id
       WHERE b.bidder_id = ?
       ORDER BY b.bid_time DESC
       LIMIT 50`,
      [userId]
    );
    return rows;
  }

  static async getWonAuctions(userId: number) {
    const rows = await query<any[]>(
      `SELECT 
        w.winner_id, w.winning_bid, w.status as winner_status, w.created_at as won_at,
        a.auction_id, a.title, a.image,
        o.order_id, o.order_number, o.order_status, o.payment_status, o.shipping_status
       FROM winners w
       INNER JOIN auctions a ON a.auction_id = w.auction_id
       LEFT JOIN orders o ON o.winner_id = w.winner_id
       WHERE w.user_id = ?
       ORDER BY w.created_at DESC`,
      [userId]
    );
    return rows;
  }

  // ==========================================================
  // Seller Dashboard & Activities
  // ==========================================================
  static async getSellerDashboard(sellerId: number) {
    const activeAuctions = await query<any[]>(
      "SELECT COUNT(*) as count FROM auctions WHERE seller_id = ? AND auction_status = 'active'",
      [sellerId]
    );

    const pendingShipments = await query<any[]>(
      `SELECT COUNT(*) as count FROM orders 
       WHERE seller_id = ? 
         AND payment_status = 'paid'
         AND order_status IN ('processing', 'paid')
         AND (shipping_status IS NULL 
              OR shipping_status IN ('not_started', 'awaiting_shipment', 'ready_to_ship'))`,
      [sellerId]
    );

    const earnings = await query<any[]>(
      `SELECT 
        COALESCE(SUM(seller_amount), 0) as total_revenue,
        (SELECT COALESCE(SUM(amount), 0) FROM escrow_holds WHERE seller_id = ? AND status = 'held') as escrow_held
       FROM orders WHERE seller_id = ? AND payment_status = 'paid'`,
      [sellerId, sellerId]
    );

    return {
      active_auctions_count: activeAuctions[0]?.count || 0,
      pending_shipments_count: pendingShipments[0]?.count || 0,
      total_sales: parseFloat(earnings[0]?.total_revenue || 0),
      escrow_held: parseFloat(earnings[0]?.escrow_held || 0),
    };
  }

  static async getSellerAuctions(sellerId: number, status?: string) {
    const conditions = ['a.seller_id = ?'];
    const params: any[] = [sellerId];

    if (status) {
      conditions.push('a.auction_status = ?');
      params.push(status);
    }

    const rows = await query<any[]>(
      `SELECT 
        a.*, c.category_name as category_name,
        (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.auction_id) as bid_count
       FROM auctions a
       LEFT JOIN categories c ON c.category_id = a.category_id
       WHERE ${conditions.join(' AND ')}
       ORDER BY a.created_at DESC`,
      params
    );
    return rows;
  }

  // ==========================================================
  // Orders & Fulfillment
  // ==========================================================
  static async getOrders(userId: number, role: 'buyer' | 'seller') {
    const column = role === 'buyer' ? 'o.buyer_id' : 'o.seller_id';

    const rows = await query<any[]>(
      `SELECT 
        o.*,
        a.title as auction_title, a.image as auction_image,
        u.full_name as counterparty_name
       FROM orders o
       INNER JOIN auctions a ON a.auction_id = o.auction_id
       INNER JOIN users u ON u.user_id = ${role === 'buyer' ? 'o.seller_id' : 'o.buyer_id'}
       WHERE ${column} = ?
       ORDER BY o.created_at DESC`,
      [userId]
    );
    return rows;
  }

  static async getOrderById(orderId: number, userId: number, role: string) {
    const rows = await query<any[]>(
      `SELECT 
        o.*,
        a.title as auction_title, a.image as auction_image,
        b.full_name as buyer_name, b.email as buyer_email, b.phone as buyer_phone,
        s.full_name as seller_name, s.email as seller_email, s.phone as seller_phone
       FROM orders o
       INNER JOIN auctions a ON a.auction_id = o.auction_id
       INNER JOIN users b ON b.user_id = o.buyer_id
       INNER JOIN users s ON s.user_id = o.seller_id
       WHERE o.order_id = ?
       LIMIT 1`,
      [orderId]
    );

    if (!rows.length) throw new Error('Order not found.');
    const order = rows[0];

    // Authorization check
    if (role !== 'admin' && order.buyer_id !== userId && order.seller_id !== userId) {
      throw new Error('Forbidden. You do not have permission to view this order.');
    }

    return order;
  }

  static async confirmReceipt(orderId: number, buyerId: number) {
    return await withTransaction(async (conn) => {
      const [orderRows] = await conn.execute<any[]>(
        'SELECT * FROM orders WHERE order_id = ? AND buyer_id = ? FOR UPDATE',
        [orderId, buyerId]
      );

      if (!orderRows.length) throw new Error('Order not found or unauthorized.');
      const order = orderRows[0];

      if (order.order_status === 'completed') {
        throw new Error('This order is already marked as completed.');
      }

      // Mark order completed (removed 'delivery_status' — column doesn't exist in schema)
      await conn.execute(
        `UPDATE orders SET 
          order_status = 'completed',
          shipping_status = 'delivered',
          buyer_confirmed_at = NOW(),
          delivered_at = COALESCE(delivered_at, NOW()),
          completed_at = NOW(),
          escrow_released_at = NOW()
         WHERE order_id = ?`,
        [orderId]
      );

      // Release escrow
      await conn.execute(
        "UPDATE escrow_holds SET status = 'released', released_at = NOW() WHERE order_id = ? AND status = 'held'",
        [orderId]
      );

      // Credit seller_wallet
      const sellerAmount = parseFloat(order.seller_amount || order.total_amount);
      await conn.execute(
        `UPDATE seller_wallet SET 
          pending_balance = GREATEST(0, pending_balance - ?),
          available_balance = available_balance + ?,
          total_earned = total_earned + ?,
          updated_at = NOW()
         WHERE seller_id = ?`,
        [sellerAmount, sellerAmount, sellerAmount, order.seller_id]
      );

      // Get updated balance
      const [walletRows] = await conn.execute<any[]>(
        'SELECT available_balance FROM seller_wallet WHERE seller_id = ?',
        [order.seller_id]
      );
      const balanceAfter = walletRows[0]?.available_balance || sellerAmount;

      // Add to seller ledger
      await conn.execute(
        `INSERT INTO seller_ledger (seller_id, order_id, entry_type, entry_category, amount, balance_after, status, reference, description, created_at)
         VALUES (?, ?, 'release', 'escrow', ?, ?, 'released', ?, ?, NOW())`,
        [
          order.seller_id,
          orderId,
          sellerAmount,
          balanceAfter,
          `ESCROW-CONFIRM-${orderId}`,
          `Escrow released: Buyer confirmed delivery of order ${order.order_number}`,
        ]
      );

      // Notify seller
      await conn.execute(
        `INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
         VALUES (?, 'order_completed', 'Order completed!', ?, 'seller/orders.html', 0, NOW())`,
        [
          order.seller_id,
          `Buyer confirmed delivery of order ${order.order_number}. Funds have been credited to your balance.`,
        ]
      );

      return {
        order_id: orderId,
        order_status: 'completed',
        message: 'Order completed and funds released to the seller.',
      };
    });
  }

  // ==========================================================
  // Reviews
  // ==========================================================
  // Reviews
  // ==========================================================
  static async addReview(userId: number, data: { order_id: number; rating: number; review_text?: string; comment?: string }) {
    // Verify order exists, completed/delivered, buyer matches
    const orders = await query<any[]>(
      'SELECT order_id, auction_id, seller_id, buyer_id, order_status FROM orders WHERE order_id = ? AND buyer_id = ? LIMIT 1',
      [data.order_id, userId]
    );

    if (!orders.length) throw new Error('Order not found or unauthorized.');
    const order = orders[0];

    if (order.order_status !== 'completed' && order.order_status !== 'delivered') {
      throw new Error('You can only review sellers after receiving your order.');
    }

    // Check existing review
    const existing = await query<any[]>(
      'SELECT review_id FROM reviews WHERE order_id = ? LIMIT 1',
      [data.order_id]
    );
    if (existing.length) throw new Error('You have already submitted a review for this order.');

    const commentText = data.comment || data.review_text || '';

    const result = await query<any>(
      `INSERT INTO reviews (order_id, auction_id, buyer_id, seller_id, reviewer_id, reviewee_id, rating, comment, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())`,
      [data.order_id, order.auction_id, userId, order.seller_id, userId, order.seller_id, data.rating, commentText]
    );

    return {
      review_id: result.insertId,
      message: 'Thank you! Your review has been published.',
    };
  }

  static async getMyReviews(userId: number) {
    const rows = await query<any[]>(
      `SELECT r.review_id, r.order_id, r.auction_id, r.rating, r.comment, r.created_at,
              a.title as auction_title, u.full_name as seller_name
       FROM reviews r
       LEFT JOIN auctions a ON a.auction_id = r.auction_id
       LEFT JOIN users u ON u.user_id = r.reviewee_id
       WHERE r.reviewer_id = ?
       ORDER BY r.created_at DESC`,
      [userId]
    );
    return rows;
  }

  // ==========================================================
  // Notifications
  // ==========================================================
  static async getNotifications(userId: number) {
    const notifications = await query<any[]>(
      'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50',
      [userId]
    );

    const unreadCount = await query<any[]>(
      'SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0',
      [userId]
    );

    return {
      notifications,
      unread_count: unreadCount[0]?.unread || 0,
    };
  }

  static async markNotificationRead(notificationId: number, userId: number) {
    await query(
      'UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?',
      [notificationId, userId]
    );
    return { success: true };
  }

  static async markAllNotificationsRead(userId: number) {
    await query(
      'UPDATE notifications SET is_read = 1 WHERE user_id = ?',
      [userId]
    );
    return { success: true };
  }

  // ==========================================================
  // Buyer Protection Center
  // ==========================================================
  static async getBuyerProtection(buyerId: number) {
    const orders = await query<any[]>(
      `SELECT 
        o.order_id, o.order_number, o.order_status, o.payment_status, o.shipping_status,
        o.total_amount, o.seller_amount, o.created_at, o.delivered_at, o.buyer_confirmed_at,
        a.auction_id, a.title as auction_title, a.image as auction_image,
        u.user_id as seller_id, u.full_name as seller_name,
        eh.status as escrow_status, eh.amount as escrow_amount
       FROM orders o
       INNER JOIN auctions a ON a.auction_id = o.auction_id
       INNER JOIN users u ON u.user_id = o.seller_id
       LEFT JOIN escrow_holds eh ON eh.order_id = o.order_id
       WHERE o.buyer_id = ?
       ORDER BY o.created_at DESC`,
      [buyerId]
    );

    const disputes = await query<any[]>(
      `SELECT 
        d.dispute_id, d.order_id, d.reason, d.description, d.evidence_path,
        d.status as dispute_status, d.resolution, d.seller_response,
        d.created_at as dispute_created_at, d.updated_at as dispute_updated_at,
        o.order_number, o.total_amount,
        a.auction_id, a.title as auction_title, a.image as auction_image,
        u.full_name as seller_name
       FROM disputes d
       INNER JOIN orders o ON o.order_id = d.order_id
       INNER JOIN auctions a ON a.auction_id = o.auction_id
       INNER JOIN users u ON u.user_id = d.seller_id
       WHERE d.buyer_id = ?
       ORDER BY d.created_at DESC`,
      [buyerId]
    );

    let totalEscrowProtected = 0;
    const protectedOrders = orders.map((o) => {
      const isPaid = o.payment_status === 'paid';
      const isCompleted = o.order_status === 'completed';
      const isDisputed = o.order_status === 'disputed';
      const isRefunded = o.order_status === 'refunded' || o.payment_status === 'refunded';
      const isDelivered = !!o.delivered_at;

      let inspectionExpiresAt: string | null = null;
      let inspectionActive = false;
      if (o.delivered_at) {
        const delivTime = new Date(o.delivered_at).getTime();
        const expiresTime = delivTime + 48 * 60 * 60 * 1000; // 48 hours inspection window
        inspectionExpiresAt = new Date(expiresTime).toISOString();
        inspectionActive = Date.now() < expiresTime && !isCompleted;
      }

      if (isPaid && !isCompleted && !isRefunded) {
        totalEscrowProtected += parseFloat(o.total_amount || 0);
      }

      const canDispute = isPaid && !isCompleted && !isDisputed && !isRefunded && o.order_status !== 'cancelled';

      let statusBadge = 'Payment Pending';
      if (isDisputed) statusBadge = 'Dispute Under Review';
      else if (isRefunded) statusBadge = 'Refunded to Buyer';
      else if (isCompleted) statusBadge = 'Protected & Completed';
      else if (inspectionActive) statusBadge = '48h Inspection Window Active';
      else if (isDelivered) statusBadge = 'Delivered (Awaiting Confirmation)';
      else if (o.shipping_status === 'shipped') statusBadge = 'In Transit (Escrow Held)';
      else if (isPaid) statusBadge = 'Protected in Escrow';

      return {
        ...o,
        total_amount: parseFloat(o.total_amount || 0),
        escrow_amount: parseFloat(o.escrow_amount || o.total_amount || 0),
        can_dispute: canDispute,
        inspection_active: inspectionActive,
        inspection_expires_at: inspectionExpiresAt,
        protection_badge: statusBadge,
      };
    });

    const activeDisputes = disputes.filter(d => ['open', 'under_review'].includes(d.dispute_status));
    const resolvedDisputes = disputes.filter(d => ['resolved', 'rejected', 'closed'].includes(d.dispute_status));

    return {
      metrics: {
        total_protected_orders: protectedOrders.length,
        escrow_protected_amount: Math.round(totalEscrowProtected * 100) / 100,
        active_disputes_count: activeDisputes.length,
        resolved_disputes_count: resolvedDisputes.length,
      },
      orders: protectedOrders,
      disputes,
    };
  }
}
