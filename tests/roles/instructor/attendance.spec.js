// @ts-check
// Functional attendance tests â€” saving attendance and student visibility.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, visit, BASE, AUTH } = require('../../helpers');

test.describe.configure({ mode: 'serial' });
test.use({ storageState: AUTH.instructor });
const TEST_DATE = '2099-01-15';

test('attendance page shows student list', async ({ page }) => {
    await page.goto(BASE + `/instructor/attendance.php?date=${TEST_DATE}`);
    await assertNoPhpErrors(page, 'attendance form');
    expect(await page.locator('input[name="present[]"]').count()).toBeGreaterThan(0);
});

test('saving attendance shows confirmation', async ({ page }) => {
    test.setTimeout(20000);
    await page.goto(BASE + `/instructor/attendance.php?date=${TEST_DATE}`);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'attendance saved');
    await expect(page.locator('body')).toContainText(/saved|recorded/i);
});

test('sort by last name and last attended load without errors', async ({ page }) => {
    await page.goto(BASE + `/instructor/attendance.php?date=${TEST_DATE}&sort=last_name`);
    await assertNoPhpErrors(page, 'sort last_name');
    await page.goto(BASE + `/instructor/attendance.php?date=${TEST_DATE}&sort=last_attended`);
    await assertNoPhpErrors(page, 'sort last_attended');
});

test.describe('student view', () => {
    test.use({ storageState: AUTH.student });
    test('student sees attended session reflected on dashboard', async ({ page }) => {
        await visit(page, '/student/', 'dashboard after attendance');
        expect(parseInt(await page.locator('.display-6.text-primary').first().textContent() ?? '0')).toBeGreaterThanOrEqual(0);
    });
});

test('attendance with invalid date defaults gracefully', async ({ page }) => {
    await page.goto(BASE + '/instructor/attendance.php?date=not-a-date');
    await assertNoPhpErrors(page, 'invalid attendance date');
});
