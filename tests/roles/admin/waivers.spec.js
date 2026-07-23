// @ts-check
// Functional tests for admin/waivers.php — the Exempt page.
// Covers: page load, grant form fields, grant-round-trip (grant → verify → delete).
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

test.describe.configure({ mode: 'serial' });
test.use({ storageState: AUTH.admin });

const STUDENT_ID = 2; // Sarah Johnson — stable test-DB student

// ── PAGE LOAD ─────────────────────────────────────────────────────────────────

test('admin/waivers.php loads without PHP errors', async ({ page }) => {
    await page.goto(BASE + '/admin/waivers.php');
    await assertNoPhpErrors(page, 'exemptions page');
    await expect(page.locator('h3').first()).toContainText('Exempt');
});

// ── GRANT FORM ────────────────────────────────────────────────────────────────

test('Grant Exemption card header is visible', async ({ page }) => {
    await page.goto(BASE + '/admin/waivers.php');
    await expect(page.locator('.card-header').filter({ hasText: 'Grant Exemption' })).toBeVisible();
});

test('grant form has student type-to-filter, type select, reason textarea, date, and submit button', async ({ page }) => {
    await page.goto(BASE + '/admin/waivers.php');
    await page.waitForLoadState('domcontentloaded');
    // Student selector is now type-to-filter — no raw <select name=”student_id”>
    await expect(page.locator('#grantStudentFilter')).toBeVisible();
    await expect(page.locator('select[name="waiver_type"]')).toBeVisible();
    await expect(page.locator('textarea[name="reason"]')).toBeVisible();
    await expect(page.locator('input[name="granted_date"]')).toBeVisible();
    await expect(page.locator('button[name="grant"]')).toBeVisible();
});

test('waiver_type select contains all expected exemption types', async ({ page }) => {
    await page.goto(BASE + '/admin/waivers.php');
    // SPA page — wait for render before the non-waiting allTextContents()
    await expect(page.locator('select[name="waiver_type"]')).toBeVisible();
    const opts = await page.locator('select[name="waiver_type"] option').allTextContents();
    const lower = opts.map(o => o.trim().toLowerCase());
    expect(lower).toContain('monthly tuition');
    expect(lower).toContain('registration fee');
    expect(lower).toContain('belt test fee');
    // 'all fees' was removed as a selectable option (redundant with "All Types").
    expect(lower).not.toContain('all fees');
});

test('submitting grant form without student shows validation error', async ({ page }) => {
    await page.goto(BASE + '/admin/waivers.php');
    // Hidden input #grantStudentId is empty by default — submitting without selecting a
    // student is blocked client-side via setCustomValidity() on the visible filter box
    // (see waivers.php's grantForm submit handler), so no request is ever sent.
    await page.click('button[name="grant"]');
    const filter = page.locator('#grantStudentFilter');
    await expect(filter).toHaveJSProperty('validity.valid', false);
    const message = await filter.evaluate((el) => /** @type {HTMLInputElement} */ (el).validationMessage);
    expect(message.length).toBeGreaterThan(0);
});

// ── ALL EXEMPTIONS LIST ───────────────────────────────────────────────────────

test('All Exemptions card header is visible', async ({ page }) => {
    await page.goto(BASE + '/admin/waivers.php');
    await expect(page.locator('.card-header').filter({ hasText: 'All Exemptions' })).toBeVisible();
});

test('filter bar has student type-to-filter and type select', async ({ page }) => {
    await page.goto(BASE + '/admin/waivers.php');
    // Both grant form and filter bar now use type-to-filter inputs instead of selects
    await expect(page.locator('#grantStudentFilter')).toBeVisible();
    await expect(page.locator('#filterStudentFilter')).toBeVisible();
    await expect(page.locator('select[name="type"]')).toBeVisible();
});

// ── GRANT → VERIFY → DELETE ROUND-TRIP ───────────────────────────────────────

test('granting an exemption shows Exemption granted success message', async ({ page }) => {
    await page.goto(BASE + '/admin/waivers.php');
    // Use the grant form's type-to-filter to select Sarah Johnson (id=2)
    await page.fill('#grantStudentFilter', 'Sarah');
    await page.waitForTimeout(150);
    await page.locator('.grant-stu-btn:visible').first().click();
    await page.selectOption('select[name="waiver_type"]', 'belt_test');
    await page.fill('textarea[name="reason"]', 'Test exemption via Playwright');
    await page.click('button[name="grant"]');
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'grant exemption');
    await expect(page.locator('.alert-success').first()).toContainText('Exemption granted');
});

test('granted exemption appears in All Exemptions list', async ({ page }) => {
    await page.goto(BASE + '/admin/waivers.php');
    // Sarah Johnson should appear in the list
    await expect(page.locator('#waiversTable tbody')).toContainText('Sarah Johnson');
    // The reason text should also be there
    await expect(page.locator('#waiversTable tbody')).toContainText('Test exemption via Playwright');
});

test('Edit toggle shows delete column', async ({ page }) => {
    await page.goto(BASE + '/admin/waivers.php');
    // Initially the delete column is hidden via CSS
    await expect(page.locator('#editToggle')).toBeVisible();
    // Before toggling: delete buttons not visible
    await expect(page.locator('button.btn-outline-danger').first()).toBeHidden();
    // After toggling: delete buttons appear
    await page.click('#editToggle');
    await expect(page.locator('button.btn-outline-danger').first()).toBeVisible();
    await expect(page.locator('#editToggle')).toContainText('Done');
});

test('deleting an exemption removes it from the list', async ({ page }) => {
    await page.goto(BASE + '/admin/waivers.php');
    // Enable edit mode
    await page.click('#editToggle');
    // Find Sarah Johnson's row and click its delete button
    const row = page.locator('#waiversTable tbody tr').filter({ hasText: 'Sarah Johnson' }).first();
    // Serial mode guarantees the "granting an exemption" test above ran first, so this row exists.
    await expect(row).toHaveCount(1);
    page.once('dialog', d => d.accept());
    await row.locator('button.btn-outline-danger').click();
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'delete exemption');
    // Sarah Johnson row should be gone (or show 0 exemptions of that type)
    const remaining = page.locator('#waiversTable tbody tr').filter({ hasText: 'Test exemption via Playwright' });
    await expect(remaining).toHaveCount(0);
});

// ── STUDENT FILTER ────────────────────────────────────────────────────────────

test('student filter narrows the exemptions list', async ({ page }) => {
    // First grant a fresh exemption so the list is non-empty
    await page.goto(BASE + '/admin/waivers.php');
    await page.fill('#grantStudentFilter', 'Sarah');
    await page.waitForTimeout(150);
    await page.locator('.grant-stu-btn:visible').first().click();
    await page.selectOption('select[name="waiver_type"]', 'seminar');
    await page.click('button[name="grant"]');
    await page.waitForLoadState('domcontentloaded');

    // Now filter by a different student (student_id=1) using the filter form's select
    await page.goto(BASE + `/admin/waivers.php?student_id=1`);
    await assertNoPhpErrors(page, 'filtered exemptions');
    // Sarah Johnson (id=2) rows should not appear when filtering by student 1
    const sarahRows = page.locator('#waiversTable tbody tr').filter({ hasText: 'Sarah Johnson' });
    await expect(sarahRows).toHaveCount(0);
});

// ── ACCESS CONTROL ────────────────────────────────────────────────────────────

test('admin/waivers.php redirects unauthenticated users to login', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto(BASE + '/admin/waivers.php');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('login.php');
});
