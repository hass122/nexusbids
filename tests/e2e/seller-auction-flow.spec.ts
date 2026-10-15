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

let sellerEmail: string;
let sellerId: number;
let sellerToken: string;
const auctionTitle = `E2E Test Auction ${Date.now()}`;

test.beforeAll(async () => {
    console.log('🔧 Setup: Registering seller + category...');
    const ts = Date.now();

    const catRows = await query<any[]>('SELECT category_id FROM categories WHERE category_id = 1');
    if (!catRows.length) {
        await query("INSERT INTO categories (category_id, category_name, status, created_at) VALUES (1, 'Test Category', 'active', NOW())");
    }

    sellerEmail = `e2e_seller_${ts}@test.com`;
    const reg = await apiPost('/auth/register', {
        full_name: 'Auction Test Seller',
        username: `seller_${ts}`,
        email: sellerEmail,
        password: 'Test@12345',
        phone: `0300${Math.floor(1000000 + Math.random() * 9000000)}`,
        role: 'seller',
    });
    if (reg.status !== 201) throw new Error(`Seller reg failed: ${JSON.stringify(reg.data)}`);

    await query("UPDATE users SET seller_kyc_status='approved' WHERE email=?", [sellerEmail]);
    const sRows = await query<any[]>('SELECT user_id FROM users WHERE email = ?', [sellerEmail]);
    sellerId = sRows[0].user_id;

    // Login to get token
    const login = await apiPost('/auth/login', { email: sellerEmail, password: 'Test@12345' });
    sellerToken = login.data.data.accessToken;

    console.log(`✅ Seller ready: ${sellerEmail}`);
});

