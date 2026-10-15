import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { apiPost, apiGet, createTestUser } from '../helpers/api.js';
import { cleanupTestUser, approveKyc } from '../helpers/db.js';
import { query } from '../../apps/api/src/config/db.js';

let seller: any;
let buyer: any;
let auctionId: number;

beforeAll(async () => {
    seller = await createTestUser('seller');
    buyer = await createTestUser('user');

    // Approve both KYC for testing
    await approveKyc(seller.userId);
    await approveKyc(buyer.userId);

    // Create auction directly in DB (bypass admin approval)
    const result = await query<any>(
        `INSERT INTO auctions
      (seller_id, category_id, title, description, starting_price, current_bid,
       bid_increment, start_time, end_time, auction_status, anti_sniping_enabled, extension_minutes, extended_count, created_at)
     VALUES (?, 1, 'Test Auction', 'Test Description', 1000, 0, 100, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY), 'active', 1, 2, 0, NOW())`,
        [seller.userId]
    );
    auctionId = result.insertId;
});

describe('Bidding Flow', () => {
    it('rejects bid below starting price', async () => {
        const res = await apiPost(`/auctions/${auctionId}/bids`, { bid_amount: 500 }, buyer.token);
        expect(res.status).toBe(400);
    });

    it('accepts valid first bid', async () => {
        const res = await apiPost(`/auctions/${auctionId}/bids`, { bid_amount: 1000 }, buyer.token);
        expect(res.status).toBe(201);
        expect(res.data.data.bid_amount).toBe(1000);
    });

    it('rejects self-bidding (seller)', async () => {
        const res = await apiPost(`/auctions/${auctionId}/bids`, { bid_amount: 2000 }, seller.token);
        expect(res.status).toBe(400);
        expect(res.data.error).toContain('own auction');
    });

    it('requires higher bid than current', async () => {
        const res = await apiPost(`/auctions/${auctionId}/bids`, { bid_amount: 1050 }, buyer.token);
        expect(res.status).toBe(400); // Must be at least current + increment (1100)
    });
});

afterAll(async () => {
    if (auctionId) {
        await query('DELETE FROM bids WHERE auction_id = ?', [auctionId]).catch(() => { });
        await query('DELETE FROM auctions WHERE auction_id = ?', [auctionId]).catch(() => { });
    }
    if (seller?.email) await cleanupTestUser(seller.email);
    if (buyer?.email) await cleanupTestUser(buyer.email);
});