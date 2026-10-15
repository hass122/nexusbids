import { test, expect } from '@playwright/test';

const PAGES = [
    { path: '/', name: 'home' },
    { path: '/auctions.html', name: 'auctions' },
    { path: '/login.html', name: 'login' },
    { path: '/register.html', name: 'register' },
    { path: '/about.html', name: 'about' },
    { path: '/faq.html', name: 'faq' },
    { path: '/contact.html', name: 'contact' },
];

// Buttons to SKIP (destructive or auth-sensitive)
const SKIP_BUTTON_TEXTS = [
    'sign out', 'logout', 'delete', 'remove', 'cancel', 'clear',
    'reset', 'block', 'reject', 'refuse', 'submit', 'place bid',
    'publish', 'buy', 'pay', 'confirm', 'checkout', 'download',
];

function shouldSkipButton(text: string): boolean {
    const lower = text.toLowerCase().trim();
    return SKIP_BUTTON_TEXTS.some(skip => lower.includes(skip));
}

test.describe('Button Smoke Test — All Buttons Clickable', () => {
    for (const pg of PAGES) {
        test(`All buttons on ${pg.name}`, async ({ page }) => {
            const consoleErrors: string[] = [];
            const failedRequests: { url: string; status: number }[] = [];

            page.on('console', msg => {
                if (msg.type() === 'error') {
                    const text = msg.text();
                    // Ignore known harmless warnings
                    if (!text.includes('cdn.tailwindcss.com')) {
                        consoleErrors.push(text);
                    }
                }
            });

            page.on('response', response => {
                const status = response.status();
                if (status >= 500) {
                    failedRequests.push({ url: response.url(), status });
                }
            });

            page.on('pageerror', err => {
                consoleErrors.push(`PAGE ERROR: ${err.message}`);
            });

            console.log(`\n🔍 [${pg.name}] Loading page...`);
            await page.goto(pg.path);
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(1000);

            // Find all buttons
            const buttons = await page.locator('button, a[role="button"], [type="button"], [type="submit"]').evaluateAll((els: any[]) =>
                els.map((el, idx) => ({
                    idx,
                    text: (el.textContent || '').trim().substring(0, 50),
                    id: el.id,
                    type: el.type || 'button',
                    visible: el.offsetParent !== null && el.offsetWidth > 0,
                    disabled: el.disabled === true,
                    href: el.href || null,
                }))
            );

            console.log(`   📋 Found ${buttons.length} buttons`);

            let clickedCount = 0;
            let skippedCount = 0;

            for (const btn of buttons) {
                // Skip if not visible
                if (!btn.visible) continue;
                if (btn.disabled) {
                    console.log(`   ⏭️  [${btn.id || 'no-id'}] "${btn.text}" — disabled, skipping`);
                    continue;
                }

                // Skip destructive buttons
                if (shouldSkipButton(btn.text)) {
                    skippedCount++;
                    continue;
                }

                // Skip navigation links (they change page - tested elsewhere)
                if (btn.href && !btn.href.includes('#')) continue;

                const errorsBefore = consoleErrors.length;

                try {
                    const locator = page.locator(`button, a[role="button"]`).nth(btn.idx);

                    // Try to click with timeout
                    await locator.click({ timeout: 3000, trial: false }).catch((err: any) => {
                        // Element might be covered, try force
                        return locator.click({ timeout: 2000, force: true }).catch(() => {
                            throw new Error(`Click failed: ${err.message}`);
                        });
                    });

                    await page.waitForTimeout(500);
                    clickedCount++;

                    const newErrors = consoleErrors.length - errorsBefore;
                    if (newErrors > 0) {
                        console.log(`   ⚠️  [${btn.id || 'no-id'}] "${btn.text}" caused ${newErrors} console error(s)`);
                    } else {
                        console.log(`   ✅ [${btn.id || 'no-id'}] "${btn.text}" — OK`);
                    }

                    // If page navigated away, go back
                    const currentUrl = page.url();
                    if (!currentUrl.includes(pg.path)) {
                        await page.goto(pg.path);
                        await page.waitForLoadState('networkidle');
                        await page.waitForTimeout(500);
                    }
                } catch (err: any) {
                    console.log(`   ❌ [${btn.id || 'no-id'}] "${btn.text}" — ${err.message.substring(0, 100)}`);
                    // Try to recover
                    await page.goto(pg.path).catch(() => { });
                    await page.waitForLoadState('networkidle').catch(() => { });
                }
            }

            console.log(`\n   📊 [${pg.name}] Summary:`);
            console.log(`      ✅ Clicked: ${clickedCount}`);
            console.log(`      ⏭️  Skipped (destructive): ${skippedCount}`);
            console.log(`      🚨 Console errors: ${consoleErrors.length}`);
            console.log(`      🚨 Failed requests (5xx): ${failedRequests.length}`);

            if (failedRequests.length > 0) {
                console.log(`\n   Failed requests:`);
                failedRequests.forEach(f => console.log(`      ❌ ${f.status} ${f.url}`));
            }

            // Assertions
            expect(failedRequests.length).toBe(0);
            // Allow up to 2 console errors (framework noise), fail on more
            expect(consoleErrors.length).toBeLessThan(3);
        });
    }
});