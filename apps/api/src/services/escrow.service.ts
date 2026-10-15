import { query, withTransaction } from '../config/db.js';
import { PoolConnection } from 'mysql2/promise';

export class EscrowService {
  // ─── Hold Escrow on Payment ────────────────────────────────
  // Hold escrow — accepts optional existingConn to avoid nested transaction deadlocks
  static async holdEscrow(orderId: number, existingConn?: PoolConnection) {
    // If caller already has a transaction, use it (avoids nested transaction deadlock)
    if (existingConn) {
      return await this._holdEscrowLogic(existingConn, orderId);
    }
    // Otherwise, start a new transaction
    return await withTransaction(async (conn) => {
      return await this._holdEscrowLogic(conn, orderId);
    });
  }

  private static async _holdEscrowLogic(conn: PoolConnection, orderId: number) {
    const [orders] = await conn.execute<any[]>(
      'SELECT * FROM orders WHERE order_id = ? FOR UPDATE',
      [orderId]
    );

    if (!orders.length) throw new Error('Order not found');
    const order = orders[0];

    // Check if already held
    const [existing] = await conn.execute<any[]>(
      "SELECT id FROM escrow_holds WHERE order_id = ? AND status = 'held'",
      [orderId]
    );

    if (existing.length > 0) return { already_held: true };

    const sellerAmount = parseFloat(order.seller_amount || order.total_amount);

    // Create escrow hold
    await conn.execute(
      `INSERT INTO escrow_holds 
        (order_id, seller_id, buyer_id, amount, status, hold_reason, auto_release_at, created_at)
       VALUES (?, ?, ?, ?, 'held', 'order_placed', DATE_ADD(NOW(), INTERVAL 7 DAY), NOW())`,
      [orderId, order.seller_id, order.buyer_id, sellerAmount]
    );

    // Ensure seller wallet exists
    await conn.execute(
      `INSERT INTO seller_wallet (seller_id, available_balance, pending_balance, total_earned, created_at)
       VALUES (?, 0.00, ?, 0.00, NOW())
       ON DUPLICATE KEY UPDATE pending_balance = pending_balance + VALUES(pending_balance)`,
      [order.seller_id, sellerAmount]
    );

    // Fetch CURRENT available balance (hold does not change available, only pending)
    const [walletBalance] = await conn.execute<any[]>(
      'SELECT available_balance FROM seller_wallet WHERE seller_id = ?',
      [order.seller_id]
    );
    const currentAvailable = parseFloat(walletBalance[0]?.available_balance || 0);

    // Record in seller ledger
    await conn.execute(
      `INSERT INTO seller_ledger 
        (seller_id, order_id, auction_id, entry_type, entry_category, amount, balance_after, status, reference, description, created_at)
       VALUES (?, ?, ?, 'hold', 'escrow', ?, ?, 'held', ?, ?, NOW())`,
      [
        order.seller_id,
        orderId,
        order.auction_id,
        sellerAmount,
        currentAvailable,
        `ESCROW-HOLD-${orderId}`,
        `Payment held in escrow for order #${order.order_number}`,
      ]
    );

    return { held: true, amount: sellerAmount };
  }

