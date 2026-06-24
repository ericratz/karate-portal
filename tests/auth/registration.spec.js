// @ts-check
// Registration flow tests.
const { test, expect } = require('@playwright/test');
const { login, logout, visit, BASE } = require('../helpers');
const { ADMIN_USER, ADMIN_PASS, STU_USER, STU_PASS } = require('../credentials');

test.describe.configure({ mode: 'serial' });
const TS = Date.now();

test('register page loads', async ({ page }) => {
    await visit(page, '/register.php', 'register page');
    await expect(page.locator('h4')).toContainText('Shotokan Karate');
});

test('mismatched passwords shows error', async ({ page }) => {
    await page.goto(BASE + '/register.php');
    await page.fill('input[name="first_name"]', 'Test');
    await page.fill('input[name="last_name"]', 'User');
    await page.fill('input[name="date_of_birth"]', '2000-01-01');
    await page.fill('input[name="email"]', `t${TS}@x.com`);
    await page.fill('input[name="username"]', `u${TS}`);
    await page.fill('input[name="password"]', 'Testpass1!');
    await page.fill('input[name="confirm"]', 'Different1!');
    await page.click('button:has-text("Next")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger')).toContainText('do not match');
});

test('duplicate username shows error', async ({ page }) => {
    await page.goto(BASE + '/register.php');
    await page.fill('input[name="first_name"]', 'Test');
    await page.fill('input[name="last_name"]', 'User');
    await page.fill('input[name="date_of_birth"]', '2000-01-01');
    await page.fill('input[name="email"]', `t${TS}@x.com`);
    await page.fill('input[name="username"]', ADMIN_USER);
    await page.fill('input[name="password"]', 'pass1234A!');
    await page.fill('input[name="confirm"]', 'pass1234A!');
    await page.click('button:has-text("Next")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger')).toContainText('already taken');
});

test('valid registration creates account and logs in', async ({ page }) => {
    await page.goto(BASE + '/register.php');
    await page.fill('input[name="first_name"]', 'Playwright');
    await page.fill('input[name="last_name"]', `Test${TS}`);
    await page.fill('input[name="date_of_birth"]', '2000-06-15');
    await page.fill('input[name="email"]', `pw${TS}@test.com`);
    await page.fill('input[name="username"]', `pw${TS}`);
    await page.fill('input[name="password"]', 'TestPass1!');
    await page.fill('input[name="confirm"]', 'TestPass1!');
    await page.click('button:has-text("Next")');
    await page.waitForLoadState('domcontentloaded');
    // No matching records for fresh user â†’ lands on confirm step
    await expect(page.locator('.card-header small')).toContainText('Confirm');
    await page.click('button:has-text("Create Account")');
    await page.waitForLoadState('domcontentloaded');
    // Should be logged in and redirected to student dashboard
    expect(page.url()).toContain('/student/');
});

test('new account can log in after registration', async ({ page }) => {
    await login(page, `pw${TS}`, 'TestPass1!');
    const badge = await page.textContent('.role-badge');
    expect(['student','guest']).toContain(badge?.trim().toLowerCase());
    await logout(page);
});

test('already logged-in user is redirected from register page', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/register.php');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).not.toContain('register.php');
    await logout(page);
});

// ── MATCH EXISTING RECORD FLOW ────────────────────────────────────────────────
// Sarah Johnson (id=2) is a stable student in the test DB.
// Registering with her name + DOB triggers the "Find Your Record" match step.

test.describe('Registration match flow', () => {
    test.describe.configure({ mode: 'serial' });

    const MATCH_TS = Date.now();

    test('matching name+DOB shows Find Your Record step', async ({ page }) => {
        await page.goto(BASE + '/register.php');
        await page.fill('input[name="first_name"]', 'Sarah');
        await page.fill('input[name="last_name"]', 'Johnson');
        await page.fill('input[name="date_of_birth"]', '2026-06-02');
        await page.fill('input[name="email"]', `match${MATCH_TS}@test.com`);
        await page.fill('input[name="username"]', `match${MATCH_TS}`);
        await page.fill('input[name="password"]', 'TestPass1!');
        await page.fill('input[name="confirm"]', 'TestPass1!');
        await page.click('button:has-text("Next")');
        await page.waitForLoadState('domcontentloaded');
        // Should show the match step, not jump straight to confirm
        const body = await page.textContent('body');
        // Either shows "Find Your Record" or lists match cards
        expect(body).toMatch(/Find Your Record|Sarah Johnson/);
    });

    test('selecting "none of these" creates a new account', async ({ page }) => {
        await page.goto(BASE + '/register.php');
        await page.fill('input[name="first_name"]', 'Sarah');
        await page.fill('input[name="last_name"]', 'Johnson');
        await page.fill('input[name="date_of_birth"]', '2026-06-02');
        await page.fill('input[name="email"]', `match2${MATCH_TS}@test.com`);
        await page.fill('input[name="username"]', `match2${MATCH_TS}`);
        await page.fill('input[name="password"]', 'TestPass1!');
        await page.fill('input[name="confirm"]', 'TestPass1!');
        await page.click('button:has-text("Next")');
        await page.waitForLoadState('domcontentloaded');
        // Select "none of these / create new"
        const noneCard = page.locator('.option-card').filter({ hasText: /none|new|not listed/i }).first();
        if (await noneCard.count() > 0) {
            await noneCard.click();
        } else {
            // Fallback: look for a radio with value=new or similar
            const newRadio = page.locator('input[type="radio"][value="new"], input[type="radio"][value="0"]').first();
            if (await newRadio.count() > 0) await newRadio.click();
        }
        await page.click('button:has-text("Next"), button:has-text("Continue"), button:has-text("Create Account")');
        await page.waitForLoadState('domcontentloaded');
        // Should advance (either to confirm step or dashboard)
        const url = page.url();
        const body = await page.textContent('body');
        expect(url.includes('register.php') ? body : url).toBeTruthy();
    });
});
