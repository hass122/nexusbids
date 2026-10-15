import crypto from 'node:crypto';
import { query, withTransaction } from '../config/db.js';
import { ENV } from '../config/env.js';
import { EscrowService } from './escrow.service.js';
import { NotificationService } from './notification.service.js';

export type PaymentMode = 'demo' | 'sandbox' | 'production';

export interface PaymentIntentResult {
  payment_intent_id: string;
  order_id: number;
  amount: number;
  currency: 'PKR';
  payment_method: string;
  mode: PaymentMode;
  status: 'pending';
  client_payload: Record<string, any>;
  instruction: string;
}

export class PaymentService {
  // ─── Resolve Effective Payment Mode ─────────────────────────
  static getEffectiveMode(requestedMode?: string): PaymentMode {
    // Server-side strict production enforcement:
    // When in production, client requests can NEVER switch to demo or sandbox mode.
    if (ENV.IS_PRODUCTION) {
      return 'production';
    }

    if (requestedMode === 'demo') return 'demo';
    if (requestedMode === 'sandbox') return 'sandbox';
    if (requestedMode === 'production') return 'production';
    return (ENV.PAYMENT?.MODE as PaymentMode) || 'sandbox';
  }

  // ─── Payment Intent Creation ────────────────────────────────
  // NEVER trusts frontend amount — calculates authoritative amount server-side!
  static async createPaymentIntent(
    userId: number,
    orderId: number,
    paymentMethod: string,
    idempotencyKey?: string,
    requestedMode?: string
  ): Promise<PaymentIntentResult> {
    const validMethods = ['jazzcash', 'easypaisa', 'card', 'stripe', 'bank_transfer'];
    if (!validMethods.includes(paymentMethod)) {
      throw new Error(`Unsupported payment method: ${paymentMethod}. Supported: ${validMethods.join(', ')}`);
    }

    const mode = this.getEffectiveMode(requestedMode);

    return await withTransaction(async (conn) => {
      // 1. Fetch and Lock Order
      const [orders] = await conn.execute<any[]>(
        `SELECT o.*, a.title as auction_title, a.seller_id
         FROM orders o
         JOIN auctions a ON a.auction_id = o.auction_id
         WHERE o.order_id = ? AND o.buyer_id = ? FOR UPDATE`,
        [orderId, userId]
      );

      if (!orders.length) {
        throw new Error('Order not found or unauthorized access.');
      }

      const order = orders[0];

      if (order.payment_status === 'paid' || order.order_status === 'paid' || order.order_status === 'completed') {
        throw new Error('Order has already been paid and settled.');
      }

      if (order.order_status === 'cancelled') {
        throw new Error('Cannot process payment for a cancelled order.');
      }

      // 2. Authoritative Server-Side Amount & Currency Validation
      const serverCalculatedAmount = parseFloat(order.total_amount);
      if (isNaN(serverCalculatedAmount) || serverCalculatedAmount <= 0) {
        throw new Error('Invalid order total amount calculated by server.');
      }

      // 3. Idempotency Check: check for existing pending payment with same method
      const [existingPayments] = await conn.execute<any[]>(
        `SELECT * FROM payments 
         WHERE order_id = ? AND user_id = ? AND payment_status = 'pending' AND payment_method = ?
         ORDER BY created_at DESC LIMIT 1`,
        [orderId, userId, paymentMethod]
      );

      let intentId: string;
      if (existingPayments.length > 0 && (!idempotencyKey || existingPayments[0].transaction_id?.includes(idempotencyKey))) {
        intentId = existingPayments[0].transaction_id;
      } else {
        const uniqueSuffix = idempotencyKey ? crypto.createHash('md5').update(idempotencyKey).digest('hex').substring(0, 8) : Date.now();
        intentId = `NEXUS-INTENT-${orderId}-${uniqueSuffix}`;

        let winnerId = order.winner_id;
        if (!winnerId) {
          const [wRows] = await conn.execute<any[]>(
            'SELECT winner_id FROM winners WHERE auction_id = ? LIMIT 1',
            [order.auction_id]
          );
          if (wRows.length) {
            winnerId = wRows[0].winner_id;
          } else {
            const [wIns] = await conn.execute<any>(
              `INSERT INTO winners (auction_id, user_id, winning_bid, status, created_at)
               VALUES (?, ?, ?, 'pending_payment', NOW())`,
              [order.auction_id, userId, serverCalculatedAmount]
            );
            winnerId = wIns.insertId;
          }
        }

        // Create Payment Record with Pending Status
        await conn.execute(
          `INSERT INTO payments 
            (auction_id, user_id, winner_id, order_id, amount, payment_method, payment_status, transaction_id, created_at, initiated_at)
           VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())`,
          [order.auction_id, userId, winnerId, orderId, serverCalculatedAmount, paymentMethod, intentId]
        );

        // Audit Log
        await conn.execute(
          `INSERT INTO payment_audit_log 
            (actor_id, actor_type, entity_type, entity_id, action, new_value, created_at)
           VALUES (?, 'buyer', 'payment_intent', ?, 'INTENT_CREATED', ?, NOW())`,
          [
            userId,
            orderId,
            JSON.stringify({ intentId, amount: serverCalculatedAmount, paymentMethod, mode }),
          ]
        );
      }

      // 4. Mode-Specific Payload Construction
      let clientPayload: Record<string, any> = {};
      let instruction = '';

      if (mode === 'demo') {
        instruction = 'Demo Mode: Transfer payment manually to NexusBids escrow account and upload receipt.';
        clientPayload = {
          mode: 'demo',
          bank_name: 'Habib Bank Limited (HBL) - Escrow Depository',
          account_title: 'NexusBids Technologies Pvt Ltd (Demo)',
          account_number: 'PK99HABB00012345678901',
          reference_code: intentId,
        };
      } else if (mode === 'sandbox') {
        // Deterministic sandbox transaction data
        instruction = 'Sandbox Mode: Automated simulation gateway ready. Confirm via sandbox checkout or webhook test runner.';
        const sandboxToken = crypto.createHmac('sha256', ENV.PAYMENT?.JAZZCASH_HMAC_SECRET || 'sandbox_secret')
          .update(`${intentId}:${serverCalculatedAmount}`)
          .digest('hex');

        clientPayload = {
          mode: 'sandbox',
          gateway: paymentMethod,
          sandbox_checkout_url: `/api/v1/payments/sandbox/simulate-checkout?intent=${intentId}&amount=${serverCalculatedAmount}`,
          test_token: sandboxToken,
          test_credentials: {
            jazzcash_test_wallet: '03123456789',
            easypaisa_test_wallet: '03451234567',
            test_otp: '123456',
          },
        };
      } else {
        // Production Mode
        instruction = 'Production Mode: Redirecting to licensed financial rail.';
        clientPayload = {
          mode: 'production',
          gateway: paymentMethod,
          checkout_session_url: `https://gateway.nexusbids.pk/checkout/${intentId}`,
        };
      }

      return {
        payment_intent_id: intentId,
        order_id: orderId,
        amount: serverCalculatedAmount,
        currency: 'PKR',
        payment_method: paymentMethod,
        mode,
        status: 'pending',
        client_payload: clientPayload,
        instruction,
      };
    });
  }