test('Seller can create a new auction via UI', async ({ page }) => {
    // ═══════════════════════════════════════════
    // NETWORK LOGGING
    // ═══════════════════════════════════════════
    page.on('request', req => {
        if (req.method() === 'POST') {
            console.log(`📤 [REQ] POST ${req.url()}`);
            if (req.postData() && !req.postData()?.includes('base64') && req.postData()!.length < 1000) {
                console.log(`   Body: ${req.postData()}`);
            }
        }
    });
    page.on('response', async response => {
        if (response.request().method() === 'POST') {
            console.log(`📥 [RES] ${response.status()} ${response.url()}`);
            if (response.status() >= 400) {
                try {
                    const body = await response.json();
                    console.log('   Error:', JSON.stringify(body));
                } catch { }
            }
        }
    });
    page.on('console', msg => {
        if (msg.type() === 'error') console.log(`🖥️ [ERR]:`, msg.text());
    });

    // ═══════════════════════════════════════════
    // STEP 1: LOGIN
    // ═══════════════════════════════════════════
    console.log('\n🔑 Logging in as seller...');
    await page.goto('/login.html');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);
    const loginForm = page.locator('form').filter({ has: page.locator('input[type="password"]') }).first();
    await loginForm.locator('input[name="email"]').first().fill(sellerEmail);
    await loginForm.locator('input[type="password"]').first().fill('Test@12345');
    await loginForm.locator('button[type="submit"]').first().click();
    await page.waitForURL(/.*(seller|buyer)\/dashboard\.html/, { timeout: 15000 });
    console.log('🎉 Logged in');

    // ═══════════════════════════════════════════
    // STEP 2: NAVIGATE
    // ═══════════════════════════════════════════
    console.log('\n🏛️ Going to create auction page...');
    await page.goto('/seller/create-auction.html');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(2000);

    // ═══════════════════════════════════════════
    // STEP 3: FILL FORM (with safer values)
    // ═══════════════════════════════════════════
    console.log('\n📝 Filling form...');

    // Title
    await page.locator('#title').fill(auctionTitle);
    console.log('✅ Title filled');

    // Description
    await page.locator('#description').fill(
        'This is an automated E2E test auction listing created by Playwright. It has a complete description to pass validation.'
    );
    console.log('✅ Description filled');

    // Category (index 1 = first real category)
    await page.locator('#category_id').selectOption({ index: 1 });
    console.log('✅ Category selected');

    // Starting price
    await page.locator('#starting_price').fill('1000');
    console.log('✅ Starting price filled');

    // Bid increment = 500 (backend default, frontend surely accepts)
    await page.locator('#bid_increment').fill('500');
    console.log('✅ Bid increment = 500');

    // Reserve price (optional but let's fill it)
    await page.locator('#reserve_price').fill('1500');
    console.log('✅ Reserve price filled');

    // Buy now price (optional, but let's fill to be safe)
    await page.locator('#buy_now_price').fill('5000');
    console.log('✅ Buy now price filled');

    // Dates (5 min from now to 24 hours from now)
    const now = new Date();
    const start = new Date(now.getTime() + 5 * 60 * 1000);
    const end = new Date(now.getTime() + 24 * 60 * 60 * 1000);
    const fmt = (d: Date) => {
        const pad = (n: number) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    };
    await page.locator('#start_time').fill(fmt(start));
    await page.locator('#end_time').fill(fmt(end));
    console.log(`✅ Dates: ${fmt(start)} → ${fmt(end)}`);

    // ═══════════════════════════════════════════
    // STEP 4: BYPASS UPLOAD — Set hidden image field directly
    // ═══════════════════════════════════════════
    console.log('\n🖼️ Bypassing upload — Setting hidden image field...');

    // Use a valid placeholder image URL (guaranteed to work)
    const imageUrl = 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=500';

    await page.evaluate((url) => {
        const imageInput = document.querySelector('#image') as HTMLInputElement;
        if (imageInput) {
            imageInput.value = url;
            // Also dispatch change event in case frontend listens to it
            imageInput.dispatchEvent(new Event('change', { bubbles: true }));
            console.log('Image field set:', url);
        } else {
            console.warn('image input not found');
        }
    }, imageUrl);
    await page.waitForTimeout(500);

    const imageValue = await page.locator('#image').inputValue().catch(() => 'not-found');
    console.log(`🔍 Hidden image field value: "${imageValue}"`);

    // ═══════════════════════════════════════════
    // STEP 5: SUBMIT FORM
    // ═══════════════════════════════════════════
    console.log('\n🎯 Submitting form...');

    // Click the "Publish Auction Listing" button
    await page.locator('button[type="submit"]:has-text("Publish")').click();
    console.log('✅ Publish button clicked');

    await page.waitForTimeout(4000); // Wait for API + redirect

    // ═══════════════════════════════════════════
    // STEP 6: VERIFY IN DB
    // ═══════════════════════════════════════════
    console.log('\n🗄️ Checking DB...');
    const auctions = await query<any[]>(
        'SELECT * FROM auctions WHERE seller_id = ? AND title = ? ORDER BY auction_id DESC LIMIT 1',
        [sellerId, auctionTitle]
    );

    if (auctions.length === 0) {
        // Capture all errors
        console.log('\n❌ Auction not created. Debugging...');

        // Full page text
        const bodyText = await page.locator('body').innerText().catch(() => '');
        const errorLike = bodyText.split('\n').filter((l: string) =>
            l.trim().length > 0 &&
            l.length < 150 &&
            /error|fail|invalid|must|minimum|required|cannot|insufficient|please/i.test(l)
        );
        if (errorLike.length) {
            console.log('🚨 [Error-like text on page]:');
            errorLike.forEach(l => console.log('  >', l.trim()));
        }

        // Toast messages
        const toasts = await page.locator('[class*="toast"], [class*="alert"], [role="alert"], [class*="error"]').allTextContents().catch(() => []);
        const visibleToasts = toasts.filter(t => t.trim());
        if (visibleToasts.length) {
            console.log('🚨 [Toasts/Alerts]:', visibleToasts.join(' | '));
        }

        // Form validation messages (HTML5)
        const invalidFields = await page.locator('input:invalid, select:invalid, textarea:invalid').evaluateAll((els: any[]) =>
            els.map(el => ({ id: el.id, name: el.name, validationMessage: el.validationMessage }))
        ).catch(() => []);
        if (invalidFields.length) {
            console.log('🚨 [Invalid HTML5 fields]:', JSON.stringify(invalidFields, null, 2));
        }

        await page.screenshot({ path: 'tests/e2e/auction-failed-debug.png', fullPage: true });
        console.log('📸 Screenshot: tests/e2e/auction-failed-debug.png');

        throw new Error('Auction not created. Check screenshot.');
    }

    const auction = auctions[0];
    console.log(`✅ Auction created!`);
    console.log(`   ID: ${auction.auction_id}`);
    console.log(`   Title: ${auction.title}`);
    console.log(`   Status: ${auction.auction_status}`);
    console.log(`   Starting Price: ${auction.starting_price}`);
    console.log(`   Bid Increment: ${auction.bid_increment}`);
    console.log(`   Image: ${auction.image}`);

    expect(auction.auction_status).toBe('pending');
    expect(parseFloat(auction.starting_price)).toBe(1000);

    await page.screenshot({ path: 'tests/e2e/auction-created-success.png', fullPage: true });
    console.log('📸 Screenshot: tests/e2e/auction-created-success.png');
});

test.afterAll(async () => {
    console.log('\n🧹 Cleanup...');
    const auctions = await query<any[]>('SELECT auction_id FROM auctions WHERE seller_id = ? AND title = ?', [sellerId, auctionTitle]);
    for (const a of auctions) {
        await query('DELETE FROM auction_images WHERE auction_id = ?', [a.auction_id]).catch(() => { });
        await query('DELETE FROM auctions WHERE auction_id = ?', [a.auction_id]).catch(() => { });
    }
    if (sellerEmail) {
        const users = await query<any[]>('SELECT user_id FROM users WHERE email = ?', [sellerEmail]).catch(() => []);
        if (users.length) {
            await query('DELETE FROM refresh_tokens WHERE user_id = ?', [users[0].user_id]).catch(() => { });
            await query('DELETE FROM users WHERE email = ?', [sellerEmail]).catch(() => { });
        }
    }
    console.log('✅ Cleanup complete');
});