import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { apiPost, apiGet, createTestUser } from '../helpers/api.js';
import { cleanupTestUser, approveKyc } from '../helpers/db.js';
import { query } from '../../apps/api/src/config/db.js';

let seller: any;
let buyer: any;
let orderId: number;
let auctionId: number;

beforeAll(async () => {
    seller = await createTestUser('seller');
    buyer = await createTestUser('user');
    await approveKyc(seller.userId);
    await approveKyc(buyer.userId);

    // 1. Buyer adds a shipping address
    await apiPost('/shipping/addresses', {
        full_name: 'Test Buyer',
        phone: '03001234567',
        address: 'House 123, Street 4',
        city: 'Karachi',
        is_default: true
    }, buyer.token);

    // 2. Create Auction
    const auction = await query<any>(
        `INSERT INTO auctions (seller_id, category_id, title, description, starting_price, current_bid, bid_increment, start_time, end_time, auction_status, created_at)
     VALUES (?, 1, 'Shipping Test', 'Desc', 1000, 1000, 100, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY), 'sold', NOW())`,
        [seller.userId]
    );
    auctionId = auction.insertId;

    // 3. Create Winner & Order (Already paid, ready to ship)
    const winner = await query<any>(
        `INSERT INTO winners (auction_id, user_id, winning_bid, status, created_at)
     VALUES (?, ?, 1000, 'paid', NOW())`,
        [auctionId, buyer.userId]
    );

    const order = await query<any>(
        `INSERT INTO orders
      (order_number, auction_id, winner_id, buyer_id, seller_id, winning_bid, platform_fee, seller_amount, total_amount, order_status, payment_status, shipping_status, created_at)
     VALUES (?, ?, ?, ?, ?, 1000, 50, 950, 1000, 'processing', 'paid', 'not_started', NOW())`,
        [`SHIP-${Date.now()}`, auctionId, winner.insertId, buyer.userId, seller.userId]
    );
    orderId = order.insertId;
});

describe('Shipping Flow', () => {
    it('seller can create a shipment', async () => {
        const res = await apiPost(`/shipping/orders/${orderId}/ship`, {
            courier: 'TCS Express',
            tracking_number: 'TCS123456789',
            estimated_delivery: new Date(Date.now() + 3 * 24 * 60 * 60 * 1000).toISOString().split('T')[0], // 3 days from now
            note: 'Fragile item',
        }, seller.token);

        expect(res.status).toBe(201);

        // Check DB order status
        const dbOrder = await query<any[]>('SELECT shipping_status FROM orders WHERE order_id = ?', [orderId]);
        expect(dbOrder[0].shipping_status).toBe('shipped');
    });

    it('seller can update tracking to in_transit', async () => {
        const res = await apiPost(`/shipping/orders/${orderId}/tracking`, {
            status: 'in_transit',
            location: 'Karachi Hub',
            note: 'Left origin facility',
        }, seller.token);

        expect(res.status).toBe(200);
    });

    it('rejects invalid shipping transition (e.g., delivered -> shipped)', async () => {
        // 1. Mark as delivered first
        await apiPost(`/shipping/orders/${orderId}/tracking`, { status: 'delivered' }, seller.token);

        // 2. Try to go backwards to 'shipped'
        const res = await apiPost(`/shipping/orders/${orderId}/tracking`, {
            status: 'shipped'
        }, seller.token);

        expect(res.status).toBe(400);
        expect(res.data.error).toContain('Invalid shipping transition');
    });

    it('buyer can view tracking timeline', async () => {
        const res = await apiGet(`/shipping/orders/${orderId}/tracking`, buyer.token);

        expect(res.status).toBe(200);
        expect(res.data.data.shipping_status).toBe('delivered');
        expect(res.data.data.timeline.length).toBeGreaterThan(0);
    });
});

afterAll(async () => {
    await query('DELETE FROM shipment_tracking WHERE order_id = ?', [orderId]).catch(() => { });
    await query('DELETE FROM shipments WHERE order_id = ?', [orderId]).catch(() => { });
    await query('DELETE FROM orders WHERE order_id = ?', [orderId]).catch(() => { });
    await query('DELETE FROM winners WHERE auction_id = ?', [auctionId]).catch(() => { });
    await query('DELETE FROM auctions WHERE auction_id = ?', [auctionId]).catch(() => { });

    if (seller?.email) await cleanupTestUser(seller.email);
    if (buyer?.email) await cleanupTestUser(buyer.email);
});