  // ─── Webhook Cryptographic Verification ─────────────────────
  static verifyWebhookSignature(
    gateway: string,
    rawPayload: any,
    headers: Record<string, string | string[] | undefined>
  ): boolean {
    const gatewayLower = gateway.toLowerCase();

    const safeCompare = (a: string, b: string): boolean => {
      const bufA = Buffer.from(a.toLowerCase());
      const bufB = Buffer.from(b.toLowerCase());
      if (bufA.length !== bufB.length) return false;
      return crypto.timingSafeEqual(bufA, bufB);
    };

    if (gatewayLower === 'jazzcash') {
      const secret = ENV.PAYMENT?.JAZZCASH_HMAC_SECRET;
      if (!secret && ENV.IS_PRODUCTION) {
        throw new Error('JazzCash webhook secret is not configured in production.');
      }
      const finalSecret = secret || 'nexusbids_jazzcash_sandbox_secret_2026';
      const receivedHash = (rawPayload?.pp_SecureHash || headers['x-jazzcash-signature'] || '') as string;
      if (!receivedHash) return false;

      // JazzCash computes HMAC over sorted fields excluding pp_SecureHash
      const sortedKeys = Object.keys(rawPayload)
        .filter((k) => k !== 'pp_SecureHash' && rawPayload[k] !== undefined && rawPayload[k] !== null && rawPayload[k] !== '')
        .sort();

      const signString = sortedKeys.map((k) => `${rawPayload[k]}`).join('&');
      const expectedHash = crypto.createHmac('sha256', finalSecret).update(signString).digest('hex');

      return safeCompare(receivedHash, expectedHash);
    }

    if (gatewayLower === 'easypaisa') {
      const secret = ENV.PAYMENT?.EASYPAISA_HMAC_SECRET;
      if (!secret && ENV.IS_PRODUCTION) {
        throw new Error('Easypaisa webhook secret is not configured in production.');
      }
      const finalSecret = secret || 'nexusbids_easypaisa_sandbox_secret_2026';
      const receivedSignature = (headers['x-easypaisa-signature'] || rawPayload?.signature || '') as string;
      if (!receivedSignature) return false;

      const bodyStr = typeof rawPayload === 'string' ? rawPayload : JSON.stringify(rawPayload);
      const expectedSig = crypto.createHmac('sha256', finalSecret).update(bodyStr).digest('hex');

      return safeCompare(receivedSignature, expectedSig);
    }

    if (gatewayLower === 'stripe') {
      const secret = ENV.PAYMENT?.STRIPE_WEBHOOK_SECRET;
      if (!secret && ENV.IS_PRODUCTION) {
        throw new Error('Stripe webhook secret is not configured in production.');
      }
      const finalSecret = secret || 'whsec_nexusbids_sandbox_test_secret_2026';
      const stripeHeader = (headers['stripe-signature'] || '') as string;
      if (!stripeHeader) return false;

      const parts = stripeHeader.split(',');
      const timestampPart = parts.find((p) => p.startsWith('t='))?.replace('t=', '');
      const sigPart = parts.find((p) => p.startsWith('v1='))?.replace('v1=', '');

      if (!timestampPart || !sigPart) return false;

      // Replay attack protection: max 5 minute tolerance
      const nowSec = Math.floor(Date.now() / 1000);
      const eventSec = parseInt(timestampPart, 10);
      if (Math.abs(nowSec - eventSec) > 300) {
        return false;
      }

      const bodyStr = typeof rawPayload === 'string' ? rawPayload : JSON.stringify(rawPayload);
      const expectedSig = crypto.createHmac('sha256', finalSecret).update(`${timestampPart}.${bodyStr}`).digest('hex');

      return safeCompare(sigPart, expectedSig);
    }

    return false;
  }