  // ─── Release Escrow to Seller ──────────────────────────────
  static async releaseEscrow(orderId: number, reason: string = 'delivery_confirmed') {
    return await withTransaction(async (conn) => {
      const [holds] = await conn.execute<any[]>(
        "SELECT * FROM escrow_holds WHERE order_id = ? AND status IN ('held', 'disputed') FOR UPDATE",
        [orderId]
      );

      if (!holds.length) {
        return { released: false, message: 'No held escrow found for order' };
      }

      const hold = holds[0];
      const amount = parseFloat(hold.amount);

      // Update hold status
      await conn.execute(
        `UPDATE escrow_holds SET 
          status = 'released', 
          released_amount = ?, 
          release_reason = ?, 
          released_at = NOW() 
         WHERE id = ?`,
        [amount, reason, hold.id]
      );

      // Update seller wallet: decrease pending, increase available & total_earned
      await conn.execute(
        `UPDATE seller_wallet SET 
          pending_balance = GREATEST(0, pending_balance - ?),
          available_balance = available_balance + ?,
          total_earned = total_earned + ?,
          updated_at = NOW()
         WHERE seller_id = ?`,
        [amount, amount, amount, hold.seller_id]
      );

      // Get current available balance for ledger
      const [wallets] = await conn.execute<any[]>(
        'SELECT available_balance FROM seller_wallet WHERE seller_id = ?',
        [hold.seller_id]
      );
      const newBalance = wallets[0]?.available_balance || amount;

      // Add credit entry in seller_ledger
      await conn.execute(
        `INSERT INTO seller_ledger 
          (seller_id, order_id, entry_type, entry_category, amount, balance_after, status, reference, description, created_at)
         VALUES (?, ?, 'release', 'escrow', ?, ?, 'released', ?, ?, NOW())`,
        [
          hold.seller_id,
          orderId,
          amount,
          newBalance,
          `ESCROW-REL-${orderId}`,
          `Escrow released (${reason}) for order #${orderId}`,
        ]
      );

      // Update order escrow release timestamp
      await conn.execute(
        'UPDATE orders SET escrow_released_at = NOW(), updated_at = NOW() WHERE order_id = ?',
        [orderId]
      );

      return { released: true, amount, seller_id: hold.seller_id };
    });
  }

  // ─── Refund Escrow to Buyer ────────────────────────────────
  static async refundEscrow(orderId: number, reason: string = 'dispute_resolved_buyer') {
    return await withTransaction(async (conn) => {
      const [holds] = await conn.execute<any[]>(
        "SELECT * FROM escrow_holds WHERE order_id = ? AND status IN ('held', 'disputed') FOR UPDATE",
        [orderId]
      );

      if (!holds.length) {
        return { refunded: false, message: 'No active escrow hold found for order' };
      }

      const hold = holds[0];
      const amount = parseFloat(hold.amount);

      await conn.execute(
        `UPDATE escrow_holds SET 
          status = 'refunded', 
          refunded_amount = ?, 
          release_reason = ?, 
          released_at = NOW() 
         WHERE id = ?`,
        [amount, reason, hold.id]
      );

      // Revert seller pending balance
      await conn.execute(
        'UPDATE seller_wallet SET pending_balance = GREATEST(0, pending_balance - ?) WHERE seller_id = ?',
        [amount, hold.seller_id]
      );

      // Credit buyer wallet (COALESCE defends against NULL column)
      if (hold.buyer_id) {
        await conn.execute(
          'UPDATE users SET wallet_balance = COALESCE(wallet_balance, 0) + ? WHERE user_id = ?',
          [amount, hold.buyer_id]
        );
      }

      // Record in seller ledger
      await conn.execute(
        `INSERT INTO seller_ledger 
          (seller_id, order_id, entry_type, entry_category, amount, balance_after, status, reference, description, created_at)
         VALUES (?, ?, 'refund', 'dispute', ?, 0.00, 'cancelled', ?, ?, NOW())`,
        [
          hold.seller_id,
          orderId,
          amount,
          `ESCROW-REF-${orderId}`,
          `Escrow refunded to buyer (${reason}) for order #${orderId}`,
        ]
      );

      return { refunded: true, amount, buyer_id: hold.buyer_id };
    });
  }

  // ─── Mark Escrow as Disputed ────────────────────────────────
  static async markDisputed(orderId: number, disputeReason: string = 'buyer_dispute_opened') {
    return await withTransaction(async (conn) => {
      await conn.execute(
        "UPDATE escrow_holds SET status = 'disputed', release_reason = ? WHERE order_id = ? AND status = 'held'",
        [disputeReason, orderId]
      );
      return { disputed: true, order_id: orderId };
    });
  }

