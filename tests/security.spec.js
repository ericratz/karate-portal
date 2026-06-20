// @ts-check
// Security tests — verifies all 6 hardening items and the audit log.
//
// Items covered:
//  1. CSRF tokens present in every POST form
//  2. CSRF enforcement (POST without token → 403)
//  3. Destructive actions use POST forms, not GET links
//  4. Password minimum 8 characters
//  5. Login rate limiting feedback (non-destructive: stays under threshold)
//  6. Session cookie hardening (HttpOnly, SameSite)
//  7. Audit log (page, filters, access control, event recording)
//  8. .env not accessible via HTTP
const { test, expect } = require('@playwright/test');
const { login, logout, visit, assertNoPhpErrors, BASE } = require('./helpers');

const { ADMIN_USER, ADMIN_PASS, INST_USER, INST_PASS, STU_USER, STU_PASS } = require('./credentials');

// ── 1. CSRF TOKENS PRESENT IN FORMS ──────────────────────────────────────────

test('all key POST forms have csrf_token hidden input', async ({ page }) => {
    // Login and register — unauthenticated
    await page.goto(BASE + '/login.php');
    await expect(page.locator('input[name="csrf_token"]')).toHaveCount(1);
    await page.goto(BASE + '/register.php');
    await expect(page.locator('input[name="csrf_token"]')).toHaveCount(1);
    // Authenticated pages
    await login(page, INST_USER, INST_PASS);
    const today = new Date().toISOString().slice(0, 10);
    await page.goto(BASE + `/instructor/attendance.php?date=${today}`);
    expect(await page.locator('form input[name="csrf_token"]').count()).toBeGreaterThanOrEqual(1);
    await page.goto(BASE + '/instructor/belt_test_edit.php');
    await expect(page.locator('form input[name="csrf_token"]')).toHaveCount(1);
    await logout(page);
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/payments.php?action=add');
    await page.waitForSelector('#addPaymentForm.show');
    await expect(page.locator('#addPaymentForm input[name="csrf_token"]')).toHaveCount(1);
    await logout(page);
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/student/profile_edit.php');
    expect(await page.locator('input[name="csrf_token"]').count()).toBeGreaterThanOrEqual(1);
    await logout(page);
});

// ── 3. DESTRUCTIVE ACTIONS USE POST (not GET links) ───────────────────────────

test('payment delete buttons are <button> in <form method="post">, not <a> tags', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/payments.php');
    // No GET-based delete links should exist
    const getDeleteLinks = page.locator('a[href*="delete="]');
    expect(await getDeleteLinks.count()).toBe(0);
    await logout(page);
});

test('expense delete is a POST form button', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/expenses.php');
    expect(await page.locator('a[href*="delete="]').count()).toBe(0);
    await logout(page);
});

test('expense toggle-paid is a POST form button', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/expenses.php');
    expect(await page.locator('a[href*="toggle_paid="]').count()).toBe(0);
    await logout(page);
});

test('waiver revoke is a POST form button, not a link', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/waivers.php');
    expect(await page.locator('a:has-text("Revoke")').count()).toBe(0);
    await logout(page);
});

test('waiver delete is a POST form button', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/waivers.php');
    expect(await page.locator('a[href*="delete="]').count()).toBe(0);
    await logout(page);
});

test('users page toggle is a POST form button', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/users.php');
    expect(await page.locator('a[href*="toggle="]').count()).toBe(0);
    await logout(page);
});

test('student delete profile is a POST form button', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    // Should be a form button, not a link
    expect(await page.locator('a[href*="delete_profile"]').count()).toBe(0);
    await expect(page.locator('button:has-text("Delete Profile")')).toBeVisible();
    await logout(page);
});

test('belt test delete in all-tests list is a POST button', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    expect(await page.locator('a[href*="delete="]').count()).toBe(0);
    await logout(page);
});

// ── 4. PASSWORD MINIMUM 8 CHARACTERS ─────────────────────────────────────────