  // ─── Gateway-Specific Status Normalization ────────────────────
  static mapGatewayStatus(gateway: string, payload: any): 'paid' | 'failed' | 'cancelled' | 'unconfirmed' {
    const gw = (gateway || '').toLowerCase();

    if (gw === 'jazzcash') {
      // JazzCash: ResponseCode '000' is the authoritative success code
      if (payload.pp_ResponseCode === '000') return 'paid';
      if (payload.pp_ResponseCode !== undefined) return 'failed';
      const s = String(payload.status || '').toLowerCase();
      if (s === 'paid' || s === 'completed' || s === 'success') return 'paid';
      if (s === 'failed' || s === 'cancelled' || s === 'rejected' || s === 'declined') return 'failed';
      return 'unconfirmed';
    }

    if (gw === 'easypaisa') {
      const respCode = String(payload.responseCode || payload.code || '');
      const orderStatus = String(payload.orderStatus || payload.status || '').toUpperCase();
      if (respCode === '0000' || orderStatus === 'PAID' || orderStatus === 'COMPLETED' || orderStatus === 'SUCCESS') {
        return 'paid';
      }
      if (['FAILED', 'CANCELLED', 'DECLINED', 'EXPIRED', 'REVERSED'].includes(orderStatus) || (respCode && respCode !== '0000')) {
        return 'failed';
      }
      return 'unconfirmed';
    }

    if (gw === 'stripe') {
      const eventType = String(payload.type || '');
      const objStatus = String(payload.data?.object?.status || payload.status || '').toLowerCase();
      if (eventType === 'payment_intent.succeeded' || objStatus === 'succeeded' || objStatus === 'paid') {
        return 'paid';
      }
      if (eventType === 'payment_intent.payment_failed' || ['failed', 'canceled', 'requires_payment_method'].includes(objStatus)) {
        return 'failed';
      }
      return 'unconfirmed';
    }

    // Generic fallback: strictly whitelist explicit success
    const s = String(payload.status || '').toLowerCase();
    if (s === 'paid' || s === 'completed' || s === 'success' || s === 'succeeded') return 'paid';
    if (s === 'failed' || s === 'cancelled' || s === 'declined' || s === 'expired' || s === 'reversed') return 'failed';
    return 'unconfirmed';
  }

