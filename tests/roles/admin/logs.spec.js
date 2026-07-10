// @ts-check
// Tests for admin/logs.php (combined tabbed log viewer) and admin/app_log.php (error log).
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

// ── COMBINED LOGS PAGE (logs.php) ─────────────────────────────────────────────

test.describe('Combined logs page', () => {
    test.use({ storageState: AUTH.admin });

    test('loads with "Logs" heading', async ({ page }) => {
        await page.goto(BASE + '/admin/logs.php');
        await assertNoPhpErrors(page, 'logs page');
        await expect(page.locator('h4')).toContainText('Logs');
    });

    test('has Activity, Errors, and Mail tab links', async ({ page }) => {
        await page.goto(BASE + '/admin/logs.php');
        await expect(page.locator('a.nav-link:has-text("Activity")')).toBeVisible();
        await expect(page.locator('a.nav-link:has-text("Errors")')).toBeVisible();
        await expect(page.locator('a.nav-link:has-text("Mail")')).toBeVisible();
    });

    test('Activity tab is active by default', async ({ page }) => {
        await page.goto(BASE + '/admin/logs.php');
        await expect(page.locator('a.nav-link.active:has-text("Activity")')).toBeVisible();
    });

    test('Errors tab loads and shows active Errors nav link', async ({ page }) => {
        // No assertNoPhpErrors — the error tab intentionally displays error log entries
        // whose message text can contain "Uncaught", "Warning", etc.
        await page.goto(BASE + '/admin/logs.php?tab=error');
        await expect(page.locator('a.nav-link.active:has-text("Errors")')).toBeVisible();
    });

    test('Mail tab loads without PHP errors', async ({ page }) => {
        await page.goto(BASE + '/admin/logs.php?tab=mail');
        await assertNoPhpErrors(page, 'logs mail tab');
        await expect(page.locator('a.nav-link.active:has-text("Mail")')).toBeVisible();
    });

    test('Activity tab has action and user filter selects', async ({ page }) => {
        await page.goto(BASE + '/admin/logs.php?tab=activity');
        await expect(page.locator('select[name="action"]')).toBeVisible();
        await expect(page.locator('select[name="user"]')).toBeVisible();
    });

    test('Errors tab has level filter', async ({ page }) => {
        await page.goto(BASE + '/admin/logs.php?tab=error');
        await expect(page.locator('select[name="level"]')).toBeVisible();
    });

    test('Mail tab has status filter', async ({ page }) => {
        await page.goto(BASE + '/admin/logs.php?tab=mail');
        await expect(page.locator('select[name="status"]')).toBeVisible();
    });

    test('invalid tab param falls back to Activity', async ({ page }) => {
        await page.goto(BASE + '/admin/logs.php?tab=bogus');
        await assertNoPhpErrors(page, 'logs bad tab');
        await expect(page.locator('a.nav-link.active:has-text("Activity")')).toBeVisible();
    });

    test('non-admin is denied', async ({ page, context }) => {
        await context.clearCookies();
        await page.goto(BASE + '/admin/logs.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('login.php');
    });
});

// ── ERROR LOG PAGE (app_log.php) ──────────────────────────────────────────────

test.describe('Error log page', () => {
    test.use({ storageState: AUTH.admin });

    test('loads with "Error Log" heading', async ({ page }) => {
        // No assertNoPhpErrors — this page intentionally displays error log entries
        // whose message text can contain "Uncaught", "Warning:", etc.
        await page.goto(BASE + '/admin/app_log.php');
        await expect(page.locator('h4')).toContainText('Error Log');
    });

    test('has level and channel filter selects', async ({ page }) => {
        await page.goto(BASE + '/admin/app_log.php');
        await expect(page.locator('select[name="level"]')).toBeVisible();
        await expect(page.locator('select[name="channel"]')).toBeVisible();
    });

    test('has from and to date inputs', async ({ page }) => {
        await page.goto(BASE + '/admin/app_log.php');
        await expect(page.locator('input[name="from"]')).toBeVisible();
        await expect(page.locator('input[name="to"]')).toBeVisible();
    });

    test('filtering by level returns only that level', async ({ page }) => {
        // No assertNoPhpErrors — error log entries may contain "Uncaught" etc.
        await page.goto(BASE + '/admin/app_log.php?level=error');
        const rows = await page.locator('tbody tr').count();
        if (rows === 0) return;
        // Level badge is in the 2nd column; all visible rows should show "error".
        const otherLevel = page.locator('tbody td:nth-child(2)').filter({ hasNotText: 'error' });
        expect(await otherLevel.count()).toBe(0);
    });

    test('non-admin is denied', async ({ page, context }) => {
        await context.clearCookies();
        await page.goto(BASE + '/admin/app_log.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('login.php');
    });
});
