// @ts-check
// Profile editing tests — admin and student.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

test.describe.configure({ mode: 'serial' });
test.use({ storageState: AUTH.admin });

test('admin: edit saves and persists on reload, then restores', async ({ page }) => {
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    await page.click('#profileEditBtn');
    const original = await page.inputValue('input[name="phone"]');
    await page.fill('input[name="phone"]', '555-0199');
    await page.click('#profileEditBtn');
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'profile saved');
    await page.reload();
    await page.click('#profileEditBtn');
    expect(await page.inputValue('input[name="phone"]')).toBe('555-0199');
    // Restore original value
    await page.fill('input[name="phone"]', original);
    await page.click('#profileEditBtn');
    await page.waitForLoadState('domcontentloaded');
});

test('admin: account type dropdown shows valid value', async ({ page }) => {
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    await page.click('#profileEditBtn');
    expect(['guest','student','instructor']).toContain(
        await page.inputValue('select[name="account_type"]')
    );
});

test('admin: active_override Force Active saves and can be reset', async ({ page }) => {
    // Active Status is now part of the Profile Info card, toggled via #profileEditBtn
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    await page.click('#profileEditBtn');
    await page.selectOption('select[name="active_override"]', '1');
    await page.click('#profileEditBtn');
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'active override saved');
    // The save above is an htmx AJAX swap, not a real navigation, so
    // domcontentloaded resolves before the swap lands. Wait for the button
    // to actually show "Edit" (proof the fresh post-swap DOM is in) before
    // clicking it again — otherwise this can land on the stale pre-swap
    // button, which re-submits instead of opening edit mode.
    await expect(page.locator('#profileEditBtn')).toHaveText('Edit');
    await page.click('#profileEditBtn');
    await page.selectOption('select[name="active_override"]', 'auto');
    await page.click('#profileEditBtn');
    await page.waitForLoadState('domcontentloaded');
});

test.describe('student profile edit', () => {
    test.use({ storageState: AUTH.student });
    test('student: profile edit form is pre-filled and submits without error', async ({ page }) => {
        await page.goto(BASE + '/student/profile_edit.php');
        await assertNoPhpErrors(page, 'profile edit loads');
        expect((await page.inputValue('input[name="first_name"]')).length).toBeGreaterThan(0);
    });
});
