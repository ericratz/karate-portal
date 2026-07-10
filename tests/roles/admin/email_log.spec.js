// @ts-check
// Tests for admin/email_log.php
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

test.describe('Email log', () => {
    test.use({ storageState: AUTH.admin });

    test('page loads without PHP errors', async ({ page }) => {
        await page.goto(BASE + '/admin/email_log.php');
        await assertNoPhpErrors(page, 'email log');
        await expect(page.locator('h4')).toContainText('Mail Log');
    });

    test('filter bar has status, type, from, to inputs', async ({ page }) => {
        await page.goto(BASE + '/admin/email_log.php');
        await expect(page.locator('select[name="status"]')).toBeVisible();
        await expect(page.locator('select[name="type"]')).toBeVisible();
        await expect(page.locator('input[name="from"]')).toBeVisible();
        await expect(page.locator('input[name="to"]')).toBeVisible();
    });

    test('status select has Sent and Failed options', async ({ page }) => {
        await page.goto(BASE + '/admin/email_log.php');
        const opts = await page.locator('select[name="status"] option').allTextContents();
        const lower = opts.map(o => o.trim().toLowerCase());
        expect(lower).toContain('sent');
        expect(lower).toContain('failed');
    });

    test('entry count is shown in card header', async ({ page }) => {
        await page.goto(BASE + '/admin/email_log.php');
        const header = await page.textContent('.card-header');
        expect(header).toMatch(/\d+ entr/);
    });

    // Deliberately no "filtering by status=sent" test: local mail() always fails
    // here (php.ini has no working sendmail_path/SMTP relay — see README "Cannot
    // Test Locally" — Email delivery), so email_log can never contain a 'sent' row
    // in this environment. The filter's WHERE-clause logic is identical for both
    // values, so the 'failed' case below exercises the same code path for real.
    test('filtering by status=failed returns only failed entries', async ({ page }) => {
        await page.goto(BASE + '/admin/email_log.php?status=failed');
        await assertNoPhpErrors(page, 'email log filter failed');
        // The test DB has real failed-send rows (local mail() always fails) — must exist.
        await expect(page.locator('tbody tr').first()).toBeVisible();
        const sentBadges = await page.locator('tbody .text-success').count();
        expect(sentBadges).toBe(0);
    });

    test('clear filter link resets to unfiltered view', async ({ page }) => {
        await page.goto(BASE + '/admin/email_log.php?status=sent&type=password_reset');
        const clearLink = page.locator('a:has-text("Clear")');
        await expect(clearLink).toBeVisible();
        await clearLink.click();
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('email_log.php');
        expect(page.url()).not.toContain('status=');
    });

    test('non-admin is denied access', async ({ page, context }) => {
        await context.clearCookies();
        await page.goto(BASE + '/admin/email_log.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('login.php');
    });
});
