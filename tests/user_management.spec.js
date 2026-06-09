// @ts-check
// User management — list, deactivate/reactivate, password reset.
const { test, expect } = require('@playwright/test');
const { visit, BASE, AUTH } = require('./helpers');
const { GUEST_USER } = require('./credentials');

test.describe.configure({ mode: 'serial' });
test.use({ storageState: AUTH.admin });

test('users page lists accounts', async ({ page }) => {
    await visit(page, '/admin/users.php', 'users list');
    await expect(page.locator('tbody tr').first()).toBeVisible();
});

test('toggle deactivate then re-activate a non-admin user', async ({ page }) => {
    await page.goto(BASE + '/admin/users.php');
    // Target the guest account specifically — it is not used by other parallel tests' login steps
    const guestRow = page.locator('tbody tr').filter({ hasText: GUEST_USER }).first();
    if (await guestRow.count() === 0) return; // guest account not found, skip
    await guestRow.locator('a:has-text("View")').click();
    await page.waitForLoadState('domcontentloaded');
    const btn = page.locator('button:has-text("Deactivate"), button:has-text("Activate")').first();
    if (await btn.isVisible()) {
        const before = (await btn.textContent())?.trim();
        page.once('dialog', d => d.accept());
        await btn.click();
        await page.waitForLoadState('domcontentloaded');
        const after = before === 'Deactivate' ? 'Activate' : 'Deactivate';
        await expect(page.locator(`button:has-text("${after}")`)).toBeVisible();
        // Re-activate immediately so the guest account remains usable
        page.once('dialog', d => d.accept());
        await page.locator(`button:has-text("${after}")`).click();
        await page.waitForLoadState('domcontentloaded');
    }
});

test('password reset form reveals on click', async ({ page }) => {
    await page.goto(BASE + '/admin/users.php');
    await page.locator('a:has-text("View")').first().click();
    await page.waitForLoadState('domcontentloaded');
    await page.locator('button:has-text("Change")').click();
    await expect(page.locator('input[name="new_password"]')).toBeVisible();
});
