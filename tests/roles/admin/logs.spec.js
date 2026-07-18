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

// ── LEGACY ERROR LOG URL (app_log.php → SPA error tab) ────────────────────────

test.describe('Error log page', () => {
    test.use({ storageState: AUTH.admin });

    test('app_log.php redirects into the SPA error tab', async ({ page }) => {
        // No assertNoPhpErrors — the error tab intentionally displays error log
        // entries whose message text can contain "Uncaught", "Warning:", etc.
        await page.goto(BASE + '/admin/app_log.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('app.php#/admin/logs?tab=error');
        await expect(page.locator('a.nav-link.active:has-text("Errors")')).toBeVisible();
    });

    test('error tab has timeframe, level, and channel filter selects', async ({ page }) => {
        await page.goto(BASE + '/admin/app_log.php');
        await expect(page.locator('select[name="timeframe"]')).toBeVisible();
        await expect(page.locator('select[name="level"]')).toBeVisible();
        await expect(page.locator('select[name="channel"]')).toBeVisible();
    });

    test('filtering by level returns only that level', async ({ page }) => {
        // No assertNoPhpErrors — error log entries may contain "Uncaught" etc.
        // The seed's error_log rows are all level=warning, so filter by that.
        // Pass every param in the URL (all-time so it doesn't depend on when
        // they were logged) — a single fetch, not three sequential ones, which
        // keeps the assertion off the flaky path under parallel worker load.
        await page.goto(BASE + '/admin/app.php#/admin/logs?tab=error&timeframe=all&level=warning');
        await expect(page.locator('select[name="level"]')).toHaveValue('warning');
        await expect(page.locator('tbody tr').first()).toBeVisible(); // the test DB has warning-level entries
        const rows = await page.locator('tbody tr').count();
        expect(rows).toBeGreaterThan(0);
        // Level badge is in the 2nd column; all visible rows should show "warning".
        const otherLevel = page.locator('tbody td:nth-child(2)').filter({ hasNotText: 'warning' });
        expect(await otherLevel.count()).toBe(0);
    });

    test('non-admin is denied', async ({ page, context }) => {
        await context.clearCookies();
        await page.goto(BASE + '/admin/app_log.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('login.php');
    });
});
