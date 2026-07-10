// @ts-check
// Tests for admin/donations.php
const { test, expect } = require('@playwright/test');
const { visit, assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

test.describe('Donations page', () => {
    test.use({ storageState: AUTH.admin });

    test('page loads without errors for admin', async ({ page }) => {
        await visit(page, '/admin/donations.php', 'donations page');
    });

    test.describe('student access', () => {
        test.use({ storageState: AUTH.student });
        test('student cannot access donations page', async ({ page }) => {
            await page.goto(BASE + '/admin/donations.php');
            // require_role() sends 200 with "Access denied" body (not a 403 redirect)
            const body = await page.textContent('body');
            expect(body).toContain('Access denied');
        });
    });

    test('+ Record Donation button toggles form', async ({ page }) => {
        await page.goto(BASE + '/admin/donations.php');
        await page.click('button:has-text("+ Record Donation")');
        await expect(page.locator('#addDonationForm')).toBeVisible();
    });

    test('donation form has amount, method, date, donor, notes fields', async ({ page }) => {
        await page.goto(BASE + '/admin/donations.php');
        await page.click('button:has-text("+ Record Donation")');
        await expect(page.locator('input[name="amount"]')).toBeVisible();
        await expect(page.locator('select[name="payment_method"]')).toBeVisible();
        await expect(page.locator('input[name="payment_date"]')).toBeVisible();
        await expect(page.locator('input[name="donor_name"]')).toBeVisible();
        await expect(page.locator('input[name="notes"]')).toBeVisible();
    });

    test('recording a donation and then deleting it', async ({ page }) => {
        await page.goto(BASE + '/admin/donations.php');
        await page.click('button:has-text("+ Record Donation")');
        await page.fill('input[name="amount"]', '25.00');
        await page.selectOption('select[name="payment_method"]', 'cash');
        await page.fill('input[name="payment_date"]', new Date().toISOString().slice(0, 10));
        await page.fill('input[name="donor_name"]', 'Test Donor Delete');
        await page.click('button:has-text("Save Donation")');
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'after donation save');
        await expect(page.locator('.alert-success').first()).toBeVisible();

        // Delete in edit mode — toggle is always present after a donation is saved
        await page.click('#editToggle');
        const row = page.locator('tr').filter({ hasText: 'Test Donor Delete' });
        page.once('dialog', d => d.accept());
        await row.locator('button.btn-outline-danger').first().click();
        await page.waitForLoadState('domcontentloaded');
    });

    test('donations page is in admin nav dropdown under Finances', async ({ page }) => {
        await page.goto(BASE + '/admin/');
        // Open the Admin dropdown so its items are visible
        await page.click('.navbar .dropdown-toggle:has-text("Admin")');
        const link = page.locator('a.dropdown-item:has-text("Donations")');
        await expect(link).toBeVisible();
        const href = await link.getAttribute('href');
        expect(href).toContain('donations.php');
    });

});
