// @ts-check
// Admin payments CRUD and filter tests.
const { test, expect } = require('@playwright/test');
const { visit, assertNoPhpErrors, BASE, AUTH } = require('./helpers');

test.describe.configure({ mode: 'serial' });
test.use({ storageState: AUTH.admin });
const TS = Date.now();

test('payments page loads and default filter is empty', async ({ page }) => {
    await visit(page, '/admin/payments.php', 'payments');
    expect(await page.inputValue('input[name="from"]')).toBe('');
    expect(await page.inputValue('input[name="to"]')).toBe('');
});

test('record manual payment and it appears in list', async ({ page }) => {
    await page.goto(BASE + '/admin/payments.php?action=add');
    await page.selectOption('select[name="student_id"]', { index: 1 });
    await page.fill('input[name="amount"]', '30');
    await page.selectOption('select[name="payment_type"]', 'monthly_tuition');
    await page.selectOption('select[name="payment_method"]', 'cash');
    await page.fill('input[name="notes"]', `Playwright payment ${TS}`);
    await page.click('button:has-text("Save Payment")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-success').first()).toContainText('Payment recorded');
    await expect(page.locator('body')).toContainText(`Playwright payment ${TS}`);
});

test('filter by method and by type show only matching rows', async ({ page }) => {
    // Filter by cash
    await page.goto(BASE + '/admin/payments.php');
    await page.selectOption('select[name="method"]', 'cash');
    await page.click('button:has-text("Filter")');
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'payment filter cash');
    const methods = await page.locator('tbody td:nth-child(6)').allTextContents();
    methods.forEach(m => expect(m.toLowerCase()).toBe('cash'));
    // Filter by registration type
    await page.selectOption('select[name="type"]', 'registration');
    await page.selectOption('select[name="method"]', '');
    await page.click('button:has-text("Filter")');
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'payment filter registration');
    const types = await page.locator('tbody td:nth-child(4)').allTextContents();
    types.forEach(t => expect(t.toLowerCase()).toContain('registration'));
});

test('delete payment removes it from list', async ({ page }) => {
    await page.goto(BASE + '/admin/payments.php');
    await page.click('#editToggle');
    const row = page.locator('tr').filter({ hasText: `Playwright payment ${TS}` });
    page.once('dialog', d => d.accept());
    await row.locator('.btn-outline-danger').click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('body')).not.toContainText(`Playwright payment ${TS}`);
});

test.describe('student view', () => {
    test.use({ storageState: AUTH.student });
    test('student payment history shows Month column and year filter', async ({ page }) => {
        await page.goto(BASE + '/student/payment_history.php');
        await expect(page.locator('th:has-text("Month")')).toBeVisible();
        const yearBtn = page.locator('a.btn[href*="?year="]').first();
        if (await yearBtn.isVisible()) {
            const year = (await yearBtn.textContent())?.trim();
            await yearBtn.click();
            await page.waitForLoadState('domcontentloaded');
            const dates = await page.locator('tbody td:nth-child(2)').allTextContents();
            dates.forEach(d => expect(d).toContain(year));
        }
    });
});
