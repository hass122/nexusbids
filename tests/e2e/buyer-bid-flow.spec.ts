import { test, expect } from '@playwright/test';
import { query } from '../../apps/api/src/config/db.js';

const API = 'http://localhost:5000/api/v1';

async function apiPost(path: string, body: any, token?: string) {
    const res = await fetch(`${API}${path}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Forwarded-For': `10.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}`,
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: JSON.stringify(body),
    });
    return { status: res.status, data: await res.json().catch(() => ({})) };
}

let buyerEmail: string;
let sellerEmail: string;
let sellerId: number;
let auctionId: number;
const STARTING_PRICE = 1000;
const BID_INCREMENT = 100;
const VALID_BID = STARTING_PRICE + BID_INCREMENT; // 1100

test.beforeAll(async () => {
    console.log('🔧 Setting up test data...');
    const ts = Date.now();

    const catRows = await query<any[]>('SELECT category_id FROM categories WHERE category_id = 1');
    if (!catRows.length) {
        await query("INSERT INTO categories (category_id, category_name, status, created_at) VALUES (1, 'Test Category', 'active', NOW())");
    }

    buyerEmail = `e2e_buyer_${ts}@test.com`;
    const regBuyer = await apiPost('/auth/register', {
        full_name: 'Bid Test Buyer', username: `bidder_${ts}`, email: buyerEmail,
        password: 'Test@12345', phone: `0300${Math.floor(1000000 + Math.random() * 9000000)}`, role: 'user',
    });
    if (regBuyer.status !== 201) throw new Error(`Buyer reg failed: ${JSON.stringify(regBuyer.data)}`);

    sellerEmail = `e2e_seller_${ts}@test.com`;
    const regSeller = await apiPost('/auth/register', {
        full_name: 'Bid Test Seller', username: `seller_${ts}`, email: sellerEmail,
        password: 'Test@12345', phone: `0300${Math.floor(1000000 + Math.random() * 9000000)}`, role: 'seller',
    });
    if (regSeller.status !== 201) throw new Error(`Seller reg failed: ${JSON.stringify(regSeller.data)}`);

    await query("UPDATE users SET kyc_status='approved' WHERE email=?", [buyerEmail]);
    await query("UPDATE users SET seller_kyc_status='approved' WHERE email=?", [sellerEmail]);

    const sRows = await query<any[]>('SELECT user_id FROM users WHERE email = ?', [sellerEmail]);
    sellerId = sRows[0].user_id;

    const aRes = await query<any>(
        `INSERT INTO auctions
         (seller_id, category_id, title, description, starting_price, current_bid, bid_increment, start_time, end_time, auction_status, created_at)
         VALUES (?, 1, 'E2E Bid Test Item', 'This auction was created for Playwright E2E testing.', ?, 0, ?, DATE_SUB(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 1 DAY), 'active', NOW())`,
        [sellerId, STARTING_PRICE, BID_INCREMENT]
    );
    auctionId = aRes.insertId;
    console.log(`✅ Setup: auction=#${auctionId} (min bid: ${VALID_BID})`);
});