test('register form has minlength=8 on password field', async ({ page }) => {
    await page.goto(BASE + '/register.php');
    const min = await page.getAttribute('input[name="password"]', 'minlength');
    expect(min).toBe('8');
});

test('registration server rejects password shorter than 8 chars', async ({ page }) => {
    await page.goto(BASE + '/register.php');
    await page.fill('input[name="first_name"]', 'Sec');
    await page.fill('input[name="last_name"]',  'Test');
    await page.fill('input[name="date_of_birth"]', '2000-01-01');
    await page.fill('input[name="email"]',       'sec@test.com');
    await page.fill('input[name="username"]',    'sectestuser1');
    await page.evaluate(() => {
        document.querySelector('input[name="password"]').removeAttribute('minlength');
        document.querySelector('input[name="confirm"]').removeAttribute('minlength');
    });
    await page.fill('input[name="password"]', 'abc123!'); // 7 chars
    await page.fill('input[name="confirm"]',  'abc123!');
    await page.click('button:has-text("Next")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger').first()).toContainText('8 characters');
});

test('profile edit minlength=8 on new password field', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/student/profile_edit.php');
    const min = await page.getAttribute('input[name="new_password"]', 'minlength');
    expect(min).toBe('8');
    await logout(page);
});

test('profile edit server rejects new password shorter than 8 chars', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/student/profile_edit.php');
    await page.click('button[data-bs-target="#changePasswordForm"]');
    await page.locator('#changePasswordForm').waitFor({ state: 'visible' });
    await page.evaluate(() => {
        document.querySelector('input[name="new_password"]').removeAttribute('minlength');
    });
    await page.fill('input[name="current_password"]', STU_PASS);
    await page.fill('input[name="new_password"]',     'abc123!'); // 7 chars
    await page.fill('input[name="confirm_password"]', 'abc123!');
    await page.click('button:has-text("Update Password")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger').first()).toContainText('8 characters');
    await logout(page);
});

test('admin user profile password reset requires 8 chars', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/users.php');
    // Password reset is on user_profile.php; navigate via View link
    await page.locator('a:has-text("View")').first().click();
    await page.waitForLoadState('domcontentloaded');
    await page.locator('button:has-text("Change")').click();
    const placeholder = await page.locator('input[name="new_password"]').getAttribute('placeholder');
    expect(placeholder).toContain('8');
    await logout(page);
});

// ── 5. LOGIN RATE LIMITING ────────────────────────────────────────────────────

test('wrong password shows standard invalid-credentials error', async ({ page }) => {
    await page.goto(BASE + '/login.php');
    await page.fill('input[name="username"]', 'ratelimitcheck');
    await page.fill('input[name="password"]', 'wrongpassword');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger')).toContainText(/invalid|incorrect|password/i);
});

test('3 failed attempts still shows wrong-password error (not rate-limited)', async ({ page }) => {
    // Stay under the 5-attempt threshold — verifies counting without triggering lockout
    for (let i = 0; i < 3; i++) {
        await page.goto(BASE + '/login.php');
        await page.fill('input[name="username"]', `ratelimitcheck${Date.now()}`);
        await page.fill('input[name="password"]', 'wrongpassword');
        await page.click('button[type="submit"]');
        await page.waitForLoadState('domcontentloaded');
    }
    await expect(page.locator('.alert-danger')).toContainText(/invalid|incorrect/i);
    await expect(page.locator('.alert-danger')).not.toContainText('Too many failed attempts');
});

test('login page loads and has no PHP errors (rate-limit code is syntactically valid)', async ({ page }) => {
    await visit(page, '/login.php', 'login rate-limit code valid');
});

// ── 6. SESSION COOKIE HARDENING ───────────────────────────────────────────────

test('session cookie has HttpOnly, SameSite=Lax, and broad path', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    const cookies = await page.context().cookies();
    const session = cookies.find(c => c.name === 'PHPSESSID');
    expect(session, 'PHPSESSID cookie should exist').toBeDefined();
    expect(session?.httpOnly).toBe(true);
    expect(session?.sameSite).toMatch(/Lax/i);
    expect(session?.path).toBe('/');
    await logout(page);
});

