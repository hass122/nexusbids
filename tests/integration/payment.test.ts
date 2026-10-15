import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { apiPost, createTestUser } from '../helpers/api.js';
import { cleanupTestUser, approveKyc } from '../helpers/db.js';
import { query } from '../../apps/api/src/config/db.js';

let seller: any;
let buyer: any;
let orderId: number;
let auctionId: number; // ✅ FIXED: Declared globally so afterAll can access it

beforeAll(async () => {
    seller = await createTestUser('seller');
    buyer = await createTestUser('user');
    await approveKyc(seller.userId);
    await approveKyc(buyer.userId);

    // Create auction + order directly
    const auction = await query<any>(
        `INSERT INTO auctions (seller_id, category_id, title, description, starting_price, current_bid, bid_increment, start_time, end_time, auction_status, created_at)
     VALUES (?, 1, 'Payment Test', 'Desc', 1000, 0, 100, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY), 'active', NOW())`,
        [seller.userId]
    );

    auctionId = auction.insertId; // ✅ FIXED: Assign to global variable

    // 1. Create a Winner first (Foreign Key requirement)
    const winner = await query<any>(
        `INSERT INTO winners (auction_id, user_id, winning_bid, status, created_at)
     VALUES (?, ?, 1000, 'pending_payment', NOW())`,
        [auctionId, buyer.userId]
    );

    // 2. Then create the Order
    const order = await query<any>(
        `INSERT INTO orders
      (order_number, auction_id, winner_id, buyer_id, seller_id, winning_bid, platform_fee, seller_amount, total_amount, order_status, payment_status, shipping_status, created_at)
     VALUES (?, ?, ?, ?, ?, 1000, 50, 950, 1000, 'pending_payment', 'unpaid', 'not_started', NOW())`,
        [`TEST-${Date.now()}`, auctionId, winner.insertId, buyer.userId, seller.userId]
    );
    orderId = order.insertId;
});

describe('Payment Flow', () => {
    it('creates payment intent with correct server amount', async () => {
        const res = await apiPost('/payments/intent', {
            order_id: orderId,
            payment_method: 'jazzcash',
        }, buyer.token);

        expect(res.status).toBe(201);
        expect(res.data.data.amount).toBe(1000);
        expect(res.data.data.payment_intent_id).toBeDefined();
    });

    it('rejects payment from wrong buyer', async () => {
        const other = await createTestUser('user');
        await approveKyc(other.userId); // ✅ Approve KYC so we test order ownership, not KYC block

        const res = await apiPost('/payments/intent', {
            order_id: orderId,
            payment_method: 'jazzcash',
        }, other.token);

        expect(res.status).toBe(400); // Now backend correctly says "unauthorized order access"
        await cleanupTestUser(other.email);
    });
});

afterAll(async () => {
    if (orderId) {
        await query('DELETE FROM payment_audit_log WHERE entity_id = ?', [orderId]).catch(() => { });
        await query('DELETE FROM payments WHERE order_id = ?', [orderId]).catch(() => { });
        await query('DELETE FROM orders WHERE order_id = ?', [orderId]).catch(() => { });
    }
    if (auctionId) {
        await query('DELETE FROM winners WHERE auction_id = ?', [auctionId]).catch(() => { });
        await query('DELETE FROM auctions WHERE auction_id = ?', [auctionId]).catch(() => { });
    }
    if (seller?.email) await cleanupTestUser(seller.email);
    if (buyer?.email) await cleanupTestUser(buyer.email);
});