  // ─── Webhook Processing with Idempotency & DB Transaction ───
  static async verifyAndProcessWebhook(
    gateway: string,
    payload: any,
    headers: Record<string, string | string[] | undefined>
  ) {
    // 1. Cryptographic Signature Validation
    const isSignatureValid = this.verifyWebhookSignature(gateway, payload, headers);
    if (!isSignatureValid) {
      throw new Error(`Invalid or tampered webhook signature for gateway ${gateway}.`);
    }

    const transactionId = payload.transaction_id || payload.pp_TxnRef || payload.data?.object?.id || payload.order_reference;
    const orderId = parseInt(payload.order_id || payload.pp_BillReference || payload.data?.object?.metadata?.order_id || '0', 10);
    const eventStatus = this.mapGatewayStatus(gateway, payload);

    if (!orderId && !transactionId) {
      throw new Error('Webhook missing order reference and transaction identifier.');
    }

    return await withTransaction(async (conn) => {
      // 2. Fetch and Lock Payment Record
      const [payments] = await conn.execute<any[]>(
        `SELECT p.*, o.buyer_id, o.seller_id, o.total_amount, o.order_number, a.title as auction_title
         FROM payments p
         LEFT JOIN orders o ON o.order_id = p.order_id
         LEFT JOIN auctions a ON a.auction_id = p.auction_id
         WHERE (p.transaction_id = ? OR p.order_id = ?)
         ORDER BY p.payment_id DESC LIMIT 1 FOR UPDATE`,
        [transactionId, orderId]
      );

      if (!payments.length) {
        throw new Error(`No matching payment intent found for transaction: ${transactionId || orderId}`);
      }

      const payment = payments[0];
      const actualOrderId = payment.order_id;

      // Fail-closed: payment MUST belong to a real, non-cancelled order
      if (!actualOrderId || !payment.buyer_id || !payment.seller_id) {
        throw new Error(
          `Payment references missing or invalid order (order_id=${actualOrderId}). Refusing to mark paid.`
        );
      }

      // 3. Idempotency: Duplicate Webhook Protection
      if (payment.payment_status === 'paid' || payment.payment_status === 'completed') {
        return {
          success: true,
          duplicate: true,
          order_id: actualOrderId,
          status: 'already_processed',
        };
      }

      // 4. Amount Verification (Prevent underpayment / tampering)
      let reportedAmount: number | null = null;
      if (payload.pp_Amount !== undefined) {
        // JazzCash may report in paisas (e.g. 500000 for 5000 PKR) or direct string
        const rawAmt = parseFloat(String(payload.pp_Amount));
        const expected = parseFloat(payment.amount);
        if (Math.abs(rawAmt / 100 - expected) < 0.05) {
          reportedAmount = rawAmt / 100;
        } else {
          reportedAmount = rawAmt;
        }
      } else if (payload.data?.object?.amount !== undefined) {
        reportedAmount = parseFloat(String(payload.data.object.amount)) / 100;
      } else if (payload.amount !== undefined) {
        reportedAmount = parseFloat(String(payload.amount));
      }

      if (reportedAmount !== null) {
        const expectedAmount = parseFloat(payment.amount);
        if (Math.abs(reportedAmount - expectedAmount) > 0.05) {
          await conn.execute(
            `INSERT INTO payment_audit_log (actor_id, actor_type, entity_type, entity_id, action, new_value, created_at)
             VALUES (0, 'gateway', 'payment', ?, 'PAYMENT_AMOUNT_MISMATCH', ?, NOW())`,
            [actualOrderId, JSON.stringify({ gateway, transactionId, reportedAmount, expectedAmount })]
          );
          throw new Error(`Payment amount mismatch: gateway reported ${reportedAmount}, expected ${expectedAmount}.`);
        }
      }

      // 5. Handle Failure / Cancellation from Gateway
      if (eventStatus === 'failed' || eventStatus === 'cancelled') {
        await conn.execute(
          `UPDATE payments SET payment_status = 'failed', gateway_response = ?, updated_at = NOW() WHERE payment_id = ?`,
          [JSON.stringify(payload), payment.payment_id]
        );
        await conn.execute(
          `UPDATE orders SET payment_status = 'failed', updated_at = NOW() WHERE order_id = ?`,
          [actualOrderId]
        );
        await conn.execute(
          `INSERT INTO payment_audit_log (actor_id, actor_type, entity_type, entity_id, action, new_value, created_at)
           VALUES (0, 'gateway', 'payment', ?, 'PAYMENT_FAILED', ?, NOW())`,
          [actualOrderId, JSON.stringify({ gateway, transactionId, reason: payload.message || payload.pp_ResponseMessage || 'Gateway reported failure' })]
        );
        return { success: false, status: 'failed', order_id: actualOrderId };
      }

      if (eventStatus !== 'paid') {
        throw new Error(`Unconfirmed gateway payment status '${eventStatus}'. Payment cannot be approved.`);
      }

      // 5. Success Path: Mark Paid, Hold in Escrow, Create Audit Trail
      await conn.execute(
        `UPDATE payments SET 
          payment_status = 'paid', 
          transaction_id = COALESCE(?, transaction_id),
          gateway_response = ?,
          updated_at = NOW() 
         WHERE payment_id = ?`,
        [transactionId || null, JSON.stringify(payload), payment.payment_id]
      );

      await conn.execute(
        `UPDATE orders SET 
          payment_status = 'paid',
          order_status = 'processing',
          updated_at = NOW()
         WHERE order_id = ?`,
        [actualOrderId]
      );

      // Audit Log
      await conn.execute(
        `INSERT INTO payment_audit_log 
          (actor_id, actor_type, entity_type, entity_id, action, new_value, created_at)
         VALUES (0, 'gateway', 'payment', ?, 'PAYMENT_VERIFIED_WEBHOOK', ?, NOW())`,
        [
          actualOrderId,
          JSON.stringify({ gateway, transactionId, amount: payment.amount, status: 'paid' }),
        ]
      );

      // 6. Automatically Hold Funds in Escrow
      // Pass `conn` to reuse the current transaction — prevents nested transaction deadlock
      await EscrowService.holdEscrow(actualOrderId, conn);

      // 7. Send Notifications
      if (payment.buyer_id) {
        await NotificationService.send(
          {
            userId: payment.buyer_id,
            type: 'payment_received',
            title: 'Payment Successful & Held in Escrow',
            message: `Your payment of PKR ${Number(payment.amount).toLocaleString()} for order #${payment.order_number || actualOrderId} was verified and safely held in escrow.`,
            link: `user/orders.html?orderId=${actualOrderId}`,
          },
          conn
        );
      }
      if (payment.seller_id) {
        await NotificationService.send(
          {
            userId: payment.seller_id,
            type: 'order_created',
            title: 'Order Paid — Please Ship Item',
            message: `Order #${payment.order_number || actualOrderId} has been paid. Escrow funds are secured. Please package and dispatch the item.`,
            link: `seller/orders.html?orderId=${actualOrderId}`,
          },
          conn
        );
      }

      return {
        success: true,
        duplicate: false,
        order_id: actualOrderId,
        status: 'paid',
        escrow_held: true,
      };
    });
  }

