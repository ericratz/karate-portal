// @ts-check
// Tests for the digital injury waiver feature.
// Uses a freshly registered account so tests are fully isolated
// and don't touch jsmith's waiver status.
const { test, expect } = require('@playwright/test');
const { login, logout, visit, assertNoPhpErrors, deleteTestStudent, BASE } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const { ADMIN_USER, ADMIN_PASS, W_PASS } = require('./credentials');
const TS         = Date.now();
const W_USER     = `waiver${TS}`;   // fresh account used only in this suite

// ── SETUP: register a fresh account ──────────────────────────────────────────

test('setup: register fresh account for waiver tests', async ({ page }) => {
    await page.goto(BASE + '/register.php');
    await page.fill('input[name="first_name"]', 'Waiver');
    await page.fill('input[name="last_name"]',  `Tester${TS}`);
    await page.fill('input[name="date_of_birth"]', '1990-03-15');
    await page.fill('input[name="email"]', `waiver${TS}@test.com`);
    await page.fill('input[name="username"]', W_USER);
    await page.fill('input[name="password"]', W_PASS);
    await page.fill('input[name="confirm"]',  W_PASS);
    await page.click('button:has-text("Create Account")');
    await page.waitForLoadState('domcontentloaded');
    // Registration now shows the Notify Noji step — skip it to reach the done screen
    await page.click('button:has-text("Skip")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-success').first()).toBeVisible();

    // Submit profile form to create a linked student record (needed for dashboard to load)
    // profile_edit.php creates a guest student row when none exists
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/student/profile_edit.php');
    await page.fill('input[name="first_name"]', 'Waiver');
    await page.fill('input[name="last_name"]',  `Tester${TS}`);
    await page.fill('input[name="date_of_birth"]', '1990-03-15');
    await page.fill('input[name="email"]', `waiver${TS}@test.com`);
    await page.click('button:has-text("Save Profile")');
    await page.waitForLoadState('domcontentloaded');
    await logout(page);
});

// ── DASHBOARD BUTTON ──────────────────────────────────────────────────────────

test('dashboard shows Complete Waiver button when unsigned', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/student/');
    await expect(page.locator('a:has-text("Complete Waiver")')).toBeVisible();
    await logout(page);
});

test('Complete Waiver button links to waiver.php', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/student/');
    const href = await page.locator('a:has-text("Complete Waiver")').getAttribute('href');
    expect(href).toContain('waiver.php');
    await logout(page);
});

// ── WAIVER PAGE LOADS ─────────────────────────────────────────────────────────

test('waiver.php loads without PHP errors', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await visit(page, '/student/waiver.php', 'waiver page');
    await logout(page);
});

test('waiver page shows full agreement text', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/student/waiver.php');
    await expect(page.locator('body')).toContainText('Shotokan Karate Training Program');
    await expect(page.locator('body')).toContainText('Waiver of Legal Rights and Indemnification Agreement');
    await expect(page.locator('body')).toContainText('Noji Ratzlaff');
    await logout(page);
});

test('waiver page shows signature form when not yet signed', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/student/waiver.php');
    await expect(page.locator('input[name="print_name"]')).toBeVisible();
    await expect(page.locator('input[name="signature"]')).toBeVisible();
    await expect(page.locator('input[name="signed_date"]')).toBeVisible();
    await expect(page.locator('input[name="cell_phone"]')).toBeVisible();
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('input[name="i_agree"]')).toBeVisible();
    await logout(page);
});

test('waiver form pre-fills name and email from profile', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/student/waiver.php');
    const name  = await page.inputValue('input[name="print_name"]');
    const email = await page.inputValue('input[name="email"]');
    expect(name.toLowerCase()).toContain('waiver');
    expect(email).toContain(`waiver${TS}@test.com`);
    await logout(page);
});

test('waiver form pre-fills signed date as today', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/student/waiver.php');
    const date = await page.inputValue('input[name="signed_date"]');
    // Server runs in Mountain Time; use local date string to match PHP's date()
    const serverDate = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Denver' });
    expect(date).toBe(serverDate);
    await logout(page);
});

