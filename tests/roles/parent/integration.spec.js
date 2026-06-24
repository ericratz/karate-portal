// @ts-check
// Integration tests for the parent portal.
// Parent account: username='test', student_type='parent', student_id=6.
// Children in DB: Carlos Rivera (id=5), Emily Wilson (id=4).
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, visit, BASE, AUTH } = require('../../helpers');

const PARENT_OWN_ID  = 6; // parent's own student row
const CHILD_EMILY    = 4;
const CHILD_CARLOS   = 5;
const UNLINKED_ID    = 2; // Sarah Johnson — not linked to this parent

test.describe('Parent portal — integration', () => {
    test.use({ storageState: AUTH.parent });

    // ── DASHBOARD ─────────────────────────────────────────────────────────────

    test('parent dashboard loads without PHP errors', async ({ page }) => {
        await visit(page, '/parent/', 'parent dashboard');
    });

    test('parent dashboard shows family tabs for children', async ({ page }) => {
        await page.goto(BASE + '/parent/');
        await assertNoPhpErrors(page, 'parent dashboard');
        const body = await page.textContent('body');
        // Both linked children should appear as nav tabs
        expect(body).toContain('Emily Wilson');
        expect(body).toContain('Carlos Rivera');
    });

    test('parent dashboard default tab shows welcome heading', async ({ page }) => {
        await page.goto(BASE + '/parent/');
        await assertNoPhpErrors(page, 'parent dashboard default tab');
        // Default tab is parent's own student — heading shows their first name
        await expect(page.locator('h3').first()).toBeVisible();
    });

    test('switching to Emily tab shows her data', async ({ page }) => {
        await page.goto(BASE + `/parent/?student_id=${CHILD_EMILY}`);
        await assertNoPhpErrors(page, 'parent dashboard Emily tab');
        const body = await page.textContent('body');
        expect(body).toContain('Emily');
    });

    test('switching to Carlos tab shows his data', async ({ page }) => {
        await page.goto(BASE + `/parent/?student_id=${CHILD_CARLOS}`);
        await assertNoPhpErrors(page, 'parent dashboard Carlos tab');
        const body = await page.textContent('body');
        expect(body).toContain('Carlos');
    });

    test('invalid student_id does not crash — falls back to default tab', async ({ page }) => {
        await page.goto(BASE + '/parent/?student_id=99999');
        await assertNoPhpErrors(page, 'parent dashboard invalid id');
        // Should still show the page without a crash — validation silently resets tab_id
        await expect(page.locator('body')).not.toContainText('Fatal error');
    });

    test('unlinked student_id is silently ignored', async ({ page }) => {
        await page.goto(BASE + `/parent/?student_id=${UNLINKED_ID}`);
        await assertNoPhpErrors(page, 'parent dashboard unlinked id');
        // Page still loads; PHP falls back to allowed tab_id without a redirect
        await expect(page.locator('body')).not.toContainText('Fatal error');
    });

    test('dashboard shows Make a Payment button linking to pay.php', async ({ page }) => {
        await page.goto(BASE + `/parent/?student_id=${CHILD_EMILY}`);
        await expect(page.locator('a[href*="pay.php"]').first()).toBeVisible();
    });

    test('dashboard shows Edit Profile button linking to profile_edit.php', async ({ page }) => {
        await page.goto(BASE + `/parent/?student_id=${CHILD_EMILY}`);
        await expect(page.locator('a[href*="profile_edit.php"]').first()).toBeVisible();
    });

    // ── BELT TESTS PAGE ───────────────────────────────────────────────────────

    test('belt_tests.php loads for linked child', async ({ page }) => {
        await visit(page, `/parent/belt_tests.php?student_id=${CHILD_EMILY}`, 'parent belt tests Emily');
    });

    test('belt_tests.php loads for Carlos', async ({ page }) => {
        await visit(page, `/parent/belt_tests.php?student_id=${CHILD_CARLOS}`, 'parent belt tests Carlos');
    });

    test('belt_tests.php with unlinked student_id redirects to index', async ({ page }) => {
        await page.goto(BASE + `/parent/belt_tests.php?student_id=${UNLINKED_ID}`);
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('/parent/');
        expect(page.url()).not.toContain('belt_tests.php');
    });

    test('belt_tests.php with no student_id redirects to index', async ({ page }) => {
        await page.goto(BASE + '/parent/belt_tests.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('/parent/');
        expect(page.url()).not.toContain('belt_tests.php');
    });

    // ── ATTENDANCE PAGE ───────────────────────────────────────────────────────

    test('attendance.php loads for linked child', async ({ page }) => {
        await visit(page, `/parent/attendance.php?student_id=${CHILD_EMILY}`, 'parent attendance');
    });

    test('attendance.php with unlinked student_id redirects to index', async ({ page }) => {
        await page.goto(BASE + `/parent/attendance.php?student_id=${UNLINKED_ID}`);
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('/parent/');
        expect(page.url()).not.toContain('attendance.php');
    });

    // ── PAY PAGE ──────────────────────────────────────────────────────────────

    test('pay.php loads for parent role', async ({ page }) => {
        await visit(page, '/parent/pay.php', 'parent pay page');
    });

    // ── PAYMENT HISTORY ───────────────────────────────────────────────────────

    test('payment_history.php loads for linked child', async ({ page }) => {
        await visit(page, `/parent/payment_history.php?student_id=${CHILD_EMILY}`, 'parent payment history');
    });

    test('payment_history.php with unlinked student_id redirects to index', async ({ page }) => {
        await page.goto(BASE + `/parent/payment_history.php?student_id=${UNLINKED_ID}`);
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('/parent/');
        expect(page.url()).not.toContain('payment_history.php');
    });

    // ── PROFILE EDIT ──────────────────────────────────────────────────────────

    test('profile_edit.php loads for parent own student', async ({ page }) => {
        await visit(page, `/parent/profile_edit.php?student_id=${PARENT_OWN_ID}`, 'parent profile edit');
    });

    test('profile_edit.php with unlinked student_id redirects to index', async ({ page }) => {
        await page.goto(BASE + `/parent/profile_edit.php?student_id=${UNLINKED_ID}`);
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('/parent/');
        expect(page.url()).not.toContain('profile_edit.php');
    });

    test('profile_edit.php shows first_name and last_name inputs', async ({ page }) => {
        await page.goto(BASE + `/parent/profile_edit.php?student_id=${PARENT_OWN_ID}`);
        await assertNoPhpErrors(page, 'parent profile edit fields');
        await expect(page.locator('input[name="first_name"]')).toBeVisible();
        await expect(page.locator('input[name="last_name"]')).toBeVisible();
    });
});
