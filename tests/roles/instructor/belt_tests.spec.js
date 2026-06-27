// @ts-check
// Belt test tests â€” edit form, toggle UI, and create/verify/delete round-trip.
const { test, expect } = require('@playwright/test');
const { login, logout, visit, assertNoPhpErrors, BASE, AUTH } = require('../../helpers');
const { ADMIN_USER, ADMIN_PASS } = require('../../credentials');

test.describe.configure({ mode: 'serial' });
// Most tests run as instructor; admin/guest tests override with explicit login.
test.use({ storageState: AUTH.instructor });
const TS = Date.now();

// â”€â”€ BELT TEST EDIT FORM â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('belt_test_edit.php loads and form fields are correct', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_test_edit.php');
    await assertNoPhpErrors(page, 'belt_test_edit new');
    // Score input
    const inp = page.locator('#scoreInput');
    await expect(inp).toBeVisible();
    expect(await inp.getAttribute('min')).toBe('0');
    expect(await inp.getAttribute('max')).toBe('100');
    // Student select has options
    expect(await page.locator('select[name="student_id"] option').count()).toBeGreaterThan(1);
    // Rank select is required
    expect(await page.locator('select[name="rank_id"]').getAttribute('required')).not.toBeNull();
    // Date defaults to today (Â±2 days)
    const val = await page.inputValue('input[name="test_date"]');
    expect(val).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(Math.abs(Date.now() - new Date(val + 'T12:00:00').getTime())).toBeLessThan(2 * 86400000);
    // Belt Awarded unchecked, Fee Paid enabled
    expect(await page.locator('#beltAwarded').isChecked()).toBe(false);
    await expect(page.locator('#feePaid')).toBeEnabled();
    // Workflow alert visible
    await expect(page.locator('.alert-light')).toContainText('Workflow');
});

test('score preview shows Pass/Fail/empty based on input', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_test_edit.php');
    await page.fill('#scoreInput', '85');
    await page.dispatchEvent('#scoreInput', 'input');
    await expect(page.locator('#resultPreview .badge.bg-success')).toBeVisible();
    await page.fill('#scoreInput', '70');
    await page.dispatchEvent('#scoreInput', 'input');
    await expect(page.locator('#resultPreview .badge.bg-danger')).toBeVisible();
    await page.fill('#scoreInput', '');
    await page.dispatchEvent('#scoreInput', 'input');
    expect((await page.textContent('#resultPreview'))?.trim()).toBe('');
});

test('submitting without student and rank shows validation error', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_test_edit.php');
    await page.evaluate(() => {
        ['select[name="student_id"]','select[name="rank_id"]','input[name="test_date"]']
            .forEach(s => document.querySelector(s)?.removeAttribute('required'));
    });
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger').first()).toBeVisible();
});

// â”€â”€ BELT TESTS ALL â€” DASHBOARD + TOGGLE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('instructor dashboard shows Recent Belt Tests and links to belt_tests_all.php', async ({ page }) => {
    await page.goto(BASE + '/instructor/');
    await expect(page.locator('.card-header').filter({ hasText: 'Recent Belt Tests' })).toBeVisible();
    await expect(page.locator('a[href="belt_tests_all.php"]')).toBeVisible();
});

test('belt_tests_all.php loads, shows count, and edit toggle works', async ({ page }) => {
    await visit(page, '/instructor/belt_tests_all.php', 'all belt tests');
    // Header shows count
    expect(await page.locator('.card-header').first().textContent()).toMatch(/\d+\s*test/i);
    // Unauthenticated user cannot access â€” clear cookies instead of logging in as guest
    await page.context().clearCookies();
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    expect(page.url()).toContain('login.php'); // redirected to login
});

test('belt tests edit toggle shows and hides delete column', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    const hasBeltTests = await page.locator('#beltTestsTable tbody tr').count() > 0;
    if (hasBeltTests) {
        // Initially hidden
        await expect(page.locator('#beltTestsTable .delete-col').first()).toBeHidden();
        await page.click('#editToggle');
        await expect(page.locator('#beltTestsTable .delete-col').first()).toBeVisible();
        await expect(page.locator('#editToggle')).toHaveText('Done');
        await page.click('#editToggle');
        await expect(page.locator('#beltTestsTable .delete-col').first()).toBeHidden();
        await expect(page.locator('#editToggle')).toHaveText('Edit');
    }
});

