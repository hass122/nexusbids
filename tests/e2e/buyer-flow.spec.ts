import { test, expect } from '@playwright/test';

test.describe('NexusBids E2E — Real Browser Flow', () => {

    test('User can register, login, and view dashboard', async ({ page }) => {
        const timestamp = Date.now();
        const email = `e2e_user_${timestamp}@test.com`;
        const username = `e2e_${timestamp}`; // Unique username
        const password = 'Test@12345';
        const phone = `0300${Math.floor(1000000 + Math.random() * 9000000)}`;

        // Debug listeners
        page.on('console', msg => {
            if (msg.type() === 'error' || msg.type() === 'warning') console.log(`🖥️ [Console]:`, msg.text());
        });
        page.on('response', async response => {
            if (response.url().includes('/api/v1/auth/')) {
                console.log(`🌐 [API] ${response.request().method()} ${response.url()} → ${response.status()}`);
                if (response.status() >= 400) {
                    try { console.log(`❌ [Error Body]:`, await response.json()); } catch { }
                }
            }
        });

        // ═══════════════════════════════════════════
        // STEP 1: REGISTER
        // ═══════════════════════════════════════════
        console.log('🌐 Opening registration page...');
        await page.goto('/register.html');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);

        await page.locator('input[type="password"]').first().waitFor({ state: 'visible', timeout: 15000 });
        const registerForm = page.locator('form').filter({ has: page.locator('input[type="password"]') }).first();

        // 🔍 DEBUG: List all inputs in the form
        const formInputs = await registerForm.locator('input').evaluateAll((els: any[]) =>
            els.map((el) => ({ type: el.type, name: el.name, id: el.id, placeholder: el.placeholder, required: el.required }))
        );
        console.log('🔍 Register form inputs:', JSON.stringify(formInputs, null, 2));

        // Fill full name
        for (const sel of ['input[name="full_name"]', 'input[name="fullName"]', 'input[id="full_name"]']) {
            const loc = registerForm.locator(sel).first();
            if (await loc.count() > 0 && await loc.isVisible().catch(() => false)) {
                await loc.fill('E2E Test User');
                console.log('✅ Filled full_name');
                break;
            }
        }

        // Fill username (NEW — this was missing!)
        for (const sel of ['input[name="username"]', 'input[id="username"]', 'input[placeholder*="sername" i]']) {
            const loc = registerForm.locator(sel).first();
            if (await loc.count() > 0 && await loc.isVisible().catch(() => false)) {
                await loc.fill(username);
                console.log('✅ Filled username:', username);
                break;
            }
        }

        // Fill email
        await registerForm.locator('input[name="email"], input[id="email"]').first().fill(email);
        console.log('✅ Filled email');

        // Fill phone
        for (const sel of ['input[type="tel"]', 'input[name="phone"]', 'input[id="phone"]']) {
            const loc = registerForm.locator(sel).first();
            if (await loc.count() > 0) { await loc.fill(phone); console.log('✅ Filled phone'); break; }
        }

        // Fill ALL password fields
        const passwordInputs = registerForm.locator('input[type="password"]');
        const passCount = await passwordInputs.count();
        console.log(`🔍 Found ${passCount} password fields. Filling all...`);
        for (let i = 0; i < passCount; i++) {
            if (await passwordInputs.nth(i).isVisible()) {
                await passwordInputs.nth(i).fill(password);
            }
        }
        console.log('✅ Filled password fields');

        // Check all checkboxes (Terms & Conditions)
        const checkboxes = registerForm.locator('input[type="checkbox"]');
        const cbCount = await checkboxes.count();
        if (cbCount > 0) {
            console.log(`🔍 Checking ${cbCount} checkbox(es)...`);
            for (let i = 0; i < cbCount; i++) {
                await checkboxes.nth(i).check({ force: true }).catch(() => { });
            }
        }

        // Submit & Wait for API Response
        console.log('✅ Submitting registration form...');
        const regResponsePromise = page.waitForResponse(res => res.url().includes('/api/v1/auth/register'), { timeout: 10000 }).catch(() => null);

        await registerForm.locator('button[type="submit"]').first().click();

        const regResponse = await regResponsePromise;

        if (regResponse) {
            console.log(`🌐 [API] Registration API responded: ${regResponse.status()}`);
            if (regResponse.status() !== 201) {
                const body = await regResponse.json().catch(() => ({}));
                console.log('❌ Registration API Error:', body);
                throw new Error(`Registration failed: ${body.error || 'Unknown'}`);
            }
        } else {
            console.log('❌ Registration API was NEVER called. Form validation failed.');
            await page.screenshot({ path: 'tests/e2e/register-failed.png', fullPage: true });

            const errorText = await page.locator('.text-red-500, .text-rose-500, [role="alert"], .error').allTextContents();
            if (errorText.length) console.log('🚨 [Frontend Error Text]:', errorText.join(' | '));

            throw new Error('Registration failed. Check screenshot & error text above.');
        }

        await page.waitForTimeout(2500);

        // ═══════════════════════════════════════════
        // STEP 2: LOGIN
        // ═══════════════════════════════════════════
        console.log('🔑 Going to login page...');
        await page.goto('/login.html');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);

        await page.locator('input[type="password"]').first().waitFor({ state: 'visible', timeout: 15000 });
        const loginForm = page.locator('form').filter({ has: page.locator('input[type="password"]') }).first();

        await loginForm.locator('input[name="email"], input[id="email"]').first().fill(email);
        await loginForm.locator('input[type="password"]').first().fill(password);

        console.log('🚀 Logging in...');
        const loginResponsePromise = page.waitForResponse(res => res.url().includes('/api/v1/auth/login'), { timeout: 10000 }).catch(() => null);

        await loginForm.locator('button[type="submit"]').first().click();

        const loginResponse = await loginResponsePromise;
        if (loginResponse) {
            console.log(`🌐 [API] Login API responded: ${loginResponse.status()}`);
        }

        await page.waitForTimeout(2000);

        // ═══════════════════════════════════════════
        // STEP 3: VERIFY DASHBOARD
        // ═══════════════════════════════════════════
        const currentUrl = page.url();
        if (currentUrl.includes('dashboard.html')) {
            console.log('🎉 Successfully redirected to Buyer Dashboard!');
        } else {
            console.log(`❌ Login redirect failed. Current URL: ${currentUrl}`);
            await page.screenshot({ path: 'tests/e2e/login-failed.png', fullPage: true });
            throw new Error('Login did not redirect to dashboard.');
        }

        await expect(page.locator('#nb-header')).toBeVisible({ timeout: 10000 });
        await page.screenshot({ path: 'tests/e2e/dashboard-success.png', fullPage: true });
        console.log('📸 Screenshot saved: tests/e2e/dashboard-success.png');
    });

});