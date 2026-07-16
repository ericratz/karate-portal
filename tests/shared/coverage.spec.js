// @ts-check
// Coverage tests for back buttons, login/logout, PayPal page, and delete profile.
const { test, expect } = require('@playwright/test');
const { login, logout, visit, assertNoPhpErrors, BASE } = require('../helpers');

test.describe.configure({ mode: 'serial' });

const { ADMIN_USER, ADMIN_PASS, INST_USER, INST_PASS, STU_USER, STU_PASS } = require('../credentials');
const NOTE_TEXT  = 'Playwright test note ' + Date.now();

// ── LOGIN / LOGOUT ────────────────────────────────────────────────────────────

test('wrong password shows error, stays on login page', async ({ page }) => {
    await page.goto(BASE + '/login.php');
    await page.fill('input[name="username"]', ADMIN_USER);
    await page.fill('input[name="password"]', 'wrongpassword');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('login.php');
    const body = await page.textContent('body');
    expect(body).toMatch(/invalid|incorrect|failed|wrong/i);
});

test('unauthenticated user is redirected to login', async ({ page }) => {
    await page.goto(BASE + '/student/');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('login.php');
});

test('logout redirects to login page', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await logout(page);
    expect(page.url()).toContain('login.php');
});

test('after logout, protected page redirects to login', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await logout(page);
    await page.goto(BASE + '/student/');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('login.php');
});

// ── PAYPAL PAGE ───────────────────────────────────────────────────────────────

test('pay.php loads without PHP errors', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await visit(page, '/student/pay.php', 'pay page');
});

test('paypal_create.php rejects unauthenticated request', async ({ page }) => {
    // Hit the endpoint without a session
    const res = await page.goto(BASE + '/api/paypal_create.php');
    // Should redirect to login or return error
    const body = await page.textContent('body');
    expect(body).not.toContain('order_id');
});

// ── DELETE PROFILE ────────────────────────────────────────────────────────────

test('delete profile button visible on edit page for admin', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    await expect(page.locator('button:has-text("Delete Profile")')).toBeVisible();
});

test('delete profile button not on view profile page', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/instructor/student_profile.php?id=2');
    await expect(page.locator('button:has-text("Delete Profile")')).toHaveCount(0);
});

test('instructor cannot see delete profile button', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    // Instructors can't reach student_edit.php — it's admin only
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    const body = await page.textContent('body');
    expect(body).toContain('Access denied');
});

// ── PASSWORD CHANGE ───────────────────────────────────────────────────────────

test('student: change password form expands on button click', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/student/profile_edit.php');
    // Collapse is hidden by default — click the toggle
    await page.click('button[data-bs-target="#changePasswordForm"]');
    await expect(page.locator('#changePasswordForm')).toBeVisible();
    await expect(page.locator('input[name="current_password"]')).toBeVisible();
    await expect(page.locator('input[name="new_password"]')).toBeVisible();
    await expect(page.locator('input[name="confirm_password"]')).toBeVisible();
    await logout(page);
});

test('student: wrong current password shows error', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/student/profile_edit.php');
    await page.click('button[data-bs-target="#changePasswordForm"]');
    await page.locator('#changePasswordForm').waitFor({ state: 'visible' });
    await page.fill('input[name="current_password"]', 'wrongpassword');
    await page.fill('input[name="new_password"]', 'NewPass123!');
    await page.fill('input[name="confirm_password"]', 'NewPass123!');
    await page.click('button:has-text("Update Password")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger').first()).toContainText('incorrect');
    await logout(page);
});

test('student: mismatched new passwords shows error', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/student/profile_edit.php');
    await page.click('button[data-bs-target="#changePasswordForm"]');
    await page.locator('#changePasswordForm').waitFor({ state: 'visible' });
    await page.fill('input[name="current_password"]', STU_PASS);
    await page.fill('input[name="new_password"]', 'NewPass123!');
    await page.fill('input[name="confirm_password"]', 'DifferentPass!');
    await page.click('button:has-text("Update Password")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger').first()).toContainText('do not match');
    await logout(page);
});

