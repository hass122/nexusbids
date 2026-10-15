import { test, expect } from '@playwright/test';

test.describe('Login Flow', () => {
    test('user can login and see dashboard', async ({ page }) => {
        const email = `e2e_${Date.now()}@test.com`;
        const password = 'Test@12345';

        // Register first
        await page.goto('/register.html');
        await page.fill('input[name="full_name"], #full-name', 'E2E Test User');
        await page.fill('input[name="email"], #email', email);
        await page.fill('input[name="password"], #password', password);
        await page.fill('input[name="phone"], #phone', '03001234567');
        await page.click('button[type="submit"]');

        await page.waitForTimeout(2000);

        // Login
        await page.goto('/login.html');
        await page.fill('input[name="email"], #email', email);
        await page.fill('input[name="password"], #password', password);
        await page.click('button[type="submit"]');

        // Should redirect to dashboard
        await page.waitForURL('**/buyer/dashboard.html', { timeout: 10000 });
        await expect(page.locator('text=Buyer Dashboard, text=Dashboard')).toBeVisible();
    });

    test('shows error on wrong password', async ({ page }) => {
        await page.goto('/login.html');
        await page.fill('input[name="email"], #email', 'nonexistent@test.com');
        await page.fill('input[name="password"], #password', 'WrongPass');
        await page.click('button[type="submit"]');

        await expect(page.locator('text=Invalid, text=error')).toBeVisible({ timeout: 5000 });
    });
});