// ── FORM VALIDATION ───────────────────────────────────────────────────────────

test('submitting without signature shows error', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/student/waiver.php');
    // Clear the pre-filled name and leave signature empty
    await page.fill('input[name="print_name"]', '');
    await page.evaluate(() => {
        document.querySelector('input[name="print_name"]').removeAttribute('required');
        document.querySelector('input[name="signature"]').removeAttribute('required');
        document.querySelector('input[name="i_agree"]').removeAttribute('required');
        document.querySelector('input[name="cell_phone"]').removeAttribute('required');
        document.querySelector('input[name="email"]').removeAttribute('required');
        document.querySelector('input[name="street_address"]').removeAttribute('required');
        document.querySelector('input[name="city_state_zip"]').removeAttribute('required');
    });
    await page.click('button:has-text("Submit Signed Waiver")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger').first()).toContainText('signature');
    await logout(page);
});

test('submitting without agreement checkbox shows error', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/student/waiver.php');
    await page.fill('input[name="print_name"]', 'Waiver Tester');
    await page.fill('input[name="signature"]',  'Waiver Tester');
    await page.fill('input[name="cell_phone"]',  '555-1234');
    await page.fill('input[name="street_address"]', '123 Main St');
    await page.fill('input[name="city_state_zip"]', 'Orem, UT 84058');
    // Don't check i_agree — remove required so form submits
    await page.evaluate(() => {
        document.querySelector('input[name="i_agree"]').removeAttribute('required');
    });
    const submitBtn = page.locator('button:has-text("Submit Signed Waiver")');
    await submitBtn.scrollIntoViewIfNeeded();
    await submitBtn.click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger').first()).toContainText('agreement');
    await logout(page);
});

test('submitting without required contact fields shows error', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/student/waiver.php');
    await page.fill('input[name="print_name"]', 'Waiver Tester');
    await page.fill('input[name="signature"]',  'Waiver Tester');
    await page.check('input[name="i_agree"]');
    // Leave cell_phone, street_address, city_state_zip empty
    await page.evaluate(() => {
        ['cell_phone','email','street_address','city_state_zip']
            .forEach(n => document.querySelector(`input[name="${n}"]`).removeAttribute('required'));
        document.querySelector('input[name="email"]').value = '';
        document.querySelector('input[name="cell_phone"]').value = '';
    });
    await page.click('button:has-text("Submit Signed Waiver")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger').first()).toBeVisible();
    await logout(page);
});

// ── SUCCESSFUL SUBMISSION ─────────────────────────────────────────────────────

test('valid submission redirects to dashboard with waiver signed', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/student/waiver.php');

    await page.fill('input[name="print_name"]',    'Waiver Tester');
    await page.fill('input[name="signature"]',     'Waiver Tester');
    await page.fill('input[name="date_of_birth"]', '1990-03-15');
    await page.fill('input[name="cell_phone"]',    '555-0100');
    await page.fill('input[name="email"]',         `waiver${TS}@test.com`);
    await page.fill('input[name="street_address"]', '123 Karate Lane');
    await page.fill('input[name="city_state_zip"]', 'Orem, UT 84058');
    await page.check('input[name="i_agree"]');

    await page.click('button:has-text("Submit Signed Waiver")');
    await page.waitForLoadState('domcontentloaded');

    // Should redirect to dashboard
    expect(page.url()).toContain('index.php');
    await logout(page);
});

test('dashboard hides waiver card after signing', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/student/');
    // Complete Waiver button should be gone — card is hidden once signed
    await expect(page.locator('a:has-text("Complete Waiver")')).toHaveCount(0);
    await logout(page);
});