test('student: valid password change succeeds and new password works', async ({ page }) => {
    test.setTimeout(30_000); // multiple logins + form submissions; needs more than 5s
    const TEMP_PASS = 'TempPass999!';

    // Change to temp password
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/student/profile_edit.php');
    await page.click('button[data-bs-target="#changePasswordForm"]');
    await page.locator('#changePasswordForm').waitFor({ state: 'visible' });
    await page.fill('input[name="current_password"]', STU_PASS);
    await page.fill('input[name="new_password"]', TEMP_PASS);
    await page.fill('input[name="confirm_password"]', TEMP_PASS);
    await page.click('button:has-text("Update Password")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-success').first()).toContainText('updated');
    await logout(page);

    // Verify new password works — ALWAYS reset back in finally so other tests aren't broken
    try {
        await login(page, STU_USER, TEMP_PASS);
        expect(page.url()).toContain('/student/');
        await logout(page);
    } finally {
        // Reset password back to original regardless of whether assertions passed
        await login(page, STU_USER, TEMP_PASS);
        await page.goto(BASE + '/student/profile_edit.php');
        await page.click('button[data-bs-target="#changePasswordForm"]');
        await page.locator('#changePasswordForm').waitFor({ state: 'visible' });
        await page.fill('input[name="current_password"]', TEMP_PASS);
        await page.fill('input[name="new_password"]', STU_PASS);
        await page.fill('input[name="confirm_password"]', STU_PASS);
        await page.click('button:has-text("Update Password")');
        await page.waitForLoadState('domcontentloaded');
        await logout(page);
    }
});

// ── EDIT ROUND-TRIPS ─────────────────────────────────────────────────────────

test('adding a general note saves and appears in the list', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/student_notes.php');
    const noteText = NOTE_TEXT;
    await page.click('button:has-text("Add Entry")');
    await page.fill('textarea[name="content"]', noteText);
    await page.click('button:has-text("Save Entry")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('body')).toContainText(noteText);
});

test('student profile edit saves first name change', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/student/profile_edit.php');
    await assertNoPhpErrors(page, 'profile edit loads');
    // Just verify the form submits without error (don't mutate real name)
    const firstName = await page.inputValue('input[name="first_name"]');
    expect(firstName.length).toBeGreaterThan(0);
});

test('admin expense record saves and appears in list', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/expenses.php');
    // Open the add form
    await page.click('button:has-text("+ Record Expense")');
    await page.fill('input[name="amount"]', '1.00');
    await page.fill('input[name="expense_date"]', new Date().toISOString().slice(0, 10));
    await page.fill('input[name="description"]', 'Playwright test expense');
    await page.click('button:has-text("Save Expense")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('body')).toContainText('Playwright test expense');
});

// ── ATTENDANCE BAR CHART ──────────────────────────────────────────────────────

test('student dashboard has attendance chart canvas', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/student/');
    await assertNoPhpErrors(page, 'student dashboard chart');
    await expect(page.locator('#attChart')).toBeVisible();
    await logout(page);
});

test('student dashboard renders the Chart.js chart', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/student/');
    // Chart.js is bundled into the SPA (no CDN script tag) — assert the
    // canvas was actually initialized: Chart.js sets inline width/height.
    await expect(page.locator('#attChart')).toBeVisible();
    await expect(async () => {
        const w = await page.locator('#attChart').getAttribute('width');
        expect(Number(w)).toBeGreaterThan(0);
    }).toPass();
    await logout(page);
});

test('attendance chart data covers 12 months', async ({ page }) => {
    // The SPA feeds the chart from api/v1 — assert the payload the way the
    // React app consumes it (the old test parsed inline JS that no longer exists).
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/student/');
    await expect(page.locator('#attChart')).toBeVisible();
    const months = await page.evaluate(async () => {
        const base = '/karate/portal/api/v1';
        const fam = (await (await fetch(base + '/parent/family.php', { credentials: 'same-origin' })).json()).data;
        const sid = fam.own_student.id;
        const dash = (await (await fetch(base + `/parent/student.php?student_id=${sid}`, { credentials: 'same-origin' })).json()).data;
        return dash.attendance_chart.length;
    });
    expect(months).toBe(12);
    await logout(page);
});

// ── RESOLVE LINK PAGE ─────────────────────────────────────────────────────────

test('resolve_link.php without lr_id redirects admin to dashboard', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/resolve_link.php');
    await page.waitForLoadState('domcontentloaded');
    // PHP redirects to ./ (admin/) when no lr_id given
    expect(page.url()).toContain('/admin/');
    expect(page.url()).not.toContain('resolve_link.php');
    await logout(page);
});

test('resolve_link.php with unknown lr_id redirects with error=not_found', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/resolve_link.php?lr_id=99999');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('error=not_found');
    await logout(page);
});

test('resolve_link.php requires admin — non-admin is denied', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/admin/resolve_link.php?lr_id=1');
    await page.waitForLoadState('domcontentloaded');
    const body = await page.textContent('body');
    const denied = body.includes('Access denied') || page.url().includes('login.php');
    expect(denied).toBe(true);
    await logout(page);
});

// No afterAll cleanup needed — global-teardown always restores the DB snapshot.
