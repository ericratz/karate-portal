// @ts-check
// Admin payments CRUD and filter tests.
const { test, expect } = require('@playwright/test');
const { visit, assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

test.describe.configure({ mode: 'serial' });
test.use({ storageState: AUTH.admin });
const TS = Date.now();

test('payments page loads with Year filter defaulting to All Years', async ({ page }) => {
    await visit(page, '/admin/payments.php', 'payments');
    const yearSelect = page.locator('select[name="year"]');
    await expect(yearSelect).toBeVisible();
    expect(await yearSelect.inputValue()).toBe('');
    const currentYear = new Date().getFullYear().toString();
    await expect(yearSelect.locator(`option[value="${currentYear}"]`)).toHaveCount(1);
});

test('record manual payment and it appears in list', async ({ page }) => {
    await page.goto(BASE + '/admin/payments.php?action=add');
    // Student selector is now type-to-filter
    await page.fill('#payGrantStudentFilter', 'a');
    await page.waitForTimeout(150);
    await page.locator('.pay-grant-stu-btn:visible').first().click();
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
    // Filter by cash — the method select live-filters via HTMX on change
    await page.goto(BASE + '/admin/payments.php');
    await Promise.all([
        page.waitForResponse(r => r.url().includes('/admin/payments.php') && r.url().includes('method=cash')),
        page.selectOption('select[name="method"]', 'cash'),
    ]);
    await assertNoPhpErrors(page, 'payment filter cash');
    // Poll — the SPA re-renders just after the filtered response resolves
    await expect.poll(async () => {
        const methods = await page.locator('tbody td:nth-child(5)').allTextContents();
        return methods.every(m => m.toLowerCase() === 'cash');
    }).toBe(true);
    // Filter by registration type — navigate directly (stub forwards ?type=)
    await page.goto(BASE + '/admin/payments.php?type=registration');
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'payment filter registration');
    // SPA page — wait for the filtered fetch to render before reading cells
    await expect(page.locator('select[name="type"]')).toHaveValue('registration');
    await expect(page.locator('tbody tr, p:has-text("No payments match")').first()).toBeVisible();
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
        // SPA year filter renders as buttons (was <a href="?year="> links)
        const yearBtn = page.locator('.card-header button.btn').filter({ hasText: /^\d{4}$/ }).first();
        if (await yearBtn.isVisible()) {
            const year = (await yearBtn.textContent())?.trim() ?? '';
            await yearBtn.click();
            // Filtered rows re-render client-side; every date cell shows that year.
            // Scope to the Payments card header — the footer adds its own .card-header.
            await expect(page.locator('.card-header').filter({ hasText: 'Payments' })).toContainText(year);
            const dates = await page.locator('tbody td:nth-child(2)').allTextContents();
            dates.forEach(d => expect(d).toContain(year));
        }
    });
});
