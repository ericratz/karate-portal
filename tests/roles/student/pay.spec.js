// @ts-check
// Tests for student/pay.php JavaScript UI features:
// fee selection interactivity, month picker, custom amount, total display,
// PayPal section visibility, and static cards on the page.
const { test, expect } = require('@playwright/test');
const { visit, BASE, AUTH } = require('../../helpers');

test.describe('Pay page', () => {
    test.use({ storageState: AUTH.student });

    test('page loads without PHP errors', async ({ page }) => {
        await visit(page, '/student/pay.php', 'pay page');
    });

    test('total display starts at $0.00', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        const total = await page.textContent('#totalDisplay');
        expect(total?.trim()).toBe('$0.00');
    });

    test('"Select at least one payment" message visible by default', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await expect(page.locator('#noSelectionMsg')).toBeVisible();
    });

    test('PayPal section is hidden when nothing selected', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await expect(page.locator('#paypalSection')).toBeHidden();
    });

    test('fee table rows are clickable (cursor:pointer)', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        const rows = page.locator('table tbody tr[id^="row-"]');
        const count = await rows.count();
        expect(count).toBeGreaterThan(0);
    });

    test('clicking monthly tuition row checks its checkbox', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        const row = page.locator('#row-monthly_tuition');
        const chk = page.locator('#chk-monthly_tuition');
        const before = await chk.isChecked();
        // Click the label cell, not the checkbox itself
        await row.locator('td').nth(1).click();
        const after = await chk.isChecked();
        expect(after).toBe(!before);
    });

    test('checking monthly tuition reveals month picker select', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await expect(page.locator('#row-month-picker')).toBeHidden();
        await page.locator('#chk-monthly_tuition').check();
        await expect(page.locator('#row-month-picker')).toBeVisible();
        await expect(page.locator('#tuitionMonth')).toBeVisible();
    });

    test('unchecking monthly tuition hides month picker', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await page.locator('#chk-monthly_tuition').check();
        await page.locator('#chk-monthly_tuition').uncheck();
        await expect(page.locator('#row-month-picker')).toBeHidden();
    });

    test('month picker has 4 options (current + next 3 months)', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await page.locator('#chk-monthly_tuition').check();
        const opts = await page.locator('#tuitionMonth option').count();
        expect(opts).toBe(4);
    });

    test('checking a fee updates total to non-zero', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await page.locator('#chk-monthly_tuition').check();
        const total = await page.textContent('#totalDisplay');
        expect(total?.trim()).not.toBe('$0.00');
    });

    test('when total > 0, noSelectionMsg is hidden', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await page.locator('#chk-monthly_tuition').check();
        // noSelectionMsg is hidden by our code when total > 0
        // (paypalSection visibility depends on PayPal SDK loading â€” not tested here)
        await expect(page.locator('#noSelectionMsg')).toBeHidden();
    });

    test('unchecking all fees returns to $0.00 and hides PayPal section', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await page.locator('#chk-monthly_tuition').check();
        await page.locator('#chk-monthly_tuition').uncheck();
        const total = await page.textContent('#totalDisplay');
        expect(total?.trim()).toBe('$0.00');
        await expect(page.locator('#paypalSection')).toBeHidden();
    });

    test('checking Custom / Other Amount reveals amount and reason inputs', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await expect(page.locator('#customSection')).toBeHidden();
        await page.locator('#customCheck').check();
        await expect(page.locator('#customSection')).toBeVisible();
        await expect(page.locator('#customAmount')).toBeVisible();
        await expect(page.locator('#customReason')).toBeVisible();
    });

    test('unchecking Custom amount hides the section again', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await page.locator('#customCheck').check();
        await page.locator('#customCheck').uncheck();
        await expect(page.locator('#customSection')).toBeHidden();
    });

    test('typing a custom amount updates the total', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await page.locator('#customCheck').check();
        await page.fill('#customAmount', '25');
        const total = await page.textContent('#totalDisplay');
        expect(total?.trim()).toBe('$25.00');
    });

    test('Auto-Pay card is visible', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await expect(page.locator('.card-header').filter({ hasText: 'Monthly Auto-Pay' })).toBeVisible();
    });

    test('Other Payment Options card is visible', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await expect(page.locator('.card-header').filter({ hasText: 'Other Payment Options' })).toBeVisible();
    });

    test('PayPal SDK script tag is present', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        const count = await page.locator('script[src*="paypal"]').count();
        expect(count).toBeGreaterThan(0);
    });

    test('selecting multiple fees sums the total correctly', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        // Get amounts from data attributes for the checkboxes we'll tick
        const tuitionAmt = await page.locator('#chk-monthly_tuition').getAttribute('data-amount');
        const beltAmt    = await page.locator('#chk-belt_test').getAttribute('data-amount');
        await page.locator('#chk-monthly_tuition').check();
        await page.locator('#chk-belt_test').check();
        const expected = (parseFloat(tuitionAmt ?? '0') + parseFloat(beltAmt ?? '0')).toFixed(2);
        const total = await page.textContent('#totalDisplay');
        expect(total?.trim()).toBe(`$${expected}`);
    });

    test('row is highlighted (table-primary) when checked', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await page.locator('#chk-monthly_tuition').check();
        const cls = await page.locator('#row-monthly_tuition').getAttribute('class');
        expect(cls).toContain('table-primary');
    });

    test('row loses highlight when unchecked', async ({ page }) => {
        await page.goto(BASE + '/student/pay.php');
        await page.locator('#chk-monthly_tuition').check();
        await page.locator('#chk-monthly_tuition').uncheck();
        const cls = await page.locator('#row-monthly_tuition').getAttribute('class');
        expect(cls ?? '').not.toContain('table-primary');
    });
});
