import { Router } from 'express';
import crypto from 'node:crypto';
import { requireAuth, requireKyc } from '../middleware/auth.js';
import { sendSuccess, sendError } from '../utils/response.js';
import { query, withTransaction } from '../config/db.js';
import { ENV } from '../config/env.js';
import { PaymentService } from '../services/payment.service.js';
import { EscrowService } from '../services/escrow.service.js';
import { MarketplaceController } from '../controllers/marketplace.controller.js';

const router = Router();

// ─── Payment Methods ─────────────────────────────────────────
// GET /api/v1/payments/methods
router.get('/methods', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;
    const rows = await query<any[]>(
      "SELECT pm_id, payment_type, account_title, account_number, is_default, status FROM payment_methods WHERE user_id = ? AND status = 'active'",
      [userId]
    );
    sendSuccess(res, rows);
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

// POST /api/v1/payments/methods
router.post('/methods', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;
    const { payment_type, account_title, account_number, is_default } = req.body;

    const validTypes = ['jazzcash', 'easypaisa', 'bank_transfer', 'nayapay', 'sadapay'];
    if (!validTypes.includes(payment_type)) {
      sendError(res, `Invalid payment type. Accepted: ${validTypes.join(', ')}`, 400);
      return;
    }
    if (!account_title || !account_number) {
      sendError(res, 'Account title and number are required', 400);
      return;
    }

    await withTransaction(async (conn) => {
      if (is_default) {
        await conn.execute(
          "UPDATE payment_methods SET is_default = 0 WHERE user_id = ?",
          [userId]
        );
      }
      const result = await conn.execute(
        "INSERT INTO payment_methods (user_id, payment_type, account_title, account_number, is_default, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())",
        [userId, payment_type, account_title, account_number, is_default ? 1 : 0]
      );
    });

    sendSuccess(res, { message: 'Payment method added' }, undefined, 201);
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// ✅ NEW: PATCH endpoint for setting default payment method
router.patch('/methods/:id', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;
    const methodId = parseInt(req.params.id, 10);
    const { is_default } = req.body;

    if (is_default) {
      await withTransaction(async (conn) => {
        await conn.execute("UPDATE payment_methods SET is_default = 0 WHERE user_id = ?", [userId]);
        await conn.execute("UPDATE payment_methods SET is_default = 1 WHERE pm_id = ? AND user_id = ?", [methodId, userId]);
      });
    }
    sendSuccess(res, { updated: true });
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// ✅ NEW: DELETE endpoint for removing a payment method
router.delete('/methods/:id', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;
    const methodId = parseInt(req.params.id, 10);
    await query("DELETE FROM payment_methods WHERE pm_id = ? AND user_id = ?", [methodId, userId]);
    sendSuccess(res, { deleted: true });
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});



// ─── Order Payments ──────────────────────────────────────────
// POST /api/v1/payments/order/:orderId/initiate
router.post('/order/:orderId/initiate', requireAuth, requireKyc, async (req, res) => {
  try {
    const userId = req.user!.userId;
    const orderId = parseInt(req.params.orderId, 10);
    const { payment_method_id, payment_type } = req.body;

    // Verify ownership
    const orders = await query<any[]>(
      `SELECT o.*, a.title as auction_title 
       FROM orders o
       JOIN auctions a ON a.auction_id = o.auction_id
       WHERE o.order_id = ? AND o.buyer_id = ? LIMIT 1`,
      [orderId, userId]
    );

    if (orders.length === 0) {
      sendError(res, 'Order not found', 404);
      return;
    }

    const order = orders[0];

    if (order.payment_status !== 'pending') {
      sendError(res, `Order payment is already ${order.payment_status}`, 400);
      return;
    }

    // Create payment record
    const txRef = `NB-${orderId}-${Date.now()}`;
    const paymentAmount = parseFloat(order.total_amount || order.winning_bid || 0);

    await withTransaction(async (conn) => {
      await conn.execute(
        `INSERT INTO payments 
          (auction_id, user_id, winner_id, order_id, amount, payment_method, payment_status, transaction_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW())`,
        [
          order.auction_id,
          userId,                        // user_id = payer (buyer)
          order.buyer_id || userId,      // winner_id = auction winner (= buyer)
          orderId,
          paymentAmount,
          payment_type || 'jazzcash',
          txRef,
        ]
      );

      await conn.execute(
        "UPDATE orders SET payment_status = 'pending', updated_at = NOW() WHERE order_id = ?",
        [orderId]
      );
    });

    // In production: redirect to payment gateway URL or return mobile payment deep link
    sendSuccess(res, {
      transaction_reference: txRef,
      amount: order.total_amount,
      payment_type: payment_type || 'jazzcash',
      instruction: 'Send payment to NexusBids official account and upload receipt to confirm.',
    });
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// POST /api/v1/payments/order/:orderId/confirm-receipt
router.post('/order/:orderId/confirm-receipt', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;
    const orderId = parseInt(req.params.orderId, 10);
    const { receipt_path, transaction_id, payment_method } = req.body;

    if (!receipt_path) {
      sendError(res, 'Receipt image path is required', 400);
      return;
    }

    // Allow unpaid/pending orders where buyer hasn't already submitted
    // (or their previous submission was rejected)
    const orders = await query<any[]>(
      `SELECT order_id, payment_status, admin_review_status
       FROM orders
       WHERE order_id = ? AND buyer_id = ?
         AND payment_status IN ('unpaid','pending')
         AND (admin_review_status IS NULL OR admin_review_status = 'rejected')
       LIMIT 1`,
      [orderId, userId]
    );

    if (orders.length === 0) {
      // Distinguish real reason for better UX
      const existing = await query<any[]>(
        "SELECT payment_status, admin_review_status FROM orders WHERE order_id = ? AND buyer_id = ? LIMIT 1",
        [orderId, userId]
      );

      if (existing.length === 0) {
        sendError(res, 'Order not found', 404);
      } else if (existing[0].payment_status === 'paid') {
        sendError(res, 'Payment already confirmed for this order', 400);
      } else if (
        existing[0].admin_review_status === 'reviewing' ||
        existing[0].admin_review_status === 'pending'
      ) {
        sendError(res, 'Your receipt is already under review. Please wait.', 400);
      } else if (existing[0].admin_review_status === 'approved') {
        sendError(res, 'Payment already approved', 400);
      } else {
        sendError(res, `Cannot upload receipt. Current status: ${existing[0].payment_status}`, 400);
      }
      return;
    }

    await withTransaction(async (conn) => {
      // Update orders using columns that actually exist
      await conn.execute(
        `UPDATE orders
         SET buyer_proof_uploaded_at = NOW(),
             admin_review_status = 'pending',
             admin_reviewed_at = NULL,
             admin_review_notes = NULL,
             updated_at = NOW()
         WHERE order_id = ?`,
        [orderId]
      );

      // Try to write to payments table (best-effort — schema may vary)
      try {
        await conn.execute(
          `UPDATE payments
           SET receipt_path = ?,
               transaction_id = COALESCE(?, transaction_id),
               payment_status = 'pending',
               updated_at = NOW()
           WHERE order_id = ? AND user_id = ?`,
          [receipt_path, transaction_id || null, orderId, userId]
        );
      } catch (e: any) {
        console.warn('[payments] Update failed:', e.message);
        // Try insert if no row exists
        try {
          await conn.execute(
            `INSERT INTO payments
               (auction_id, user_id, winner_id, order_id, amount, payment_method,
                payment_status, receipt_path, transaction_id, created_at)
             SELECT auction_id, buyer_id, buyer_id, order_id, total_amount, ?,
                    'pending', ?, ?, NOW()
             FROM orders WHERE order_id = ?`,
            [payment_method || 'jazzcash', receipt_path, transaction_id || null, orderId]
          );
        } catch (e2: any) {
          console.warn('[payments] Insert also failed:', e2.message);
        }
      }
    });

    sendSuccess(res, {
      message: 'Receipt uploaded. Our team will verify your payment shortly.',
      status: 'pending_verification',
    });
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// POST /api/v1/payments/order/:orderId/seller-verify
// Seller confirms they received payment
router.post('/order/:orderId/seller-verify', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;
    const orderId = parseInt(req.params.orderId, 10);
    const { confirmed } = req.body;

    const orders = await query<any[]>(
      "SELECT * FROM orders WHERE order_id = ? AND seller_id = ? LIMIT 1",
      [orderId, userId]
    );

    if (orders.length === 0) {
      sendError(res, 'Order not found', 404);
      return;
    }

    const newStatus = confirmed ? 'paid' : 'disputed';

    await withTransaction(async (conn) => {
      await conn.execute(
        "UPDATE orders SET payment_status = ?, seller_confirmed_payment_at = NOW(), updated_at = NOW() WHERE order_id = ?",
        [newStatus, orderId]
      );

      await conn.execute(
        "UPDATE payments SET payment_status = 'paid', updated_at = NOW() WHERE order_id = ?",
        [orderId]
      );
    });

    if (confirmed) {
      const { EscrowService } = await import('../services/escrow.service.js');
      await EscrowService.holdEscrow(orderId);
    }

    sendSuccess(res, {
      message: confirmed ? 'Payment verified and held in escrow until delivery.' : 'Dispute raised. Admin will review.',
      status: newStatus,
    });
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// POST /api/v1/payments/dispute
router.post('/dispute', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;
    const { order_id, reason, description } = req.body;

    if (!order_id || !reason) {
      sendError(res, 'order_id and reason are required', 400);
      return;
    }

    // Find the order and verify this user is buyer or seller
    const orders = await query<any[]>(
      "SELECT * FROM orders WHERE order_id = ? AND (buyer_id = ? OR seller_id = ?) LIMIT 1",
      [order_id, userId, userId]
    );

    if (orders.length === 0) {
      sendError(res, 'Order not found', 404);
      return;
    }

    const order = orders[0];
    const buyerId = order.buyer_id;
    const sellerId = order.seller_id;

    // Prevent duplicate open disputes on the same order by the same user
    const existing = await query<any[]>(
      "SELECT dispute_id FROM disputes WHERE order_id = ? AND (buyer_id = ? OR seller_id = ?) AND status IN ('open','under_review') LIMIT 1",
      [order_id, userId, userId]
    );

    if (existing.length > 0) {
      sendError(res, 'You already have an open dispute for this order', 400);
      return;
    }

    // Insert using actual schema columns
    const { evidence_path } = req.body;
    const result = await query<any>(
      `INSERT INTO disputes (order_id, buyer_id, seller_id, reason, description, evidence_path, status, created_at)
       VALUES (?, ?, ?, ?, ?, ?, 'open', NOW())`,
      [order_id, buyerId, sellerId, reason, description || null, evidence_path || null]
    );

    // Mark order as disputed
    await query(
      "UPDATE orders SET order_status = 'disputed', updated_at = NOW() WHERE order_id = ?",
      [order_id]
    );

    // Freeze escrow hold status so automated cron release does not execute
    const { EscrowService } = await import('../services/escrow.service.js');
    await EscrowService.markDisputed(order_id, reason).catch(() => { });

    sendSuccess(res, {
      dispute_id: result.insertId,
      status: 'open'
    }, 'Dispute raised successfully', 201);

  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// GET /api/v1/payments/disputes
router.get('/disputes', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;
    const rows = await query<any[]>(
      `SELECT d.*, o.total_amount, o.buyer_id, o.seller_id,
              a.title as auction_title
       FROM disputes d
       JOIN orders o ON o.order_id = d.order_id
       JOIN auctions a ON a.auction_id = o.auction_id
       WHERE d.buyer_id = ? OR d.seller_id = ? OR o.buyer_id = ? OR o.seller_id = ?
       ORDER BY d.created_at DESC LIMIT 50`,
      [userId, userId, userId, userId]
    );
    sendSuccess(res, rows);
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

// ─── Wallet / Deposit ────────────────────────────────────────
// GET /api/v1/payments/wallet
router.get('/wallet', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;

    // Wallet balance — graceful fallback if column missing
    let balance = 0;
    let currency = 'PKR';
    try {
      const rows = await query<any[]>(
        'SELECT wallet_balance, wallet_currency FROM users WHERE user_id = ? LIMIT 1',
        [userId]
      );
      balance = parseFloat(rows[0]?.wallet_balance) || 0;
      currency = rows[0]?.wallet_currency || 'PKR';
    } catch {
      // wallet_balance column may not exist — compute from paid seller orders instead
      const earned = await query<any[]>(
        `SELECT COALESCE(SUM(o.seller_amount), 0) as total
         FROM orders o
         INNER JOIN auctions a ON a.auction_id = o.auction_id
         WHERE a.seller_id = ? AND o.payment_status = 'paid' AND o.delivery_status = 'delivered'`,
        [userId]
      ).catch(() => [{ total: 0 }]);
      balance = parseFloat(earned[0]?.total) || 0;
    }

    // Wallet transactions — graceful fallback if table missing
    let txn: any[] = [];
    try {
      txn = await query<any[]>(
        `SELECT wt_id, type, amount, description, status, created_at
         FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20`,
        [userId]
      );
    } catch {
      // wallet_transactions table may not exist yet — return empty array
      txn = [];
    }

    sendSuccess(res, { balance, currency, recent_transactions: txn });
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

// ─── Phase 7: Production-Grade Payment Intent Creation ─────────
// POST /api/v1/payments/intent
// Authoritative server-side amount calculation, never trusts client amount
router.post('/intent', requireAuth, requireKyc, async (req, res) => {
  try {
    const userId = req.user!.userId;
    const { order_id, payment_method, mode, idempotency_key } = req.body;

    if (!order_id || !payment_method) {
      sendError(res, 'order_id and payment_method are required', 400);
      return;
    }

    const result = await PaymentService.createPaymentIntent(
      userId,
      parseInt(order_id, 10),
      payment_method,
      idempotency_key,
      mode
    );

    sendSuccess(res, result, 'Payment intent created successfully', 201);
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// ─── Phase 7: Public Gateway Webhooks ──────────────────────────
// POST /api/v1/payments/webhook/:gateway
// Cryptographic HMAC verification, idempotency, duplicate protection, safe retries
router.post('/webhook/:gateway', async (req, res) => {
  try {
    const gateway = req.params.gateway;
    const result = await PaymentService.verifyAndProcessWebhook(gateway, req.body, req.headers);
    sendSuccess(res, result, 'Webhook processed successfully');
  } catch (err: any) {
    if (err.message.includes('signature') || err.message.includes('tampered')) {
      sendError(res, err.message, 401);
      return;
    }
    sendError(res, err.message, 400);
  }
});

// ─── Phase 7: Sandbox Simulation Test Webhook Runner ──────────
// POST /api/v1/payments/sandbox/simulate-webhook
// 🔒 ADMIN ONLY — simulating gateways without auth would allow free orders.
router.post('/sandbox/simulate-webhook', requireAuth, async (req, res) => {
  try {
    if (req.user!.role !== 'admin') {
      sendError(res, 'Forbidden: sandbox webhook simulation is admin-only.', 403);
      return;
    }
    const { gateway, order_id, transaction_id, status } = req.body;
    if (!order_id) {
      sendError(res, 'order_id is required', 400);
      return;
    }

    const gw = (gateway || 'jazzcash').toLowerCase();
    const txnId = transaction_id || `SANDBOX-TXN-${Date.now()}`;
    const payload: Record<string, any> = {
      order_id: String(order_id),
      transaction_id: txnId,
      status: status || 'paid',
      timestamp: Math.floor(Date.now() / 1000),
    };

    let headers: Record<string, string> = {};
    if (gw === 'jazzcash') {
      const secret = ENV.PAYMENT?.JAZZCASH_HMAC_SECRET || 'nexusbids_jazzcash_sandbox_secret_2026';
      const sortedKeys = Object.keys(payload).sort();
      const signString = sortedKeys.map((k) => `${payload[k]}`).join('&');
      payload.pp_SecureHash = crypto.createHmac('sha256', secret).update(signString).digest('hex');
    } else if (gw === 'easypaisa') {
      const secret = ENV.PAYMENT?.EASYPAISA_HMAC_SECRET || 'nexusbids_easypaisa_sandbox_secret_2026';
      headers['x-easypaisa-signature'] = crypto.createHmac('sha256', secret).update(JSON.stringify(payload)).digest('hex');
    } else if (gw === 'stripe') {
      const secret = ENV.PAYMENT?.STRIPE_WEBHOOK_SECRET || 'whsec_nexusbids_sandbox_test_secret_2026';
      const t = Math.floor(Date.now() / 1000);
      const hash = crypto.createHmac('sha256', secret).update(`${t}.${JSON.stringify(payload)}`).digest('hex');
      headers['stripe-signature'] = `t=${t},v1=${hash}`;
    }

    const result = await PaymentService.verifyAndProcessWebhook(gw, payload, headers);
    sendSuccess(res, result, 'Sandbox payment simulated and escrow held');
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// ─── Phase 7: Order Delivery Confirmation ─────────────────────
// POST /api/v1/payments/order/:orderId/confirm-delivery
// Delegates to the canonical MarketplaceController to keep delivery_status /
// order_status / escrow state consistent with /shipping and /orders endpoints.
router.post(['/order/:orderId/confirm-delivery', '/orders/:orderId/confirm-delivery', '/order/:id/confirm-delivery'], requireAuth, (req, res) => {
  return MarketplaceController.confirmReceipt(req, res);
});

// ─── Phase 7: Dispute Settlement with Partial Refund ──────────
// POST /api/v1/payments/order/:orderId/settle-dispute
router.post('/order/:orderId/settle-dispute', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;
    const role = req.user!.role;
    const orderId = parseInt(req.params.orderId, 10);
    const { refund_amount, release_amount, reason } = req.body;

    const orders = await query<any[]>(
      "SELECT * FROM orders WHERE order_id = ? AND (buyer_id = ? OR seller_id = ? OR ? = 'admin') LIMIT 1",
      [orderId, userId, userId, role]
    );

    if (!orders.length) {
      sendError(res, 'Order not found or unauthorized', 404);
      return;
    }

    const result = await EscrowService.partialRefundEscrow(
      orderId,
      parseFloat(refund_amount),
      parseFloat(release_amount),
      reason || 'mutual_dispute_settlement'
    );

    sendSuccess(res, result, 'Dispute settled and escrow funds distributed');
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// ─── Phase 7: Payout Summary & Balance ────────────────────────
// GET /api/v1/payments/payouts
router.get('/payouts', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;
    const data = await PaymentService.getPayoutsData(userId);
    sendSuccess(res, data);
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

// ─── Phase 7: Payout Request (Row-Locked & Anti-Duplicate) ─────
// POST /api/v1/payments/payouts/request
router.post('/payouts/request', requireAuth, async (req, res) => {
  try {
    const userId = req.user!.userId;
    const { amount, method, account_details } = req.body;

    if (!amount || !method) {
      sendError(res, 'amount and method are required', 400);
      return;
    }

    const result = await PaymentService.requestPayout(
      userId,
      parseFloat(amount),
      method,
      account_details || {}
    );

    sendSuccess(res, result, 'Payout request submitted successfully', 201);
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

export default router;