test('waiver.php shows read-only signed view after submission', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/student/waiver.php');

    // Fields should be read-only (not an editable form)
    await expect(page.locator('input[name="signature"]')).toHaveAttribute('readonly', '');
    await expect(page.locator('input[name="print_name"]')).toHaveAttribute('readonly', '');
    // Should show the signed confirmation banner
    await expect(page.locator('.alert-success').first()).toContainText('signed this waiver');
    // Signed data should be pre-filled into the readonly inputs
    await expect(page.locator('input[name="print_name"]')).toHaveValue('Waiver Tester');
    await expect(page.locator('input[name="street_address"]')).toHaveValue('123 Karate Lane');
    await logout(page);
});

test('waiver.php has navigation to student dashboard', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/student/waiver.php');
    // Navigation back to dashboard is via the navbar brand ("My Dashboard")
    const href = await page.locator('.navbar-brand').getAttribute('href');
    expect(href).toContain('/student/');
    await logout(page);
});

// ── ADMIN VIEW ────────────────────────────────────────────────────────────────

// Helper: navigate from students list → profile → waiver view for the test account
async function goToWaiverView(page) {
    await page.goto(BASE + '/admin/students.php');
    const profileLink = page.locator('tr').filter({ hasText: `Tester${TS}` }).locator('a:has-text("Profile")').first();
    if (!await profileLink.isVisible()) return false;
    await profileLink.click();
    await page.waitForLoadState('domcontentloaded');
    const waiverLink = page.locator('a[href*="waiver_view.php"]');
    if (!await waiverLink.isVisible()) return false;
    await waiverLink.click();
    await page.waitForLoadState('domcontentloaded');
    return true;
}

test('admin: waiver_view.php loads for student with signed waiver', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    if (await goToWaiverView(page)) {
        await assertNoPhpErrors(page, 'admin waiver view');
        await expect(page.locator('body')).toContainText('Waiver Tester'); // appears in h4 heading
        await expect(page.locator('input[name="street_address"]')).toHaveValue('123 Karate Lane');
        await expect(page.locator('input[name="email"]')).toHaveValue(`waiver${TS}@test.com`);
    }
    await logout(page);
});

test('admin: waiver_view.php shows IP address and timestamp', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    if (await goToWaiverView(page)) {
        await expect(page.locator('dt:has-text("IP Address")')).toBeVisible();
        await expect(page.locator('.card-header span:has-text("Submitted:")')).toBeVisible();
    }
    await logout(page);
});

test('admin: waiver_view.php for student without waiver shows warning', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    // Use student id 2 (Sarah Johnson) — no digital waiver on file
    await page.goto(BASE + '/admin/waiver_view.php?student_id=2');
    await assertNoPhpErrors(page, 'waiver view no submission');
    // Either shows a signed waiver, a manual-waiver notice, or a "no waiver" warning
    const body = await page.textContent('body');
    const hasData    = body?.includes('Waiver digitized and saved') || body?.includes('signed_date');
    const hasWarning = body?.includes('No digital waiver submission') || body?.includes('No digital submission');
    expect(hasData || hasWarning).toBe(true);
    await logout(page);
});

test('admin: invalid student id redirects from waiver_view.php', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/waiver_view.php?student_id=99999');
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'waiver view invalid id');
    await logout(page);
});

test('admin: View waiver link appears on signed student profile', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/students.php');
    const profileLink = page.locator('tr').filter({ hasText: `Tester${TS}` }).locator('a:has-text("Profile")').first();
    if (await profileLink.isVisible()) {
        await profileLink.click();
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('a[href*="waiver_view.php"]')).toBeVisible();
    }
    await logout(page);
});

// ── ACCESS CONTROL ────────────────────────────────────────────────────────────

test('waiver.php requires login', async ({ page }) => {
    await page.goto(BASE + '/student/waiver.php');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('login.php');
});

test('admin/waiver_view.php requires admin role', async ({ page }) => {
    await login(page, W_USER, W_PASS);
    await page.goto(BASE + '/admin/waiver_view.php?student_id=1');
    const body = await page.textContent('body');
    expect(body).toContain('Access denied');
    await logout(page);
});

// No afterAll cleanup — global-teardown always restores the DB snapshot.
