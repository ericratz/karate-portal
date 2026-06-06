// @ts-check
// Tests for instructor/belt_test_edit.php:
// syncBeltAwarded() JavaScript, form validation, back button label.
const { test, expect } = require('@playwright/test');
const { login, logout, visit, assertNoPhpErrors, BASE } = require('./helpers');

const { INST_USER, INST_PASS, ADMIN_USER, ADMIN_PASS } = require('./credentials');

test.describe('Belt Test Edit', () => {

    test.beforeEach(async ({ page }) => {
        await login(page, INST_USER, INST_PASS);
        await page.goto(BASE + '/instructor/belt_test_edit.php');
    });

    test('page loads without errors', async ({ page }) => {
        await assertNoPhpErrors(page, 'belt_test_edit new');
    });

    test('Belt Awarded checkbox starts disabled (default result is Pending)', async ({ page }) => {
        const cb = page.locator('#beltAwarded');
        await expect(cb).toBeDisabled();
    });

    test('Belt Awarded checkbox is unchecked by default', async ({ page }) => {
        const cb = page.locator('#beltAwarded');
        expect(await cb.isChecked()).toBe(false);
    });

    test('selecting Pass result enables Belt Awarded checkbox', async ({ page }) => {
        await page.selectOption('#evalSelect', 'pass');
        await expect(page.locator('#beltAwarded')).toBeEnabled();
    });

    test('selecting Fail result disables Belt Awarded checkbox', async ({ page }) => {
        await page.selectOption('#evalSelect', 'pass'); // enable first
        await page.selectOption('#evalSelect', 'fail');
        await expect(page.locator('#beltAwarded')).toBeDisabled();
    });

    test('changing result from Pass to Pending disables Belt Awarded', async ({ page }) => {
        await page.selectOption('#evalSelect', 'pass');
        await expect(page.locator('#beltAwarded')).toBeEnabled();
        await page.selectOption('#evalSelect', 'pending');
        await expect(page.locator('#beltAwarded')).toBeDisabled();
    });

    test('selecting Pass then checking Belt Awarded works', async ({ page }) => {
        await page.selectOption('#evalSelect', 'pass');
        await page.locator('#beltAwarded').check();
        expect(await page.locator('#beltAwarded').isChecked()).toBe(true);
    });

    test('switching from Pass to Fail unchecks Belt Awarded', async ({ page }) => {
        await page.selectOption('#evalSelect', 'pass');
        await page.locator('#beltAwarded').check();
        await page.selectOption('#evalSelect', 'fail');
        // syncBeltAwarded unchecks when disabling
        expect(await page.locator('#beltAwarded').isChecked()).toBe(false);
    });

    test('Fee Paid checkbox is enabled by default', async ({ page }) => {
        await expect(page.locator('#feePaid')).toBeEnabled();
    });

    test('evaluation select has Pending, Pass, Fail options', async ({ page }) => {
        const opts = await page.locator('#evalSelect option').allTextContents();
        const vals = opts.map(o => o.trim().toLowerCase());
        expect(vals.some(v => v.includes('pending'))).toBe(true);
        expect(vals.some(v => v.includes('pass'))).toBe(true);
        expect(vals.some(v => v.includes('fail'))).toBe(true);
    });

    test('student select is visible and has options', async ({ page }) => {
        const sel = page.locator('select[name="student_id"]');
        await expect(sel).toBeVisible();
        const opts = await sel.locator('option').count();
        expect(opts).toBeGreaterThan(1); // at least the blank + one student
    });

    test('rank select (Testing For) is required', async ({ page }) => {
        const sel = page.locator('select[name="rank_id"]');
        const req = await sel.getAttribute('required');
        expect(req).not.toBeNull();
    });

    test('test date defaults to today (or server-timezone equivalent)', async ({ page }) => {
        const val = await page.inputValue('input[name="test_date"]');
        expect(val).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        // Accept ±1 day to handle UTC vs Mountain Time crossover
        const diff = Math.abs(Date.now() - new Date(val + 'T12:00:00').getTime());
        expect(diff).toBeLessThan(2 * 24 * 60 * 60 * 1000);
    });

    test('back button links to belt_tests_all.php when no ref_pid', async ({ page }) => {
        const href = await page.locator('a:has-text("← All Belt Tests")').getAttribute('href');
        expect(href).toContain('belt_tests_all.php');
    });

    test('workflow alert box is visible', async ({ page }) => {
        await expect(page.locator('.alert-light')).toContainText('Workflow');
    });

    test('submitting without student and rank shows validation error', async ({ page }) => {
        // Remove required from student select so form can submit without it
        await page.evaluate(() => {
            const sel = document.querySelector('select[name="student_id"]');
            if (sel) sel.removeAttribute('required');
            const rank = document.querySelector('select[name="rank_id"]');
            if (rank) rank.removeAttribute('required');
            const date = document.querySelector('input[name="test_date"]');
            if (date) date.removeAttribute('required');
        });
        await page.click('button[type="submit"]');
        await page.waitForLoadState('domcontentloaded');
        // Server should return an error (missing required fields)
        await expect(page.locator('.alert-danger')).toBeVisible();
    });
});

// ── EDIT EXISTING TEST (admin only — tests Edit mode UI) ──────────────────────

test.describe('Belt Test Edit — existing record', () => {

    test('editing a belt test pre-fills the form correctly', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        // Navigate to the first belt test in the list to get its ID
        await page.goto(BASE + '/instructor/belt_tests_all.php');
        await page.click('#editToggle');
        const editLink = page.locator('a:has-text("Edit")').first();
        if (await editLink.count() === 0) return;
        await editLink.click();
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'belt_test_edit existing');
        // Form should be pre-filled with date and rank
        const date = await page.inputValue('input[name="test_date"]');
        expect(date).toMatch(/\d{4}-\d{2}-\d{2}/);
    });

    test('edit page shows Save Changes button (not Record Test)', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/instructor/belt_tests_all.php');
        await page.click('#editToggle');
        const editLink = page.locator('a:has-text("Edit")').first();
        if (await editLink.count() === 0) return;
        await editLink.click();
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('button:has-text("Save Changes")')).toBeVisible();
    });

    test('edit page shows Delete button', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/instructor/belt_tests_all.php');
        await page.click('#editToggle');
        const editLink = page.locator('a:has-text("Edit")').first();
        if (await editLink.count() === 0) return;
        await editLink.click();
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('button:has-text("Delete")')).toBeVisible();
    });
});