  // ─── Partial Refund Escrow ──────────────────────────────────
  static async partialRefundEscrow(
    orderId: number,
    refundAmount: number,
    releaseAmount: number,
    reason: string = 'partial_dispute_settlement'
  ) {
    return await withTransaction(async (conn) => {
      const [holds] = await conn.execute<any[]>(
        "SELECT * FROM escrow_holds WHERE order_id = ? AND status IN ('held', 'disputed') FOR UPDATE",
        [orderId]
      );

      if (!holds.length) {
        throw new Error('No active escrow hold found for order');
      }

      const hold = holds[0];
      const totalHeld = parseFloat(hold.amount);

      if (refundAmount < 0 || releaseAmount < 0) {
        throw new Error('Amounts must be non-negative');
      }
      // Enforce exact conservation: every paisa must be accounted for (tolerance 1 paisa)
      if (Math.abs(refundAmount + releaseAmount - totalHeld) > 0.01) {
        throw new Error(
          `Split amounts must exactly equal total held. Held: ${totalHeld}, ` +
          `Refund: ${refundAmount}, Release: ${releaseAmount}, ` +
          `Diff: ${(totalHeld - refundAmount - releaseAmount).toFixed(2)} PKR.`
        );
      }

      // Update hold record
      await conn.execute(
        `UPDATE escrow_holds SET 
          status = 'refunded', 
          refunded_amount = ?, 
          released_amount = ?, 
          release_reason = ?, 
          released_at = NOW() 
         WHERE id = ?`,
        [refundAmount, releaseAmount, reason, hold.id]
      );

      // Deduct total pending balance from seller wallet
      await conn.execute(
        'UPDATE seller_wallet SET pending_balance = GREATEST(0, pending_balance - ?) WHERE seller_id = ?',
        [totalHeld, hold.seller_id]
      );

      // If seller gets a portion, credit available balance
      if (releaseAmount > 0) {
        await conn.execute(
          `UPDATE seller_wallet SET 
            available_balance = available_balance + ?,
            total_earned = total_earned + ?,
            updated_at = NOW()
           WHERE seller_id = ?`,
          [releaseAmount, releaseAmount, hold.seller_id]
        );

        // Fetch the updated balance for accurate ledger entry
        const [wBal] = await conn.execute<any[]>(
          'SELECT available_balance FROM seller_wallet WHERE seller_id = ?',
          [hold.seller_id]
        );
        const newBalAfter = parseFloat(wBal[0]?.available_balance || 0);

        // Record seller credit in ledger
        await conn.execute(
          `INSERT INTO seller_ledger 
            (seller_id, order_id, entry_type, entry_category, amount, balance_after, status, reference, description, created_at)
           VALUES (?, ?, 'release', 'escrow', ?, ?, 'released', ?, ?, NOW())`,
          [
            hold.seller_id,
            orderId,
            releaseAmount,
            newBalAfter,
            `ESCROW-PARTIAL-REL-${orderId}`,
            `Partial release (${reason}) for order #${orderId}`,
          ]
        );
      }

      // If buyer gets a refund, credit buyer wallet (COALESCE defends against NULL column)
      if (refundAmount > 0 && hold.buyer_id) {
        await conn.execute(
          'UPDATE users SET wallet_balance = COALESCE(wallet_balance, 0) + ? WHERE user_id = ?',
          [refundAmount, hold.buyer_id]
        );
      }

      // Update order status
      await conn.execute(
        "UPDATE orders SET order_status = 'completed', escrow_released_at = NOW(), updated_at = NOW() WHERE order_id = ?",
        [orderId]
      );

      return {
        partially_refunded: true,
        refund_amount: refundAmount,
        release_amount: releaseAmount,
        order_id: orderId,
      };
    });
  }

  // ─── Auto-Release Cron ──────────────────────────────────────
  static async processAutoReleases() {
    const expiredHolds = await query<any[]>(
      `SELECT e.order_id, e.seller_id, e.amount, o.order_number 
       FROM escrow_holds e
       JOIN orders o ON o.order_id = e.order_id
       WHERE e.status = 'held' 
         AND e.auto_release_at <= NOW()
         AND o.order_status NOT IN ('disputed', 'cancelled', 'refunded')
       LIMIT 50`
    );

    let releasedCount = 0;
    for (const hold of expiredHolds) {
      try {
        await this.releaseEscrow(hold.order_id, 'auto_release_cron');
        releasedCount++;
      } catch (err) {
        console.error(`Failed to auto-release escrow for order ${hold.order_id}:`, err);
      }
    }

    return { processed: expiredHolds.length, released: releasedCount };
  }
}
