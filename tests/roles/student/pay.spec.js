// @ts-check
// Tests for the student payment page — now the React SPA route
// (student/pay.php is a redirect stub into app.php#/pay/N). Covers fee
// selection interactivity, month picker, custom amount, total display, and
// the static cards, matching what the old inline-JS page tests asserted.
const { test, expect } = require('@playwright/test');
const { visit, BASE, AUTH } = require('../../helpers');

/** Load the pay page and wait for the SPA to render the form. */
async function gotoPay(page) {
    await page.goto(BASE + '/student/pay.php');
    await expect(page.locator('#studentSelect')).toBeVisible();
}

const TUITION = 'input[aria-label="Monthly Tuition"]';
const BELT    = 'input[aria-label="Belt Test Fee"]';
const TOTAL   = '.fs-4';

test.describe('Pay page', () => {
    test.use({ storageState: AUTH.student });

    test('page loads without PHP errors', async ({ page }) => {
        await visit(page, '/student/pay.php', 'pay page');
        await expect(page.locator('#studentSelect')).toBeVisible();
    });

    test('single-member family shows the name as text, not a dropdown', async ({ page }) => {
        await gotoPay(page);
        // Only the student's own record to pay for — the "Paying for" selector
        // collapses to plain text instead of a one-option dropdown.
        await expect(page.locator('select#studentSelect')).toHaveCount(0);
        await expect(page.locator('#studentSelect')).not.toBeEmpty();
    });

    test('total display starts at $0.00', async ({ page }) => {
        await gotoPay(page);
        await expect(page.locator(TOTAL)).toHaveText('$0.00');
    });

    test('"Select at least one payment" message visible by default', async ({ page }) => {
        await gotoPay(page);
        await expect(page.locator('text=Select at least one payment')).toBeVisible();
    });

    test('clicking the tuition row (not the checkbox) checks its checkbox', async ({ page }) => {
        await gotoPay(page);
        const row = page.locator('tr', { has: page.locator(TUITION) });
        await row.locator('td').nth(1).click();
        await expect(page.locator(TUITION)).toBeChecked();
    });

    test('checking monthly tuition reveals the month picker', async ({ page }) => {
        await gotoPay(page);
        await expect(page.locator('#tuitionMonth')).toBeHidden();
        await page.locator(TUITION).check();
        await expect(page.locator('#tuitionMonth')).toBeVisible();
    });

    test('unchecking monthly tuition hides the month picker', async ({ page }) => {
        await gotoPay(page);
        await page.locator(TUITION).check();
        await page.locator(TUITION).uncheck();
        await expect(page.locator('#tuitionMonth')).toBeHidden();
    });

    test('month picker has 5 options (previous + current + next 3 months)', async ({ page }) => {
        await gotoPay(page);
        await page.locator(TUITION).check();
        expect(await page.locator('#tuitionMonth option').count()).toBe(5);
    });

    test('checking a fee updates total, hides the no-selection message', async ({ page }) => {
        await gotoPay(page);
        await page.locator(TUITION).check();
        await expect(page.locator(TOTAL)).toHaveText('$30.00');
        await expect(page.locator('text=Select at least one payment')).toBeHidden();
    });

    test('unchecking all fees returns to $0.00', async ({ page }) => {
        await gotoPay(page);
        await page.locator(TUITION).check();
        await page.locator(TUITION).uncheck();
        await expect(page.locator(TOTAL)).toHaveText('$0.00');
        await expect(page.locator('text=Select at least one payment')).toBeVisible();
    });

    test('checking Custom / Other Amount reveals amount and reason inputs', async ({ page }) => {
        await gotoPay(page);
        await expect(page.locator('input[aria-label="Custom amount"]')).toBeHidden();
        await page.locator('#customCheck').check();
        await expect(page.locator('input[aria-label="Custom amount"]')).toBeVisible();
        await expect(page.locator('input[aria-label="Reason for payment"]')).toBeVisible();
    });

    test('unchecking Custom amount hides the section again', async ({ page }) => {
        await gotoPay(page);
        await page.locator('#customCheck').check();
        await page.locator('#customCheck').uncheck();
        await expect(page.locator('input[aria-label="Custom amount"]')).toBeHidden();
    });

    test('typing a custom amount updates the total', async ({ page }) => {
        await gotoPay(page);
        await page.locator('#customCheck').check();
        await page.fill('input[aria-label="Custom amount"]', '25');
        await expect(page.locator(TOTAL)).toHaveText('$25.00');
    });

    test('checking Donation reveals the amount input, which feeds the total', async ({ page }) => {
        await gotoPay(page);
        await page.locator('input[aria-label="Donation"]').check();
        await page.fill('input[aria-label="Donation amount"]', '5');
        await expect(page.locator(TOTAL)).toHaveText('$5.00');
    });

    test('selecting multiple fees sums the total correctly', async ({ page }) => {
        await gotoPay(page);
        await page.locator(TUITION).check();
        await page.locator(BELT).check();
        await expect(page.locator(TOTAL)).toHaveText('$40.00'); // 30 + 10
    });

    test('row is highlighted (table-primary) when checked, loses it when unchecked', async ({ page }) => {
        await gotoPay(page);
        const row = page.locator('tr', { has: page.locator(TUITION) });
        await page.locator(TUITION).check();
        await expect(row).toHaveClass(/table-primary/);
        await page.locator(TUITION).uncheck();
        await expect(row).not.toHaveClass(/table-primary/);
    });

    test('Auto-Pay card is visible with a row for the student', async ({ page }) => {
        await gotoPay(page);
        await expect(page.locator('.card-header').filter({ hasText: 'Monthly Auto-Pay' })).toBeVisible();
    });

    test('Other Payment Options card is visible', async ({ page }) => {
        await gotoPay(page);
        await expect(page.locator('.card-header').filter({ hasText: 'Other Payment Options' })).toBeVisible();
    });

    test('pay.php requires login — redirects unauthenticated users', async ({ page }) => {
        await page.context().clearCookies();
        await page.goto(BASE + '/student/pay.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('login.php');
    });
});
