// @ts-check
// Tests for checkin.php (public PIN gate + self check-in UI) and admin/checkin_pin.php.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

test.describe.configure({ mode: 'serial' });

// ── PIN GATE (public, no auth) ────────────────────────────────────────────────

test.describe('checkin PIN gate', () => {
    // No storageState — checkin.php is public

    test('checkin page renders PIN entry form', async ({ page }) => {
        await page.goto(BASE + '/checkin.php');
        await expect(page.locator('input[name="pin"]')).toBeVisible();
        await expect(page.locator('button:has-text("Enter")')).toBeVisible();
        // Title or heading contains "Check"
        const body = await page.textContent('body');
        expect(body).toMatch(/check.?in/i);
    });

    test('checkin page shows no PHP errors', async ({ page }) => {
        await page.goto(BASE + '/checkin.php');
        const body = await page.textContent('body');
        expect(body).not.toContain('Fatal error');
        expect(body).not.toContain('Parse error');
        expect(body).not.toContain('Warning:');
    });

    test('wrong PIN shows Incorrect PIN error', async ({ page }) => {
        await page.goto(BASE + '/checkin.php');
        await page.fill('input[name="pin"]', '0000');
        await page.click('button:has-text("Enter")');
        await page.waitForLoadState('domcontentloaded');
        const body = await page.textContent('body');
        // Either "Incorrect PIN" or "Too many attempts" (if rate limited)
        expect(body).toMatch(/Incorrect PIN|Too many attempts/);
    });

    test('wrong PIN does not grant access to check-in UI', async ({ page }) => {
        await page.goto(BASE + '/checkin.php');
        await page.fill('input[name="pin"]', '0000');
        await page.click('button:has-text("Enter")');
        await page.waitForLoadState('domcontentloaded');
        // PIN gate should still be showing (no name-filter input)
        const nameFilter = page.locator('#nameFilter');
        await expect(nameFilter).toBeHidden();
    });

    test('checkin page today date is shown in heading', async ({ page }) => {
        await page.goto(BASE + '/checkin.php');
        const body = await page.textContent('body');
        const year = new Date().getFullYear().toString();
        expect(body).toContain(year);
    });
});

// ── ADMIN checkin_pin.php ─────────────────────────────────────────────────────

test.describe('admin checkin_pin.php', () => {
    test.use({ storageState: AUTH.admin });

    test('checkin_pin.php loads without errors', async ({ page }) => {
        await page.goto(BASE + '/admin/checkin_pin.php');
        await assertNoPhpErrors(page, 'checkin_pin admin');
    });

    test('checkin_pin.php shows current PIN and change form', async ({ page }) => {
        await page.goto(BASE + '/admin/checkin_pin.php');
        // Current PIN section
        await expect(page.locator('input[name="pin"]')).toBeVisible();
        await expect(page.locator('button:has-text("Update PIN")')).toBeVisible();
        // The large PIN display (fs-1 fw-bold)
        await expect(page.locator('.fs-1.fw-bold')).toBeVisible();
    });

    test('checkin_pin.php links to the activity log for failed PIN attempts', async ({ page }) => {
        await page.goto(BASE + '/admin/checkin_pin.php');
        const link = page.locator('a[href*="logs.php?channel=checkin"]');
        await expect(link).toBeVisible();
    });

    test('updating PIN shows success message', async ({ page }) => {
        await page.goto(BASE + '/admin/checkin_pin.php');
        // Read current PIN so we can restore it
        const currentPin = await page.locator('.fs-1.fw-bold').textContent();
        // Set a known test PIN
        await page.fill('input[name="pin"]', '9999');
        await page.click('button:has-text("Update PIN")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-success:not(.d-none)').first()).toContainText('PIN updated');
        // Restore original PIN
        await page.fill('input[name="pin"]', (currentPin ?? '1865').trim());
        await page.click('button:has-text("Update PIN")');
        await page.waitForLoadState('domcontentloaded');
    });

    test('submitting empty PIN shows error', async ({ page }) => {
        await page.goto(BASE + '/admin/checkin_pin.php');
        await page.fill('input[name="pin"]', '');
        // Remove required attribute so form submits
        await page.evaluate(() => document.querySelector('input[name="pin"]')?.removeAttribute('required'));
        await page.click('button:has-text("Update PIN")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-danger').first()).toContainText('PIN cannot be empty');
    });
});

// ── ACCESS CONTROL ────────────────────────────────────────────────────────────

test.describe('checkin_pin access control', () => {
    test.use({ storageState: AUTH.instructor });
    test('instructor cannot access checkin_pin.php', async ({ page }) => {
        await page.goto(BASE + '/admin/checkin_pin.php');
        // Should redirect to login or show access denied
        const url = page.url();
        const body = await page.textContent('body');
        const blocked = url.includes('login.php') || body.includes('Access denied') || body.includes('403');
        expect(blocked).toBe(true);
    });
});
