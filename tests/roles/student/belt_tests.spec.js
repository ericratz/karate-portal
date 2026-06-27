// @ts-check
// Tests for student/belt_tests.php â€” the student-facing full belt test history view.
// Covers summary cards, table columns, pass/fail/pending badge rendering, and access control.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

test.describe('Student belt test history page', () => {
    test.use({ storageState: AUTH.student });

    // â”€â”€ PAGE LOADS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    test('belt_tests.php loads without PHP errors', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        await assertNoPhpErrors(page, 'student belt tests');
    });

    test('page heading includes Belt Test History', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        await expect(page.locator('h4').first()).toContainText('Belt Test History');
    });

    // â”€â”€ SUMMARY CARDS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    test('summary shows Total Tests card', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        await expect(page.locator('body')).toContainText('Total Tests');
    });

    test('summary shows Passed card', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        await expect(page.locator('body')).toContainText('Passed');
    });

    test('summary shows Pending card', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        await expect(page.locator('body')).toContainText('Pending');
    });

    // â”€â”€ ALL BELT TESTS CARD â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    test('All Belt Tests card is visible', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        await expect(page.locator('.card-header').filter({ hasText: 'All Belt Tests' })).toBeVisible();
    });

    test('table has correct column headers when tests exist', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        const hasRows = await page.locator('tbody tr').count() > 0;
        if (!hasRows) return; // no belt tests in DB for this student
        await expect(page.locator('thead')).toContainText('Date');
        await expect(page.locator('thead')).toContainText('Testing For');
        await expect(page.locator('thead')).toContainText('Score');
        await expect(page.locator('thead')).toContainText('Fee');
        await expect(page.locator('thead')).toContainText('Test Passed');
    });

    // â”€â”€ PASS / FAIL / PENDING RENDERING â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // These tests are defensive: they skip if the test DB has no matching records.

    test('passing belt test shows green Passed badge in Test Passed column', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        // Look for any row whose last <td> has a bg-success badge saying "Passed"
        const passCell = page.locator('tbody td:last-child .badge.bg-success:has-text("Passed")');
        if (await passCell.count() === 0) return; // no passing tests for this student
        await expect(passCell.first()).toBeVisible();
    });

    test('failing belt test shows red Failed badge in Test Passed column', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        const failCell = page.locator('tbody td:last-child .badge.bg-danger:has-text("Failed")');
        if (await failCell.count() === 0) return; // no failing tests
        await expect(failCell.first()).toBeVisible();
    });

    test('pending belt test shows - dash in Test Passed column', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        // Pending result = neither pass nor fail â†’ last <td> shows "â€”" in a text-muted span
        const pendingCell = page.locator('tbody td:last-child span.text-muted');
        if (await pendingCell.count() === 0) return; // no pending tests
        await expect(pendingCell.first()).toContainText('â€”');
    });

    test('score badge shows Pending when score is null', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        // A null score renders as <span class="badge bg-secondary">Pending</span> in the Score column
        const pendingBadge = page.locator('tbody .badge.bg-secondary:has-text("Pending")');
        if (await pendingBadge.count() === 0) return;
        await expect(pendingBadge.first()).toBeVisible();
    });

    test('score badge shows percentage when score is set', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        // A numeric score renders as "NN%" in the Score column
        const scoreBadge = page.locator('tbody .badge').filter({ hasText: /%$/ });
        if (await scoreBadge.count() === 0) return;
        await expect(scoreBadge.first()).toBeVisible();
        expect(await scoreBadge.first().textContent()).toMatch(/\d+%/);
    });

    // â”€â”€ FEE DISPLAY â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    test('fee column shows Paid or Unpaid text when tests exist', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        const hasRows = await page.locator('tbody tr').count() > 0;
        if (!hasRows) return;
        // Fee column should contain either "Paid" or "Unpaid" text
        const feePaid   = page.locator('tbody .text-success:has-text("Paid")');
        const feeUnpaid = page.locator('tbody .text-danger:has-text("Unpaid")');
        const hasFeeDisplay = (await feePaid.count()) > 0 || (await feeUnpaid.count()) > 0;
        expect(hasFeeDisplay).toBe(true);
    });

    // â”€â”€ ACCESS CONTROL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    test('belt_tests.php requires login - redirects unauthenticated users', async ({ page }) => {
        await page.context().clearCookies();
        await page.goto(BASE + '/student/belt_tests.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('login.php');
    });
});