test('Buyer can browse auctions and place a bid', async ({ page }) => {
    page.on('request', req => {
        if (req.method() === 'POST' && req.url().includes('/auctions')) {
            console.log(`📤 [REQ] POST ${req.url()}`);
            if (req.postData()) console.log(`   Body: ${req.postData()}`);
        }
    });
    page.on('response', async response => {
        if (response.url().includes('/api/v1/auctions') && response.request().method() === 'POST') {
            console.log(`📥 [RES] ${response.status()} ${response.url()}`);
            if (response.status() >= 400) {
                try { console.log('   Error:', JSON.stringify(await response.json())); } catch { }
            }
        }
    });
    page.on('console', msg => {
        if (msg.type() === 'error') console.log(`🖥️ [ERR]:`, msg.text());
    });

    // LOGIN
    console.log('\n🔑 Logging in...');
    await page.goto('/login.html');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);
    const loginForm = page.locator('form').filter({ has: page.locator('input[type="password"]') }).first();
    await loginForm.locator('input[name="email"]').first().fill(buyerEmail);
    await loginForm.locator('input[type="password"]').first().fill('Test@12345');
    await loginForm.locator('button[type="submit"]').first().click();
    await page.waitForURL(/.*dashboard\.html/, { timeout: 15000 });

    // BROWSE
    console.log('🏛️ Going to auction...');
    await page.goto(`/auction-details.html?id=${auctionId}`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);

    // ═══════════════════════════════════════════
    // STEP 4: SET INPUT VIA NATIVE SETTER with VALID BID (1100)
    // ═══════════════════════════════════════════
    console.log(`\n💡 Setting input via native setter to VALID bid (${VALID_BID})...`);
    await page.evaluate((bidValue) => {
        const input = document.querySelector('#input-bid-amount') as HTMLInputElement;
        if (!input) throw new Error('input not found');
        const nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')!.set!;
        nativeSetter.call(input, String(bidValue));
    }, VALID_BID);
    await page.waitForTimeout(300);

    const inputValue = await page.locator('#input-bid-amount').inputValue();
    console.log(`🔍 Input value after native set: "${inputValue}"`);

    // ═══════════════════════════════════════════
    // STEP 5: CLICK PLACE BID
    // ═══════════════════════════════════════════
    console.log('\n🎯 Clicking Place Bid...');
    await page.locator('#btn-place-bid').click();
    await page.waitForTimeout(1000);

    const confirmModal = page.locator('[data-nb-confirm-modal="1"]');
    const modalVisible = await confirmModal.isVisible().catch(() => false);

    if (modalVisible) {
        console.log('🔔 Real confirm modal appeared!');
        const modalBtns = await confirmModal.locator('button').evaluateAll((els: any[]) =>
            els.map(el => ({ text: el.textContent?.trim(), visible: el.offsetParent !== null }))
        );
        console.log('📋 Modal buttons:', JSON.stringify(modalBtns));

        const confirmBtn = confirmModal.locator('button').last();
        await confirmBtn.click();
        console.log('✅ Clicked modal confirm');
        await page.waitForTimeout(2500);
    } else {
        console.log('ℹ️ No confirm modal — bid went directly');
        await page.waitForTimeout(2500);
    }

    // ═══════════════════════════════════════════
    // STEP 6: VERIFY IN DB
    // ═══════════════════════════════════════════
    const bidsInDb = await query<any[]>('SELECT * FROM bids WHERE auction_id = ?', [auctionId]);
    console.log(`\n🗄️ Bids in DB: ${bidsInDb.length}`);

    if (bidsInDb.length === 0) {
        const bodyText = await page.locator('body').innerText().catch(() => '');
        const errorLines = bodyText.split('\n').filter((l: string) =>
            /error|fail|invalid|must|minimum|required|cannot|insufficient/i.test(l) && l.length < 200
        );
        if (errorLines.length) {
            console.log('🚨 [Error text on page]:');
            errorLines.forEach(l => console.log('  >', l.trim()));
        }
        await page.screenshot({ path: 'tests/e2e/bid-failed-debug.png', fullPage: true });
        throw new Error('Bid was not created. Check screenshot bid-failed-debug.png');
    }

    console.log(`✅ Bid created: amount=${bidsInDb[0].bid_amount}`);
    expect(parseFloat(bidsInDb[0].bid_amount)).toBe(VALID_BID);

    // VERIFY IN MY BIDS
    console.log('\n📊 Checking My Bids page...');
    await page.goto('/buyer/bids.html');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2500);
    await expect(page.locator('text=E2E Bid Test Item').first()).toBeVisible({ timeout: 10000 });
    console.log('🎉 Bid visible in My Bids page!');

    await page.screenshot({ path: 'tests/e2e/bid-success.png', fullPage: true });
});

test.afterAll(async () => {
    console.log('\n🧹 Cleanup...');
    if (auctionId) {
        await query('DELETE FROM bids WHERE auction_id = ?', [auctionId]).catch(() => { });
        await query('DELETE FROM auctions WHERE auction_id = ?', [auctionId]).catch(() => { });
    }
    for (const email of [buyerEmail, sellerEmail]) {
        if (!email) continue;
        const users = await query<any[]>('SELECT user_id FROM users WHERE email = ?', [email]).catch(() => []);
        if (users.length) {
            await query('DELETE FROM refresh_tokens WHERE user_id = ?', [users[0].user_id]).catch(() => { });
            await query('DELETE FROM users WHERE email = ?', [email]).catch(() => { });
        }
    }
    console.log('✅ Cleanup complete');
});