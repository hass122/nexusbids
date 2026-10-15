import { test, expect } from '@playwright/test';
import { query } from '../../apps/api/src/config/db.js';
import dotenv from 'dotenv';
import path from 'path';
import fs from 'fs';

// Load API's .env so CRON_SECRET etc. are available
dotenv.config({ path: path.resolve(process.cwd(), 'apps/api/.env') });

const API = 'http://localhost:5000/api/v1';

// Helper: API call
async function apiCall(method: string, apiPath: string, body?: any, token?: string) {
    const res = await fetch(`${API}${apiPath}`, {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-Forwarded-For': `10.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}`,
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const data = await res.json().catch(() => ({}));
    return { status: res.status, data };
}

// Local datetime formatter — matches HTML datetime-local format (YYYY-MM-DDTHH:mm)
function fmt(d: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

// Test data
let buyerEmail: string;
let sellerEmail: string;
let adminEmail: string;
let buyerId: number;
let sellerId: number;
let buyerToken: string;
let sellerToken: string;
let adminToken: string;
let auctionId: number;
let orderId: number;
const AUCTION_TITLE = `Lifecycle Test ${Date.now()}`;
const STARTING_PRICE = 1000;
const BID_INCREMENT = 100;
const BID_AMOUNT = 1100;
const PLATFORM_FEE_PCT = 0.05;
const EXPECTED_FEE = Math.round(BID_AMOUNT * PLATFORM_FEE_PCT * 100) / 100;
const EXPECTED_SELLER_AMOUNT = BID_AMOUNT - EXPECTED_FEE;

test.describe.configure({ mode: 'serial' });

test.beforeAll(async () => {
    console.log('🔧 SETUP: Creating buyer + seller + category...');
    const ts = Date.now();

    const cat = await query<any[]>('SELECT category_id FROM categories WHERE category_id = 1');
    if (!cat.length) {
        await query("INSERT INTO categories (category_id, category_name, status, created_at) VALUES (1, 'Test Cat', 'active', NOW())");
    }

    // Create seller
    sellerEmail = `lifecycle_seller_${ts}@test.com`;
    const regSeller = await apiCall('POST', '/auth/register', {
        full_name: 'Lifecycle Seller',
        username: `lc_seller_${ts}`,
        email: sellerEmail,
        password: 'Test@12345',
        phone: `0300${Math.floor(1000000 + Math.random() * 9000000)}`,
        role: 'seller',
    });
    if (regSeller.status !== 201) throw new Error(`Seller reg failed: ${JSON.stringify(regSeller.data)}`);

    // Create buyer
    buyerEmail = `lifecycle_buyer_${ts}@test.com`;
    const regBuyer = await apiCall('POST', '/auth/register', {
        full_name: 'Lifecycle Buyer',
        username: `lc_buyer_${ts}`,
        email: buyerEmail,
        password: 'Test@12345',
        phone: `0300${Math.floor(1000000 + Math.random() * 9000000)}`,
        role: 'user',
    });
    if (regBuyer.status !== 201) throw new Error(`Buyer reg failed: ${JSON.stringify(regBuyer.data)}`);

    // Approve KYCs
    await query("UPDATE users SET kyc_status='approved', seller_kyc_status='approved' WHERE email IN (?, ?)", [buyerEmail, sellerEmail]);

    // Get IDs
    const sellerRow = await query<any[]>('SELECT user_id FROM users WHERE email = ?', [sellerEmail]);
    sellerId = sellerRow[0].user_id;
    const buyerRow = await query<any[]>('SELECT user_id FROM users WHERE email = ?', [buyerEmail]);
    buyerId = buyerRow[0].user_id;

    // Create admin user (for sandbox webhook auth)
    adminEmail = `lifecycle_admin_${ts}@test.com`;
    const regAdmin = await apiCall('POST', '/auth/register', {
        full_name: 'Lifecycle Admin',
        username: `lc_admin_${ts}`,
        email: adminEmail,
        password: 'Test@12345',
        phone: `0300${Math.floor(1000000 + Math.random() * 9000000)}`,
        role: 'user', // Register as user, then elevate to admin
    });
    if (regAdmin.status !== 201) throw new Error(`Admin reg failed: ${JSON.stringify(regAdmin.data)}`);

    // Elevate to admin role via DB
    await query("UPDATE users SET role = 'admin', kyc_status = 'approved' WHERE email = ?", [adminEmail]);

    // Get tokens
    const sellerLogin = await apiCall('POST', '/auth/login', { email: sellerEmail, password: 'Test@12345' });
    sellerToken = sellerLogin.data.data.accessToken;
    const buyerLogin = await apiCall('POST', '/auth/login', { email: buyerEmail, password: 'Test@12345' });
    buyerToken = buyerLogin.data.data.accessToken;
    const adminLogin = await apiCall('POST', '/auth/login', { email: adminEmail, password: 'Test@12345' });
    adminToken = adminLogin.data.data.accessToken;

    console.log(`✅ Seller: ${sellerEmail} (id=${sellerId})`);
    console.log(`✅ Buyer:  ${buyerEmail} (id=${buyerId})`);
    console.log(`✅ Admin:  ${adminEmail}`);
});

// ═══════════════════════════════════════════════════
// STEP 1: SELLER CREATES AUCTION (with valid 24h duration)
// ═══════════════════════════════════════════════════
test('STEP 1: Seller creates an auction', async () => {
    console.log('\n📌 STEP 1: Seller creates auction');

    const now = new Date();
    const startTime = new Date(now.getTime() + 60 * 1000);  // 1 min from now
    const endTime = new Date(now.getTime() + 24 * 60 * 60 * 1000); // 24 hours from now (well above 15 min minimum)

    const res = await apiCall('POST', '/auctions', {
        title: AUCTION_TITLE,
        description: 'Lifecycle test auction with detailed description that exceeds ten characters.',
        category_id: 1,
        starting_price: STARTING_PRICE,
        bid_increment: BID_INCREMENT,
        start_time: fmt(startTime),
        end_time: fmt(endTime),
        image: 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=500',
    }, sellerToken);

    console.log(`📥 [API] POST /auctions → ${res.status}`);
    if (res.status !== 201) {
        console.log('❌ Error:', JSON.stringify(res.data, null, 2));
    }
    expect(res.status).toBe(201);
    auctionId = res.data.data.auction_id;
    console.log(`✅ Auction created: #${auctionId}`);

    const dbAuction = await query<any[]>('SELECT * FROM auctions WHERE auction_id = ?', [auctionId]);
    expect(dbAuction[0].auction_status).toBe('pending');
    console.log(`✅ DB: status=pending, price=${STARTING_PRICE}`);
});

// ═══════════════════════════════════════════════════
// STEP 2: ADMIN APPROVES (DB simulation)
// ═══════════════════════════════════════════════════
test('STEP 2: Admin approves auction', async () => {
    console.log('\n📌 STEP 2: Admin approves auction');

    // Make auction active with start_time in past (so buyer can bid immediately)
    await query(
        `UPDATE auctions SET 
         auction_status = 'active', 
         start_time = DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         WHERE auction_id = ?`,
        [auctionId]
    );

    const dbAuction = await query<any[]>('SELECT auction_status FROM auctions WHERE auction_id = ?', [auctionId]);
    expect(dbAuction[0].auction_status).toBe('active');
    console.log(`✅ Auction active`);
});

// ═══════════════════════════════════════════════════
// STEP 3: BUYER PLACES BID
// ═══════════════════════════════════════════════════
test('STEP 3: Buyer places bid', async () => {
    console.log('\n📌 STEP 3: Buyer places bid');

    const res = await apiCall('POST', `/auctions/${auctionId}/bids`, {
        bid_amount: BID_AMOUNT,
    }, buyerToken);

    console.log(`📥 [API] POST /bids → ${res.status}`);
    if (res.status !== 201) {
        console.log('❌ Error:', JSON.stringify(res.data, null, 2));
    }
    expect(res.status).toBe(201);

    const bids = await query<any[]>('SELECT * FROM bids WHERE auction_id = ?', [auctionId]);
    expect(bids.length).toBe(1);
    expect(parseFloat(bids[0].bid_amount)).toBe(BID_AMOUNT);
    console.log(`✅ Bid placed: ${BID_AMOUNT} PKR`);

    const auction = await query<any[]>('SELECT current_bid FROM auctions WHERE auction_id = ?', [auctionId]);
    expect(parseFloat(auction[0].current_bid)).toBe(BID_AMOUNT);
    console.log(`✅ current_bid = ${BID_AMOUNT}`);
});

// ═══════════════════════════════════════════════════
// STEP 4: AUCTION ENDS (time simulation)
// ═══════════════════════════════════════════════════
test('STEP 4: Auction ends', async () => {
    console.log('\n📌 STEP 4: Auction ends (time simulation)');

    await query('UPDATE auctions SET end_time = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE auction_id = ?', [auctionId]);

    const auction = await query<any[]>('SELECT end_time, auction_status FROM auctions WHERE auction_id = ?', [auctionId]);
    console.log(`✅ end_time set to past`);
    expect(auction[0].auction_status).toBe('active');
});

// ═══════════════════════════════════════════════════
// STEP 5: CRON JOB RUNS → WINNER + ORDER CREATED
// ═══════════════════════════════════════════════════
test('STEP 5: Cron processes auction', async () => {
    console.log('\n📌 STEP 5: Cron job processes ended auction');

    // Read CRON_SECRET directly from .env file (process.env may not have it in test context)
    const envPath = path.resolve(process.cwd(), 'apps/api/.env');
    const envContent = fs.readFileSync(envPath, 'utf-8');
    const cronMatch = envContent.match(/^CRON_SECRET\s*=\s*(.+)$/m);
    const cronSecret = cronMatch ? cronMatch[1].trim().replace(/^["']|["']$/g, '') : '';

    if (!cronSecret) {
        throw new Error('CRON_SECRET not found in apps/api/.env — cannot call cron endpoint');
    }
    console.log(`🔑 CRON_SECRET from .env (length: ${cronSecret.length})`);

    const res = await fetch(`http://localhost:5000/api/v1/cron/process-auctions`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'x-cron-secret': cronSecret,
        },
    });

    const data = await res.json().catch(() => ({}));
    console.log(`📥 [CRON] ${res.status}`, JSON.stringify(data).slice(0, 200));

    if (res.status !== 200) {
        throw new Error(
            `Cron failed with ${res.status}.\n` +
            `Secret from .env: "${cronSecret.slice(0, 5)}..." (length ${cronSecret.length})\n` +
            `Response: ${JSON.stringify(data)}\n` +
            `💡 If 401: Server might need restart to pick up CRON_SECRET. Run: Ctrl+C then npm run dev in apps/api`
        );
    }

    await new Promise(r => setTimeout(r, 2000));

    const auction = await query<any[]>('SELECT auction_status FROM auctions WHERE auction_id = ?', [auctionId]);
    expect(auction[0].auction_status).toBe('sold');
    console.log(`✅ Auction marked as SOLD`);

    const winners = await query<any[]>('SELECT * FROM winners WHERE auction_id = ?', [auctionId]);
    expect(winners.length).toBe(1);
    expect(winners[0].user_id).toBe(buyerId);
    console.log(`✅ Winner: buyer_id=${buyerId}, bid=${winners[0].winning_bid}`);

    const orders = await query<any[]>('SELECT * FROM orders WHERE auction_id = ?', [auctionId]);
    expect(orders.length).toBe(1);
    orderId = orders[0].order_id;
    console.log(`✅ Order created: #${orderId}`);
    console.log(`   Total: ${orders[0].total_amount}, Fee: ${orders[0].platform_fee}, Seller: ${orders[0].seller_amount}`);

    expect(orders[0].order_status).toBe('pending_payment');
    expect(orders[0].payment_status).toBe('unpaid');
});

// ═══════════════════════════════════════════════════
// STEP 6: BUYER INITIATES PAYMENT
// ═══════════════════════════════════════════════════
test('STEP 6: Buyer creates payment intent', async () => {
    console.log('\n📌 STEP 6: Buyer initiates payment');

    const res = await apiCall('POST', '/payments/intent', {
        order_id: orderId,
        payment_method: 'jazzcash',
        mode: 'sandbox',
    }, buyerToken);

    console.log(`📥 [API] POST /payments/intent → ${res.status}`);
    if (res.status !== 201) {
        console.log('❌ Error:', JSON.stringify(res.data, null, 2));
    }
    expect(res.status).toBe(201);
    expect(res.data.data.amount).toBe(BID_AMOUNT);
    console.log(`✅ Intent: ${res.data.data.payment_intent_id}`);

    const payments = await query<any[]>('SELECT * FROM payments WHERE order_id = ?', [orderId]);
    expect(payments.length).toBe(1);
    console.log(`✅ Payment record created`);
});

// ═══════════════════════════════════════════════════
// STEP 7: PAYMENT WEBHOOK → ORDER PAID + ESCROW HELD
// ═══════════════════════════════════════════════════
test('STEP 7: Payment webhook fires', async () => {
    console.log('\n📌 STEP 7: Simulating payment webhook');

    // Sandbox endpoint requires ADMIN role (security fix)
    // Use admin token, not buyer token
    const res = await fetch(`${API}/payments/sandbox/simulate-webhook`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${adminToken}`,  // ← Admin token
        },
        body: JSON.stringify({
            gateway: 'jazzcash',
            order_id: orderId,
            status: 'paid',
        }),
    });

    const data = await res.json().catch(() => ({}));
    console.log(`📥 [WEBHOOK] ${res.status}`, JSON.stringify(data).slice(0, 300));

    if (res.status !== 200) {
        throw new Error(
            `Webhook failed with ${res.status}.\n` +
            `Response: ${JSON.stringify(data, null, 2)}\n` +
            `Note: sandbox webhook requires admin role. Check if admin user was elevated correctly.`
        );
    }

    await new Promise(r => setTimeout(r, 2000));

    const orders = await query<any[]>('SELECT * FROM orders WHERE order_id = ?', [orderId]);
    expect(orders[0].payment_status).toBe('paid');
    console.log(`✅ Order PAID`);

    const escrow = await query<any[]>('SELECT * FROM escrow_holds WHERE order_id = ?', [orderId]);
    expect(escrow.length).toBeGreaterThan(0);
    expect(escrow[0].status).toBe('held');
    console.log(`✅ Escrow held: ${escrow[0].amount} PKR`);

    const wallet = await query<any[]>('SELECT * FROM seller_wallet WHERE seller_id = ?', [sellerId]);
    expect(wallet.length).toBe(1);
    expect(parseFloat(wallet[0].pending_balance)).toBe(EXPECTED_SELLER_AMOUNT);
    console.log(`✅ Seller pending: ${wallet[0].pending_balance}`);
});

// ═══════════════════════════════════════════════════
// STEP 8: SELLER SHIPS
// ═══════════════════════════════════════════════════
test('STEP 8: Seller ships order', async () => {
    console.log('\n📌 STEP 8: Seller ships order');

    // Add shipping address first
    await apiCall('POST', '/shipping/addresses', {
        full_name: 'Lifecycle Buyer',
        phone: '03001234567',
        address: 'House 1, Test Street',
        city: 'Karachi',
        is_default: true,
    }, buyerToken);

    const res = await apiCall('POST', `/shipping/orders/${orderId}/ship`, {
        courier: 'TCS Express',
        tracking_number: `TCS${Date.now()}`,
        estimated_delivery: new Date(Date.now() + 3 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
    }, sellerToken);

    console.log(`📥 [API] POST /ship → ${res.status}`);
    if (res.status !== 201) {
        console.log('❌ Error:', JSON.stringify(res.data, null, 2));
    }
    expect(res.status).toBe(201);

    const orders = await query<any[]>('SELECT shipping_status FROM orders WHERE order_id = ?', [orderId]);
    expect(orders[0].shipping_status).toBe('shipped');
    console.log(`✅ Order shipped`);
});

// ═══════════════════════════════════════════════════
// STEP 9: BUYER CONFIRMS DELIVERY
// ═══════════════════════════════════════════════════
test('STEP 9: Buyer confirms delivery', async () => {
    console.log('\n📌 STEP 9: Buyer confirms delivery');

    const res = await apiCall('POST', `/orders/${orderId}/confirm-receipt`, {}, buyerToken);

    console.log(`📥 [API] POST /confirm-receipt → ${res.status}`);
    if (res.status !== 200 && res.status !== 201) {
        console.log('❌ Error:', JSON.stringify(res.data, null, 2));
    }
    expect([200, 201]).toContain(res.status);

    await new Promise(r => setTimeout(r, 1500));

    const orders = await query<any[]>('SELECT order_status FROM orders WHERE order_id = ?', [orderId]);
    expect(orders[0].order_status).toBe('completed');
    console.log(`✅ Order COMPLETED`);

    const escrow = await query<any[]>('SELECT status FROM escrow_holds WHERE order_id = ?', [orderId]);
    expect(escrow[0].status).toBe('released');
    console.log(`✅ Escrow RELEASED`);

    const wallet = await query<any[]>('SELECT * FROM seller_wallet WHERE seller_id = ?', [sellerId]);
    expect(parseFloat(wallet[0].pending_balance)).toBe(0);
    expect(parseFloat(wallet[0].available_balance)).toBe(EXPECTED_SELLER_AMOUNT);
    console.log(`✅ Seller available: ${wallet[0].available_balance} PKR`);
    console.log(`🎉 SELLER CAN WITHDRAW ${wallet[0].available_balance} PKR!`);
});

// ═══════════════════════════════════════════════════
// STEP 10: FINAL SUMMARY
// ═══════════════════════════════════════════════════
test('STEP 10: Final verification', async () => {
    console.log('\n📌 STEP 10: Final state');

    const auction = await query<any[]>('SELECT * FROM auctions WHERE auction_id = ?', [auctionId]);
    const order = await query<any[]>('SELECT * FROM orders WHERE order_id = ?', [orderId]);
    const escrow = await query<any[]>('SELECT * FROM escrow_holds WHERE order_id = ?', [orderId]);
    const wallet = await query<any[]>('SELECT * FROM seller_wallet WHERE seller_id = ?', [sellerId]);

    console.log('\n════════════════════════════════════════');
    console.log('       FINAL STATE SUMMARY');
    console.log('════════════════════════════════════════');
    console.log(`Auction #${auctionId}: ${auction[0].auction_status} ✅ (final: ${auction[0].current_bid} PKR)`);
    console.log(`Order #${orderId}: ${order[0].order_status} ✅ | Payment: ${order[0].payment_status} ✅ | Shipping: ${order[0].shipping_status} ✅`);
    console.log(`  Total: ${order[0].total_amount} | Fee: ${order[0].platform_fee} | Seller: ${order[0].seller_amount}`);
    console.log(`Escrow: ${escrow[0].status} ✅ (${escrow[0].amount} PKR)`);
    console.log(`Wallet: pending=${wallet[0].pending_balance} | available=${wallet[0].available_balance} ✅`);
    console.log('════════════════════════════════════════');
    console.log('🎉 FULL LIFECYCLE COMPLETE!\n');
});

test.afterAll(async () => {
    console.log('\n🧹 CLEANUP...');
    if (orderId) {
        await query('DELETE FROM shipment_tracking WHERE order_id = ?', [orderId]).catch(() => { });
        await query('DELETE FROM shipments WHERE order_id = ?', [orderId]).catch(() => { });
        await query('DELETE FROM escrow_holds WHERE order_id = ?', [orderId]).catch(() => { });
        await query('DELETE FROM seller_ledger WHERE order_id = ?', [orderId]).catch(() => { });
        await query('DELETE FROM payment_audit_log WHERE entity_id = ?', [orderId]).catch(() => { });
        await query('DELETE FROM payments WHERE order_id = ?', [orderId]).catch(() => { });
        await query('DELETE FROM orders WHERE order_id = ?', [orderId]).catch(() => { });
    }
    if (auctionId) {
        await query('DELETE FROM bids WHERE auction_id = ?', [auctionId]).catch(() => { });
        await query('DELETE FROM winners WHERE auction_id = ?', [auctionId]).catch(() => { });
        await query('DELETE FROM auctions WHERE auction_id = ?', [auctionId]).catch(() => { });
    }
    if (sellerId) await query('DELETE FROM seller_wallet WHERE seller_id = ?', [sellerId]).catch(() => { });
    for (const email of [buyerEmail, sellerEmail, adminEmail]) {
        if (!email) continue;
        const u = await query<any[]>('SELECT user_id FROM users WHERE email = ?', [email]).catch(() => []);
        if (u.length) {
            await query('DELETE FROM refresh_tokens WHERE user_id = ?', [u[0].user_id]).catch(() => { });
            await query('DELETE FROM users WHERE email = ?', [email]).catch(() => { });
        }
    }
    console.log('✅ Cleanup complete');
});