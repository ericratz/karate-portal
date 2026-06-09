// @ts-check
// Registration flow tests.
const { test, expect } = require('@playwright/test');
const { login, logout, visit, BASE } = require('./helpers');
const { ADMIN_USER, ADMIN_PASS, STU_USER, STU_PASS } = require('./credentials');

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
    await page.click('button:has-text("Create Account")');
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
    await page.click('button:has-text("Create Account")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger')).toContainText('already taken');
});

test('valid registration reaches Notify Noji step', async ({ page }) => {
    await page.goto(BASE + '/register.php');
    await page.fill('input[name="first_name"]', 'Playwright');
    await page.fill('input[name="last_name"]', `Test${TS}`);
    await page.fill('input[name="date_of_birth"]', '2000-06-15');
    await page.fill('input[name="email"]', `pw${TS}@test.com`);
    await page.fill('input[name="username"]', `pw${TS}`);
    await page.fill('input[name="password"]', 'TestPass1!');
    await page.fill('input[name="confirm"]', 'TestPass1!');
    await page.click('button:has-text("Create Account")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.option-card').first()).toBeVisible();
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