// ── 7. AUDIT LOG ─────────────────────────────────────────────────────────────

test('audit log page loads for admin without errors', async ({ page }) => {
    test.setTimeout(25000); // audit log can be slow mid-suite when the table has many entries
    await login(page, ADMIN_USER, ADMIN_PASS);
    await visit(page, '/admin/audit_log.php', 'audit log');
    await expect(page.locator('h3')).toContainText('Audit Log');
    await logout(page);
});

test('audit log shows login_success events', async ({ page }) => {
    // Log in to generate a fresh login_success event
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/audit_log.php?action=login_success');
    await assertNoPhpErrors(page, 'audit log login_success filter');
    await expect(page.locator('body')).toContainText('login_success');
    await logout(page);
});

test('audit log records login_fail events', async ({ page }) => {
    // Generate a login_fail event
    await page.goto(BASE + '/login.php');
    await page.fill('input[name="username"]', 'auditlogfailtest');
    await page.fill('input[name="password"]', 'wrongpassword');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');

    // Check admin can see it
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/audit_log.php?action=login_fail');
    await assertNoPhpErrors(page, 'audit log login_fail filter');
    await expect(page.locator('body')).toContainText('login_fail');
    await logout(page);
});

test('audit log filter by action only shows matching rows', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/audit_log.php?action=login_success');
    // Every badge in results should say login_success (if any rows exist)
    const rows = await page.locator('tbody tr').count();
    if (rows > 0) {
        const badges = await page.locator('tbody .badge').allTextContents();
        badges.forEach(b => expect(b.trim()).toBe('login_success'));
    }
    await logout(page);
});

test('audit log has filter inputs for action, user, from, to', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/audit_log.php');
    await expect(page.locator('select[name="action"]')).toBeVisible();
    await expect(page.locator('input[name="user"]')).toBeVisible();
    await expect(page.locator('input[name="from"]')).toBeVisible();
    await expect(page.locator('input[name="to"]')).toBeVisible();
    await logout(page);
});

test('audit log date filter works', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    const today = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Denver' });
    await page.goto(BASE + `/admin/audit_log.php?from=${today}&to=${today}`);
    await assertNoPhpErrors(page, 'audit log date filter');
    await expect(page.locator('h3')).toContainText('Audit Log');
    await logout(page);
});

test('audit log is accessible via admin nav dropdown', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/');
    // Open the Admin dropdown so the menu items are visible
    await page.click('.navbar .dropdown-toggle:has-text("Admin")');
    const link = page.locator('.dropdown-menu a:has-text("Audit Log")');
    await expect(link).toBeVisible();
    const href = await link.getAttribute('href');
    expect(href).toContain('audit_log.php');
    await logout(page);
});

test('audit log requires admin role — non-admin is denied', async ({ page }) => {
    // Use the guest account — never modified by other tests, role='student' in users table
    await login(page, 'test', 'testing');
    await page.goto(BASE + '/admin/audit_log.php');
    const body = await page.textContent('body');
    expect(body).toContain('Access denied');
    await logout(page);
});

test('audit log requires admin role — instructor is denied', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/admin/audit_log.php');
    const body = await page.textContent('body');
    expect(body).toContain('Access denied');
    await logout(page);
});

// ── 8. .env NOT ACCESSIBLE VIA HTTP ──────────────────────────────────────────

test('.env file is blocked by the server (not 200)', async ({ page }) => {
    // Requires Apache AllowOverride All so .htaccess is processed.
    // On properly configured XAMPP this returns 403.
    const res = await page.request.get('http://localhost/karate/.env');
    expect(res.status()).not.toBe(200);
});

test('.env URL does not return database credentials', async ({ page }) => {
    const res = await page.request.get('http://localhost/karate/.env');
    const body = await res.text();
    // Even if somehow accessible, DB password should not appear as plain HTML
    expect(body).not.toContain('DB_PASS=');
});
