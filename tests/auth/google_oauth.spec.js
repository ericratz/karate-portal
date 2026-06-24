// @ts-check
// Tests for Google OAuth error paths (no real Google tokens involved).
// These endpoints redirect gracefully when the OAuth flow is broken.
const { test, expect } = require('@playwright/test');
const { BASE } = require('../helpers');

test('google-callback.php without ?code redirects to login with google_failed error', async ({ page }) => {
    // PHP detects missing `code` param and redirects before touching Google APIs
    await page.goto(BASE + '/google-callback.php');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('login.php');
    expect(page.url()).toContain('error=google_failed');
});

test('google-register.php without google_pending session redirects to login', async ({ page }) => {
    // No session, so $_SESSION['google_pending'] is empty → redirect to login
    await page.goto(BASE + '/google-register.php');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('login.php');
});
