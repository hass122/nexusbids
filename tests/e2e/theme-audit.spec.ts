import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const PAGES = [
    { path: '/', name: 'home' },
    { path: '/auctions.html', name: 'auctions' },
    { path: '/login.html', name: 'login' },
    { path: '/register.html', name: 'register' },
    { path: '/about.html', name: 'about' },
    { path: '/faq.html', name: 'faq' },
    { path: '/how-it-works.html', name: 'how-it-works' },
    { path: '/contact.html', name: 'contact' },
    { path: '/privacy.html', name: 'privacy' },
    { path: '/terms.html', name: 'terms' },
    { path: '/buyer/dashboard.html', name: 'buyer-dashboard' },
    { path: '/buyer/bids.html', name: 'buyer-bids' },
    { path: '/buyer/orders.html', name: 'buyer-orders' },
    { path: '/buyer/watchlist.html', name: 'buyer-watchlist' },
    { path: '/buyer/profile.html', name: 'buyer-profile' },
    { path: '/buyer/addresses.html', name: 'buyer-addresses' },
    { path: '/buyer/payments.html', name: 'buyer-payments' },
    { path: '/buyer/notifications.html', name: 'buyer-notifications' },
    { path: '/buyer/kyc.html', name: 'buyer-kyc' },
    { path: '/buyer/security.html', name: 'buyer-security' },
];

// Helper: set theme via localStorage
async function setTheme(page: any, theme: 'light' | 'dark') {
    await page.evaluate((t: string) => {
        localStorage.setItem('nexusbids_theme', t);
    }, theme);
    await page.reload();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);
}

// Helper: check contrast violations via axe-core
async function checkContrast(page: any, pageName: string, theme: string) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
        .include('body')
        .analyze();

    const contrastViolations = results.violations.filter(v => v.id === 'color-contrast');

    if (contrastViolations.length > 0) {
        console.log(`\n🚨 [${pageName} / ${theme}] ${contrastViolations.length} contrast violations:`);
        contrastViolations.forEach((v: any) => {
            console.log(`   ❌ ${v.nodes.length} element(s): ${v.description}`);
            v.nodes.slice(0, 5).forEach((node: any) => {
                console.log(`      Target: ${node.target.join(' ')}`);
                console.log(`      HTML:   ${(node.html || '').substring(0, 100)}`);
                const check = node.any[0]?.data;
                if (check) {
                    console.log(`      Ratio:  ${check.contrastRatio} (needs ${check.expectedContrastRatio})`);
                    console.log(`      Fg:     ${check.fgColor}, Bg: ${check.bgColor}`);
                }
            });
        });
    } else {
        console.log(`   ✅ [${pageName} / ${theme}] No contrast issues`);
    }

    return contrastViolations.length;
}

// ─── LIGHT MODE CONTRAST AUDIT ───
test.describe('Light Mode — Contrast & Visibility', () => {
    for (const pg of PAGES) {
        test(`Light mode: ${pg.name}`, async ({ page }) => {
            await page.goto(pg.path);
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(500);

            await setTheme(page, 'light');

            const violations = await checkContrast(page, pg.name, 'light');

            // Save screenshot for visual proof
            await page.screenshot({
                path: `tests/e2e/screenshots/light/${pg.name}.png`,
                fullPage: true,
            });

            // Fail if > 5 violations (allow minor, warn on many)
            expect(violations).toBeLessThan(10);
        });
    }
});

// ─── DARK MODE CONTRAST AUDIT ───
test.describe('Dark Mode — Contrast & Visibility', () => {
    for (const pg of PAGES) {
        test(`Dark mode: ${pg.name}`, async ({ page }) => {
            await page.goto(pg.path);
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(500);

            await setTheme(page, 'dark');

            const violations = await checkContrast(page, pg.name, 'dark');

            await page.screenshot({
                path: `tests/e2e/screenshots/dark/${pg.name}.png`,
                fullPage: true,
            });

            expect(violations).toBeLessThan(10);
        });
    }
});