  // ─── Payout Request with Row Locking & Anti-Duplication ─────
  static async requestPayout(
    sellerId: number,
    amount: number,
    method: string,
    accountDetails: Record<string, any>
  ) {
    if (isNaN(amount) || amount <= 0) {
      throw new Error('Payout amount must be greater than zero.');
    }

    const minPayout = 1000; // Unified with marketplace.routes.ts /seller/payout-request
    if (amount < minPayout) {
      throw new Error(`Minimum withdrawal amount is PKR ${minPayout}.`);
    }

    return await withTransaction(async (conn) => {
      // 1. Row Lock on seller_wallet
      const [wallets] = await conn.execute<any[]>(
        'SELECT * FROM seller_wallet WHERE seller_id = ? FOR UPDATE',
        [sellerId]
      );

      if (!wallets.length) {
        throw new Error('Seller wallet not initialized.');
      }

      const wallet = wallets[0];
      const availableBalance = parseFloat(wallet.available_balance);

      if (availableBalance < amount) {
        throw new Error(`Insufficient available balance. Available: PKR ${availableBalance.toFixed(2)}, Requested: PKR ${amount.toFixed(2)}.`);
      }

      // 2. Prevent Duplicate Payout Requests
      const [pendingPayouts] = await conn.execute<any[]>(
        "SELECT payout_id FROM payouts WHERE seller_id = ? AND status IN ('pending', 'processing') LIMIT 1",
        [sellerId]
      );

      if (pendingPayouts.length > 0) {
        throw new Error('You already have a pending or processing payout request. Please wait until it completes.');
      }

      // 3. Deduct Available Balance Atomically
      await conn.execute(
        `UPDATE seller_wallet SET 
          available_balance = available_balance - ?,
          updated_at = NOW() 
         WHERE seller_id = ?`,
        [amount, sellerId]
      );

      const ref = `PAYOUT-${sellerId}-${Date.now()}`;

      // 4. Insert Payout Record
      const [payoutResult] = await conn.execute<any>(
        `INSERT INTO payouts 
          (seller_id, amount, net_amount, status, method, reference, requested_at)
         VALUES (?, ?, ?, 'pending', ?, ?, NOW())`,
        [sellerId, amount, amount, method, ref]
      );

      const payoutId = payoutResult.insertId;

      // 5. Record Double-Entry Seller Ledger
      const [updatedWallet] = await conn.execute<any[]>(
        'SELECT available_balance FROM seller_wallet WHERE seller_id = ?',
        [sellerId]
      );
      // Use ?? (not ||) — a legitimate 0 balance must not fall through
      const balAfter = parseFloat(
        updatedWallet[0]?.available_balance ?? (availableBalance - amount)
      );

      await conn.execute(
        `INSERT INTO seller_ledger 
          (seller_id, entry_type, entry_category, amount, balance_after, status, reference, description, metadata, created_at)
         VALUES (?, 'payout', 'payout', ?, ?, 'pending', ?, ?, ?, NOW())`,
        [
          sellerId,
          amount,
          balAfter,
          ref,
          `Payout withdrawal requested via ${method}`,
          JSON.stringify(accountDetails || {}),
        ]
      );

      return {
        payout_id: payoutId,
        seller_id: sellerId,
        amount,
        status: 'pending',
        reference: ref,
        remaining_available_balance: balAfter,
      };
    });
  }

  // ─── Payout History & Balance Summary ───────────────────────
  static async getPayoutsData(sellerId: number) {
    const [wallets] = await query<any[]>(
      'SELECT available_balance, pending_balance, total_earned, total_withdrawn FROM seller_wallet WHERE seller_id = ?',
      [sellerId]
    );

    const wallet = wallets[0] || {
      available_balance: 0,
      pending_balance: 0,
      total_earned: 0,
      total_withdrawn: 0,
    };

    const payouts = await query<any[]>(
      `SELECT payout_id, amount, net_amount, fee, status, method, reference, requested_at, processed_at
       FROM payouts
       WHERE seller_id = ?
       ORDER BY requested_at DESC LIMIT 50`,
      [sellerId]
    );

    return {
      available_balance: parseFloat(wallet.available_balance) || 0,
      pending_balance: parseFloat(wallet.pending_balance) || 0,
      total_earned: parseFloat(wallet.total_earned) || 0,
      total_withdrawn: parseFloat(wallet.total_withdrawn) || 0,
      payouts,
    };
  }
}
