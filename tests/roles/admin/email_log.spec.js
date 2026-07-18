// @ts-check
// Tests for the legacy admin/email_log.php URL — now a redirect stub into the
// React SPA combined-logs Mail tab (app.php#/admin/logs?tab=mail).
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

test.describe('Email log', () => {
    test.use({ storageState: AUTH.admin });

    test('email_log.php redirects into the SPA mail tab', async ({ page }) => {
        await page.goto(BASE + '/admin/email_log.php');
        await assertNoPhpErrors(page, 'email log');
        expect(page.url()).toContain('app.php#/admin/logs?tab=mail');
        await expect(page.locator('a.nav-link.active:has-text("Mail")')).toBeVisible();
    });

    test('filter bar has timeframe, status, and type selects', async ({ page }) => {
        await page.goto(BASE + '/admin/email_log.php');
        await expect(page.locator('select[name="timeframe"]')).toBeVisible();
        await expect(page.locator('select[name="status"]')).toBeVisible();
        await expect(page.locator('select[name="type"]')).toBeVisible();
    });

    test('status select has Sent and Failed options', async ({ page }) => {
        await page.goto(BASE + '/admin/email_log.php');
        await expect(page.locator('select[name="status"]')).toBeVisible();
        const opts = await page.locator('select[name="status"] option').allTextContents();
        const lower = opts.map(o => o.trim().toLowerCase());
        expect(lower).toContain('sent');
        expect(lower).toContain('failed');
    });

    test('entry count is shown in card header', async ({ page }) => {
        await page.goto(BASE + '/admin/email_log.php');
        // The results card header shows "N entries" once the tab has loaded
        await expect(page.locator('.card-header').first()).toContainText(/\d+ entr/);
    });

    test('status filter carries over and resets to unfiltered view', async ({ page }) => {
        // The stub forwards ?status=sent into the SPA route
        await page.goto(BASE + '/admin/email_log.php?status=sent');
        const statusSelect = page.locator('select[name="status"]');
        await expect(statusSelect).toHaveValue('sent');
        // Selecting "All" clears the filter from the route query
        await statusSelect.selectOption('');
        await expect(statusSelect).toHaveValue('');
        expect(page.url()).not.toContain('status=');
    });

    test('non-admin is denied access', async ({ page, context }) => {
        await context.clearCookies();
        await page.goto(BASE + '/admin/email_log.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('login.php');
    });
});
