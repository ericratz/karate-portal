// @ts-check
// Rank history tests.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

test.describe.configure({ mode: 'serial' });
test.use({ storageState: AUTH.admin });

test('rank history add form is visible and functional', async ({ page }) => {
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    await assertNoPhpErrors(page, 'student edit');
    await page.click('button:has-text("+ Record Rank")');
    await expect(page.locator('#rank-add-box select[name="new_rank_id"]')).toBeVisible();
    await expect(page.locator('#rank-add-box input[name="new_rank_date"]')).toBeVisible();
});

test('add rank entry persists and shows in table', async ({ page }) => {
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    await page.click('button:has-text("+ Record Rank")');
    await page.locator('#rank-add-box select[name="new_rank_id"]').selectOption({ index: 1 });
    await page.fill('#rank-add-box input[name="new_rank_date"]', '2020-01-01');
    await page.locator('#rank-add-box button:has-text("Save")').click();
    await assertNoPhpErrors(page, 'add rank');
    // A rank now exists, so the Edit toggle must render (SPA refetch)
    const rankEditBtn = page.locator('#rankEditToggle');
    await expect(rankEditBtn).toBeVisible();
    await rankEditBtn.click();
    expect(await page.locator('select[name^="rank_updates"]').count()).toBeGreaterThanOrEqual(1);
});

test('rank history table shows all entries and has selects with options', async ({ page }) => {
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    // The rank added by the previous (serial) test guarantees the toggle exists
    const rankEditBtn = page.locator('#rankEditToggle');
    await expect(rankEditBtn).toBeVisible();
    await rankEditBtn.click();
    const rankSelects = page.locator('select[name^="rank_updates"]');
    expect(await rankSelects.count()).toBeGreaterThan(0);
    expect(await rankSelects.first().locator('option').count()).toBeGreaterThan(0);
});
