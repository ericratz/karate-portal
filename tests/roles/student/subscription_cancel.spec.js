// @ts-check
// Tests for student/subscription_cancel.php.
// The endpoint cancels a student's active PayPal subscription. When there is no
// active subscription it still redirects to profile_edit.php?autopay=cancelled.
const { test, expect } = require('@playwright/test');
const { BASE, AUTH } = require('../../helpers');

// ── UNAUTHENTICATED ───────────────────────────────────────────────────────────

test('subscription_cancel.php redirects unauthenticated users to login', async ({ page }) => {
    await page.goto(BASE + '/student/subscription_cancel.php');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('login.php');
});

// ── AUTHENTICATED — NO ACTIVE SUBSCRIPTION ───────────────────────────────────

test.describe('subscription_cancel — student with no active subscription', () => {
    test.use({ storageState: AUTH.student });

    test('GET subscription_cancel.php redirects to profile_edit?autopay=cancelled', async ({ page }) => {
        // verify_csrf() skips on GET requests; endpoint proceeds, finds no active
        // subscription for jsmith, and redirects to profile_edit.php?autopay=cancelled.
        await page.goto(BASE + '/student/subscription_cancel.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('autopay=cancelled');
        expect(page.url()).toContain('profile_edit.php');
    });
});

// ── ACCESS CONTROL — WRONG ROLE ───────────────────────────────────────────────

test.describe('subscription_cancel — admin role cannot reach student endpoint', () => {
    test.use({ storageState: AUTH.admin });

    test('admin GET subscription_cancel.php redirects away (not student dashboard)', async ({ page }) => {
        await page.goto(BASE + '/student/subscription_cancel.php');
        await page.waitForLoadState('domcontentloaded');
        // Admin role has no student row, so the PHP finds no student_id and redirects to index.php
        expect(page.url()).not.toContain('subscription_cancel.php');
    });
});
