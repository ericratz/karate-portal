// @ts-check
// Tests for student profile save: ?saved=1 flash banner and address field persistence.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('./helpers');

test.describe.configure({ mode: 'serial' });
test.use({ storageState: AUTH.student });

const TS          = Date.now();
const TEST_STREET = `999 Test Lane ${TS}`;
const TEST_CSZ    = 'Provo, UT 84601';

// ── PROFILE SAVE FLASH ────────────────────────────────────────────────────────

test('student dashboard shows success banner when ?saved=1', async ({ page }) => {
    await page.goto(BASE + '/student/?saved=1');
    await assertNoPhpErrors(page, 'student dashboard saved flash');
    await expect(page.locator('.alert-success').first()).toContainText('Profile saved successfully');
});

test('student dashboard does NOT show profile banner without ?saved=1', async ({ page }) => {
    await page.goto(BASE + '/student/');
    await assertNoPhpErrors(page, 'student dashboard no flash');
    await expect(page.locator('.alert-success:has-text("Profile saved")')).toHaveCount(0);
});

// ── PROFILE EDIT FORM ─────────────────────────────────────────────────────────

test('profile_edit.php loads and shows address fields', async ({ page }) => {
    await page.goto(BASE + '/student/profile_edit.php');
    await assertNoPhpErrors(page, 'profile edit loads');
    await expect(page.locator('input[name="street_address"]')).toBeVisible();
    await expect(page.locator('input[name="city_state_zip"]')).toBeVisible();
    await expect(page.locator('input[name="first_name"]')).toBeVisible();
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('button:has-text("Save Profile")')).toBeVisible();
});

// ── ADDRESS FIELD PERSISTENCE ─────────────────────────────────────────────────

test('saving profile with address fields redirects to dashboard with success banner', async ({ page }) => {
    await page.goto(BASE + '/student/profile_edit.php');
    // Fill ALL required fields explicitly — don't rely on jsmith's DB values being non-null.
    // The DB snapshot is restored after all tests so these temporary changes are fine.
    await page.fill('input[name="first_name"]',    'John');
    await page.fill('input[name="last_name"]',     'Smith');
    await page.fill('input[name="date_of_birth"]', '1985-06-15');
    await page.fill('input[name="email"]',         'jsmith@karate.test');
    await page.fill('input[name="street_address"]', TEST_STREET);
    await page.fill('input[name="city_state_zip"]', TEST_CSZ);
    await page.click('button:has-text("Save Profile")');
    await page.waitForLoadState('domcontentloaded');
    // Should redirect to student dashboard with ?saved=1
    expect(page.url()).toContain('index.php?saved=1');
    await expect(page.locator('.alert-success').first()).toContainText('Profile saved successfully');
});

test('address fields persist after save — pre-fill on reload', async ({ page }) => {
    await page.goto(BASE + '/student/profile_edit.php');
    await assertNoPhpErrors(page, 'profile edit after save');
    await expect(page.locator('input[name="street_address"]')).toHaveValue(TEST_STREET);
    await expect(page.locator('input[name="city_state_zip"]')).toHaveValue(TEST_CSZ);
});

test('clearing address fields and saving persists empty values', async ({ page }) => {
    await page.goto(BASE + '/student/profile_edit.php');
    // Ensure required fields are filled so the form passes server-side validation
    await page.fill('input[name="first_name"]',    'John');
    await page.fill('input[name="last_name"]',     'Smith');
    await page.fill('input[name="date_of_birth"]', '1985-06-15');
    await page.fill('input[name="email"]',         'jsmith@karate.test');
    await page.fill('input[name="street_address"]', '');
    await page.fill('input[name="city_state_zip"]', '');
    await page.click('button:has-text("Save Profile")');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('index.php?saved=1');
});

// ── PASSWORD CHANGE CARD ──────────────────────────────────────────────────────

test('Change Password card is visible', async ({ page }) => {
    await page.goto(BASE + '/student/profile_edit.php');
    await expect(page.locator('.card-header').filter({ hasText: 'Change Password' })).toBeVisible();
});

test('wrong current password shows error', async ({ page }) => {
    await page.goto(BASE + '/student/profile_edit.php');
    // Expand the Bootstrap collapse — wait for the input to be visible rather than a fixed delay
    await page.click('button:has-text("Change Password")');
    await page.locator('#changePasswordForm input[name="current_password"]').waitFor({ state: 'visible' });
    await page.fill('input[name="current_password"]', 'wrongpassword');
    await page.fill('input[name="new_password"]',     'NewPass123!');
    await page.fill('input[name="confirm_password"]', 'NewPass123!');
    await page.click('button:has-text("Update Password")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger').first()).toContainText('incorrect');
});

// ── ACCESS CONTROL ────────────────────────────────────────────────────────────

test('profile_edit.php requires login', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto(BASE + '/student/profile_edit.php');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('login.php');
});
