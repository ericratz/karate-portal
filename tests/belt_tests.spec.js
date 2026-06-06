// @ts-check
// Tests for belt test edit/delete toggle and Show All button.
// NOTE: The instructor dashboard no longer has tabs — belt tests UI lives in belt_tests_all.php
const { test, expect } = require('@playwright/test');
const { login, logout, visit, assertNoPhpErrors, BASE } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const { ADMIN_USER, ADMIN_PASS, INST_USER, INST_PASS } = require('./credentials');
const TS         = Date.now();

// ── DASHBOARD BELT TESTS ──────────────────────────────────────────────────────

test('instructor dashboard shows Recent Belt Tests section', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/');
    await assertNoPhpErrors(page, 'instructor dashboard');
    await expect(page.locator('.card-header').filter({ hasText: 'Recent Belt Tests' })).toBeVisible();
    await logout(page);
});

test('dashboard has link to belt_tests_all.php', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/');
    const link = page.locator('a[href="belt_tests_all.php"]');
    await expect(link).toBeVisible();
    await logout(page);
});

// ── BELT TESTS ALL PAGE — EDIT/DELETE TOGGLE ─────────────────────────────────

test('belt_tests_all.php loads without errors', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await visit(page, '/instructor/belt_tests_all.php', 'all belt tests');
    await logout(page);
});

test('Edit button is visible when belt tests exist', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    const editBtn = page.locator('#editToggle');
    const hasBeltTests = await page.locator('#beltTestsTable tbody tr').count() > 0;
    if (hasBeltTests) {
        await expect(editBtn).toBeVisible();
    }
    await logout(page);
});

test('delete column is hidden by default', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    const hasBeltTests = await page.locator('#beltTestsTable tbody tr').count() > 0;
    if (hasBeltTests) {
        const deleteCols = page.locator('#beltTestsTable .delete-col');
        const first = deleteCols.first();
        await expect(first).toBeHidden();
    }
    await logout(page);
});

test('clicking Edit shows delete column', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    const hasBeltTests = await page.locator('#beltTestsTable tbody tr').count() > 0;
    if (hasBeltTests) {
        await page.click('#editToggle');
        await expect(page.locator('#beltTestsTable .delete-col').first()).toBeVisible();
        // Button should say Done
        await expect(page.locator('#editToggle')).toHaveText('Done');
    }
    await logout(page);
});

test('clicking Done hides delete column again', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    const hasBeltTests = await page.locator('#beltTestsTable tbody tr').count() > 0;
    if (hasBeltTests) {
        await page.click('#editToggle'); // Edit
        await page.click('#editToggle'); // Done
        await expect(page.locator('#beltTestsTable .delete-col').first()).toBeHidden();
        await expect(page.locator('#editToggle')).toHaveText('Edit');
    }
    await logout(page);
});

test('belt_tests_all.php back button goes to instructor dashboard', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    const back = await page.locator('a:has-text("← Back")').getAttribute('href');
    expect(back).toContain('index.php');
    await logout(page);
});

test('belt_tests_all.php edit toggle works', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    const editBtn = page.locator('#editToggle');
    if (await editBtn.isVisible()) {
        await editBtn.click();
        await expect(page.locator('#beltTestsTable .delete-col').first()).toBeVisible();
        await editBtn.click();
        await expect(page.locator('#beltTestsTable .delete-col').first()).toBeHidden();
    }
    await logout(page);
});

test('belt_tests_all.php shows total count in header', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    await assertNoPhpErrors(page, 'belt tests all count');
    const header = await page.locator('.card-header').first().textContent();
    expect(header).toMatch(/\d+\s*test/i);
    await logout(page);
});

test('guest cannot access belt_tests_all.php', async ({ page }) => {
    await login(page, 'test', 'testing');
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    await expect(page.locator('body')).toContainText('Access denied');
    await logout(page);
});

// ── CREATE → VERIFY → DELETE ROUND-TRIP ──────────────────────────────────────

test('admin: create a belt test for a student', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/instructor/belt_test_edit.php');
    const futureDate = new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10);
    await page.selectOption('select[name="student_id"]', { index: 1 });
    await page.fill('input[name="test_date"]', futureDate);
    await page.selectOption('select[name="rank_id"]', { index: 1 });
    await page.fill('input[name="notes"]', `Delete Me ${TS}`);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'create belt test for delete');
    await logout(page);
});

test('created belt test appears in all belt tests list', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    await expect(page.locator('body')).toContainText(`Delete Me ${TS}`);
    await logout(page);
});

test('delete button removes the belt test', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    const editBtn = page.locator('#editToggle');
    if (await editBtn.isVisible()) await editBtn.click();
    const row = page.locator('tr').filter({ hasText: `Delete Me ${TS}` });
    if (await row.isVisible()) {
        page.once('dialog', d => d.accept());
        await row.locator('.btn-outline-danger').click();
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'after delete');
        await expect(page.locator('body')).not.toContainText(`Delete Me ${TS}`);
    }
    await logout(page);
});