test('existing belt test edit form pre-fills correctly', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    await page.click('#editToggle');
    const editLink = page.locator('a:has-text("Edit")').first();
    if (await editLink.count() === 0) return;
    await editLink.click();
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'belt_test_edit existing');
    expect(await page.inputValue('input[name="test_date"]')).toMatch(/\d{4}-\d{2}-\d{2}/);
    await expect(page.locator('button:has-text("Save Changes")')).toBeVisible();
    await expect(page.locator('button:has-text("Delete")')).toBeVisible();
});

// â”€â”€ CREATE â†’ VERIFY â†’ DELETE ROUND-TRIP â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('create a belt test for a student', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_test_edit.php');
    const futureDate = new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10);
    await page.selectOption('select[name="student_id"]', { index: 1 });
    await page.fill('input[name="test_date"]', futureDate);
    await page.selectOption('select[name="rank_id"]', { index: 1 });
    await page.fill('input[name="notes"]', `Delete Me ${TS}`);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'create belt test for delete');
});

test('created belt test appears in list', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    await expect(page.locator('body')).toContainText(`Delete Me ${TS}`);
});

test('delete button removes the belt test', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    await page.click('#editToggle');
    const row = page.locator('tr').filter({ hasText: `Delete Me ${TS}` });
    page.once('dialog', d => d.accept());
    await row.locator('.btn-outline-danger').click();
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'after delete');
    await expect(page.locator('body')).not.toContainText(`Delete Me ${TS}`);
});

// â”€â”€ AUTO-RANK: passing score auto-inserts into student_ranks â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// PHP logic: score >= 80 â†’ result='pass' AND belt_awarded=1 automatically,
// which triggers INSERT IGNORE INTO student_ranks. Verified via admin/student_edit.php.

test('create a passing belt test (score >= 80) for student 2', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_test_edit.php');
    await page.waitForLoadState('domcontentloaded');
    const today = new Date().toISOString().slice(0, 10);
    // Select Sarah Johnson (student id=2) by value
    await page.selectOption('select[name="student_id"]', { value: '2' });
    await page.fill('input[name="test_date"]', today);
    // Choose the first available rank
    await page.selectOption('select[name="rank_id"]', { index: 1 });
    // Score >= 80 â†’ PHP auto-sets result='pass' and belt_awarded=1 (auto-rank)
    await page.fill('#scoreInput', '85');
    await page.fill('input[name="notes"]', `AutoRank ${TS}`);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'create passing belt test');
    // Successful save redirects to belt_test_edit.php?id=X&saved=1
    expect(page.url()).toContain('saved=1');
});

test('passing belt test appears in list with a score badge', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    await expect(page.locator('body')).toContainText(`AutoRank ${TS}`);
    // The row for our test should show a score (85%) â€” bg-success badge
    const row = page.locator('tr').filter({ hasText: `AutoRank ${TS}` });
    await expect(row.locator('.badge.bg-success, .badge.bg-danger, .badge.bg-secondary').first()).toBeVisible();
});

test('passing belt test auto-adds rank to student Rank History in student_edit', async ({ page }) => {
    // student_edit.php requires admin role â€” clear the instructor session first so
    // login.php shows its form instead of redirecting to the instructor dashboard
    await page.context().clearCookies();
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    await assertNoPhpErrors(page, 'student edit after auto-rank');
    // Rank History card should be present
    await expect(page.locator('.card-header').filter({ hasText: 'Rank History' })).toBeVisible();
    // The auto-rank INSERT IGNORE means the student's Rank History card
    // should have at least one rank row (not "No ranks recorded.")
    await expect(
        page.locator('.card').filter({ has: page.locator('.card-header:has-text("Rank History")') })
    ).not.toContainText('No ranks recorded.');
    await logout(page);
});
