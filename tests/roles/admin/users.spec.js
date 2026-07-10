// @ts-check
// User management — list, deactivate/reactivate, password reset.
const { test, expect } = require('@playwright/test');
const { visit, login, logout, BASE, AUTH } = require('../../helpers');
const { ADMIN_USER, ADMIN_PASS } = require('../../credentials');

test.describe.configure({ mode: 'serial' });
test.use({ storageState: AUTH.admin });

// The test DB has no disposable "guest" login account, so these tests register
// their own throwaway account (via the real registration flow) rather than
// depending on a fixture account that may not exist. Deleted in afterAll.
const TS = Date.now();
const THROWAWAY_USER = `pwtest${TS}`;

test('users page lists accounts', async ({ page }) => {
    await visit(page, '/admin/users.php', 'users list');
    await expect(page.locator('tbody tr').first()).toBeVisible();
});

test('setup: register a throwaway account for deactivate/password tests', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto(BASE + '/register.php');
    await page.fill('input[name="first_name"]', 'Pwtest');
    await page.fill('input[name="last_name"]', `User${TS}`);
    await page.fill('input[name="date_of_birth"]', '2000-01-01');
    await page.fill('input[name="email"]', `${THROWAWAY_USER}@test.invalid`);
    await page.fill('input[name="username"]', THROWAWAY_USER);
    await page.fill('input[name="password"]', 'TestPass1!');
    await page.fill('input[name="confirm"]', 'TestPass1!');
    await page.click('button:has-text("Next")');
    await page.waitForLoadState('domcontentloaded');
    await page.click('button:has-text("Create Account")');
    await page.waitForLoadState('domcontentloaded');
    // Registration logs the new user in — switch back to admin for the rest of the suite.
    await logout(page);
    await login(page, ADMIN_USER, ADMIN_PASS);
});

test('toggle deactivate then re-activate a non-admin user', async ({ page }) => {
    await page.goto(BASE + '/admin/users.php');
    const row = page.locator('tbody tr').filter({ hasText: THROWAWAY_USER }).first();
    await expect(row).toHaveCount(1); // created by the setup test above
    await row.locator('a:has-text("View")').click();
    await page.waitForLoadState('domcontentloaded');
    const btn = page.locator('button:has-text("Deactivate"), button:has-text("Activate")').first();
    await expect(btn).toBeVisible();
    const before = (await btn.textContent())?.trim();
    page.once('dialog', d => d.accept());
    await btn.click();
    await page.waitForLoadState('domcontentloaded');
    const after = before === 'Deactivate' ? 'Activate' : 'Deactivate';
    await expect(page.locator(`button:has-text("${after}")`)).toBeVisible();
    // Re-activate immediately so later tests see a normal account state.
    page.once('dialog', d => d.accept());
    await page.locator(`button:has-text("${after}")`).click();
    await page.waitForLoadState('domcontentloaded');
});

test('password reset form reveals on click', async ({ page }) => {
    await page.goto(BASE + '/admin/users.php');
    await page.locator('a:has-text("View")').first().click();
    await page.waitForLoadState('domcontentloaded');
    await page.locator('button:has-text("Change")').click();
    await expect(page.locator('input[name="new_password"]')).toBeVisible();
});

test('admin can search / filter user list', async ({ page }) => {
    await page.goto(BASE + '/admin/users.php');
    const searchInput = page.locator('input[type="search"], input[type="text"]').first();
    if (await searchInput.count() > 0 && await searchInput.isVisible()) {
        await searchInput.fill('instructor');
        // At least the instructor account should appear
        await expect(page.locator('tbody').first()).toContainText('instructor');
    }
});

test('admin can set a new password for another user via the detail page', async ({ page }) => {
    test.setTimeout(20000);
    await page.goto(BASE + '/admin/users.php');
    const row = page.locator('tbody tr').filter({ hasText: THROWAWAY_USER }).first();
    await expect(row).toHaveCount(1); // created by the setup test above
    await row.locator('a:has-text("View")').click();
    await page.waitForLoadState('domcontentloaded');
    await page.locator('button:has-text("Change")').click();
    const pwInput = page.locator('input[name="new_password"]');
    await pwInput.waitFor({ state: 'visible' });
    await pwInput.fill('TestPass1!'); // set back to same password — effectively a no-op
    await page.locator('button:has-text("Set Password")').click();
    await page.waitForLoadState('domcontentloaded');
    // Should show success or reload without error
    await expect(page.locator('body')).not.toContainText('Fatal error');
    await expect(page.locator('body')).not.toContainText('SQLSTATE');
});

test.afterAll(async ({ browser }) => {
    const page = await browser.newPage({ storageState: AUTH.admin });
    await page.goto(BASE + '/admin/students.php');
    const row = page.locator('tr').filter({ hasText: `User${TS}` });
    if (await row.count() > 0) {
        const profileHref = await row.locator('a[href*="student_profile.php"]').first().getAttribute('href');
        const match = profileHref?.match(/[?&]id=(\d+)/);
        if (match) {
            await page.goto(BASE + '/admin/student_edit.php?id=' + match[1]);
            await page.waitForLoadState('domcontentloaded');
            const deleteBtn = page.locator('button:has-text("Delete Profile")');
            if (await deleteBtn.isVisible()) {
                page.once('dialog', d => d.accept());
                await deleteBtn.click();
                await page.waitForLoadState('domcontentloaded');
            }
        }
    }
    await page.close();
});
