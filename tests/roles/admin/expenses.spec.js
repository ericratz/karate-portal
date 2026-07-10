// @ts-check
// Admin expenses CRUD tests.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

test.describe.configure({ mode: 'serial' });
test.use({ storageState: AUTH.admin });
const TS = Date.now();

test('add expense and it appears in list', async ({ page }) => {
    await page.goto(BASE + '/admin/expenses.php');
    await page.click('button:has-text("+ Record Expense")');
    await page.selectOption('select[name="expense_type"]', 'supplies');
    await page.fill('input[name="amount"]', '5.00');
    await page.fill('input[name="expense_date"]', new Date().toISOString().slice(0,10));
    await page.fill('input[name="description"]', `Toggle test ${TS}`);
    await page.click('button:has-text("Save Expense")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('body')).toContainText(`Toggle test ${TS}`);
});

test('edit toggle enters and exits editing mode', async ({ page }) => {
    await page.goto(BASE + '/admin/expenses.php');
    const editBtn = page.locator('#editToggle');
    if (await editBtn.isVisible()) {
        expect(await page.locator('#expensesTable').getAttribute('class')).not.toContain('editing');
        await editBtn.click();
        await expect(page.locator('#expensesTable')).toHaveClass(/editing/);
        await editBtn.click();
        expect(await page.locator('#expensesTable').getAttribute('class')).not.toContain('editing');
    }
});

test('year filter loads without error', async ({ page }) => {
    await page.goto(BASE + '/admin/expenses.php');
    const yearBtns = page.locator('a.btn[href*="?year="]');
    if (await yearBtns.count() > 1) {
        await yearBtns.nth(1).click();
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'expenses year filter');
    }
});

test('delete expense removes it', async ({ page }) => {
    await page.goto(BASE + '/admin/expenses.php');
    await page.click('#editToggle');
    const row = page.locator('tr').filter({ hasText: `Toggle test ${TS}` });
    page.once('dialog', d => d.accept());
    await row.locator('.btn-outline-danger').click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('body')).not.toContainText(`Toggle test ${TS}`);
});
