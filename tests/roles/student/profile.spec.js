// @ts-check
// Tests for student profile save: ?saved=1 flash banner and address field persistence.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

test.describe.configure({ mode: 'serial' });
test.use({ storageState: AUTH.student });

const TS          = Date.now();
const TEST_STREET = `999 Test Lane ${TS}`;
const TEST_CSZ    = 'Provo, UT 84601';

// â”€â”€ PROFILE SAVE FLASH â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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

// â”€â”€ PROFILE EDIT FORM â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('profile_edit.php loads and shows address fields', async ({ page }) => {
    await page.goto(BASE + '/student/profile_edit.php');
    await assertNoPhpErrors(page, 'profile edit loads');
    await expect(page.locator('input[name="street_address"]')).toBeVisible();
    await expect(page.locator('input[name="city_state_zip"]')).toBeVisible();
    await expect(page.locator('input[name="first_name"]')).toBeVisible();
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('button:has-text("Save Profile")')).toBeVisible();
});

// â”€â”€ ADDRESS FIELD PERSISTENCE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('saving profile with address fields redirects to dashboard with success banner', async ({ page }) => {
    await page.goto(BASE + '/student/profile_edit.php');
    // Fill ALL required fields explicitly â€” don't rely on jsmith's DB values being non-null.
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

test('address fields persist after save â€” pre-fill on reload', async ({ page }) => {
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

// â”€â”€ PASSWORD CHANGE CARD â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('Change Password card is visible', async ({ page }) => {
    await page.goto(BASE + '/student/profile_edit.php');
    await expect(page.locator('.card-header').filter({ hasText: 'Change Password' })).toBeVisible();
});

test('wrong current password shows error', async ({ page }) => {
    await page.goto(BASE + '/student/profile_edit.php');
    // Expand the Bootstrap collapse â€” wait for the input to be visible rather than a fixed delay
    await page.click('button:has-text("Change Password")');
    await page.locator('#changePasswordForm input[name="current_password"]').waitFor({ state: 'visible' });
    await page.fill('input[name="current_password"]', 'wrongpassword');
    await page.fill('input[name="new_password"]',     'NewPass123!');
    await page.fill('input[name="confirm_password"]', 'NewPass123!');
    await page.click('button:has-text("Update Password")');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger').first()).toContainText('incorrect');
});

// ── UNIFORM & BELT SIZE ───────────────────────────────────────────────────────

// Note: selects for uniform/belt size are always in the DOM on profile_edit.php.
// We use page.evaluate() for these checks — Playwright's locator API sometimes
// returns 0 for elements below the visible viewport.

test('profile_edit.php has Uniform Size and Belt Size selects', async ({ page }) => {
    await page.goto(BASE + '/student/profile_edit.php');
    const counts = await page.evaluate(() => {
        const all = Array.from(document.querySelectorAll('select'));
        return {
            uniform: all.filter(s => s.name === 'uniform_size').length,
            belt:    all.filter(s => s.name === 'belt_size').length,
        };
    });
    expect(counts.uniform).toBe(1);
    expect(counts.belt).toBe(1);
});

test('Uniform Size select has full range 000-8', async ({ page }) => {
    await page.goto(BASE + '/student/profile_edit.php');
    const vals = await page.evaluate(() => {
        const sel = Array.from(document.querySelectorAll('select')).find(s => s.name === 'uniform_size');
        return sel ? Array.from(sel.options).map(o => o.textContent.trim()) : [];
    });
    for (const sz of ['000','00','0','1','2','3','4','5','6','7','8']) {
        expect(vals).toContain(sz);
    }
});

test('Belt Size select has sizes 2-8', async ({ page }) => {
    await page.goto(BASE + '/student/profile_edit.php');
    const vals = await page.evaluate(() => {
        const sel = Array.from(document.querySelectorAll('select')).find(s => s.name === 'belt_size');
        return sel ? Array.from(sel.options).map(o => o.textContent.trim()) : [];
    });
    for (const sz of ['2','3','4','5','6','7','8']) {
        expect(vals).toContain(sz);
    }
});

test('saving uniform and belt size persists on reload', async ({ page }) => {
    await page.goto(BASE + '/student/profile_edit.php');
    // Set all required fields and selects, then submit the form directly via
    // page.evaluate() — avoids Playwright fill/click issues in serial mode.
    await page.evaluate(() => {
        // Use .name property comparison — attribute selectors unreliable in headless Chrome
        const inputs = Array.from(document.querySelectorAll('input, textarea'));
        const setInput = (name, val) => { const el = inputs.find(i => i.name === name); if (el) el.value = val; };
        setInput('first_name',    'John');
        setInput('last_name',     'Smith');
        setInput('date_of_birth', '1985-06-15');
        setInput('email',         'jsmith@karate.test');
        const selects = Array.from(document.querySelectorAll('select'));
        const u = selects.find(s => s.name === 'uniform_size');
        const b = selects.find(s => s.name === 'belt_size');
        if (u) u.value = '3';
        if (b) b.value = '4';
        // Submit the first form (profile form) directly
        document.querySelector('form').submit();
    });
    // Wait for the PHP redirect to the success page — the POST+302 redirect
    // means domcontentloaded may fire at the intermediate profile_edit.php URL.
    await page.waitForURL(/saved=1/, { timeout: 10000 });
    expect(page.url()).toContain('?saved=1');
    await page.goto(BASE + '/student/profile_edit.php');
    const saved = await page.evaluate(() => {
        const all = Array.from(document.querySelectorAll('select'));
        return {
            uniform: (all.find(s => s.name === 'uniform_size') || {}).value,
            belt:    (all.find(s => s.name === 'belt_size')    || {}).value,
        };
    });
    expect(saved.uniform).toBe('3');
    expect(saved.belt).toBe('4');
});

// â”€â”€ ACCESS CONTROL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('profile_edit.php requires login', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto(BASE + '/student/profile_edit.php');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('login.php');
});
