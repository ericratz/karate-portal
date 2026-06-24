// @ts-check
// Tests for admin/user_profile.php:
// card toggle (edit/cancel), first/last name fields, password card, status card.
const { test, expect } = require('@playwright/test');
const { visit, assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

// Helper: navigate to user_profile.php for the first non-admin user
async function goToNonAdminProfile(page) {
    await page.goto(BASE + '/admin/users.php');
    const nonAdminRow = page.locator('tbody tr').filter({
        hasNot: page.locator('span.badge.bg-danger')
    }).first();
    if (await nonAdminRow.count() === 0) return false;
    await nonAdminRow.locator('a:has-text("View")').click();
    await page.waitForLoadState('domcontentloaded');
    return true;
}

// Helper: navigate to user_profile.php for the first user (any role)
async function goToAnyProfile(page) {
    await page.goto(BASE + '/admin/users.php');
    await page.locator('a:has-text("View")').first().click();
    await page.waitForLoadState('domcontentloaded');
}

test.describe('User Profile (admin/user_profile.php)', () => {
    test.describe.configure({ mode: 'serial' });
    test.use({ storageState: AUTH.admin });

    test('page loads without PHP errors', async ({ page }) => {
        await goToAnyProfile(page);
        await assertNoPhpErrors(page, 'user profile');
    });

    test('page has Account Details card', async ({ page }) => {
        await goToAnyProfile(page);
        await expect(page.locator('.card-header').filter({ hasText: 'Account Details' })).toBeVisible();
    });

    test('account details view shows username, role, created date', async ({ page }) => {
        await goToAnyProfile(page);
        const body = await page.textContent('body');
        expect(body).toMatch(/Username|username/);
        expect(body).toMatch(/Account Created/);
        expect(body).toMatch(/Role/);
    });

    test('Edit button on Account Details card reveals edit form', async ({ page }) => {
        await goToAnyProfile(page);
        await expect(page.locator('#account-edit')).toBeHidden();
        await page.locator('#accountEditBtn').click({ force: true });
        await expect(page.locator('#account-edit')).toBeVisible();
        await expect(page.locator('#account-view')).toBeHidden();
    });

    test('edit form has first_name, last_name, username, email, is_admin checkbox', async ({ page }) => {
        await goToAnyProfile(page);
        await page.locator('#accountEditBtn').click({ force: true });
        await expect(page.locator('input[name="first_name"]')).toBeVisible();
        await expect(page.locator('input[name="last_name"]')).toBeVisible();
        await expect(page.locator('input[name="username"]')).toBeVisible();
        await expect(page.locator('input[name="email"]')).toBeVisible();
        await expect(page.locator('input[name="is_admin"]')).toBeVisible();
    });

    test('Cancel button returns to view mode', async ({ page }) => {
        await goToAnyProfile(page);
        await page.locator('#accountEditBtn').click({ force: true });
        await expect(page.locator('#account-edit')).toBeVisible();
        await page.locator('#accountCancelBtn').click();
        await expect(page.locator('#account-view')).toBeVisible();
        await expect(page.locator('#account-edit')).toBeHidden();
        // Button should say Edit again
        await expect(page.locator('#accountEditBtn')).toHaveText('Edit');
    });

    test('Edit button changes to Confirm after click', async ({ page }) => {
        await goToAnyProfile(page);
        await page.locator('#accountEditBtn').click({ force: true });
        await expect(page.locator('#accountEditBtn')).toHaveText('Confirm');
    });

    test('is_admin checkbox controls admin access (role is derived, not a select)', async ({ page }) => {
        await goToAnyProfile(page);
        await page.locator('#accountEditBtn').click({ force: true });
        // Role is now derived from is_admin + student_type; the UI shows a checkbox, not a select
        const chk = page.locator('input[name="is_admin"][type="checkbox"]');
        await expect(chk).toBeVisible();
    });

    test('Linked Roster Entry card is visible', async ({ page }) => {
        await goToAnyProfile(page);
        await expect(page.locator('.card-header').filter({ hasText: 'Linked Roster Entry' })).toBeVisible();
    });

    test('Password card has a Change button', async ({ page }) => {
        await goToAnyProfile(page);
        await expect(page.locator('.card-header').filter({ hasText: 'Password' })).toBeVisible();
        await expect(page.locator('button:has-text("Change")')).toBeVisible();
    });

    test('Change button expands password reset form', async ({ page }) => {
        await goToAnyProfile(page);
        await expect(page.locator('#pwResetBox')).toBeHidden();
        await page.locator('button:has-text("Change")').click();
        await expect(page.locator('#pwResetBox')).toBeVisible();
        await expect(page.locator('input[name="new_password"]')).toBeVisible();
    });

    test('Account Status card visible for non-current-user profiles', async ({ page }) => {
        const found = await goToNonAdminProfile(page);
        if (!found) return;
        await expect(page.locator('.card-header').filter({ hasText: 'Account Status' })).toBeVisible();
    });

    test('Deactivate or Activate button is present on status card', async ({ page }) => {
        const found = await goToNonAdminProfile(page);
        if (!found) return;
        const btn = page.locator('button:has-text("Deactivate"), button:has-text("Activate")').last();
        await expect(btn).toBeVisible();
    });

    test('msg=saved shows success alert', async ({ page }) => {
        await goToAnyProfile(page);
        const url = page.url();
        await page.goto(url + (url.includes('?') ? '&' : '?') + 'msg=saved');
        await expect(page.locator('.alert-success:not(.d-none)')).toContainText('Changes saved');
    });
});
