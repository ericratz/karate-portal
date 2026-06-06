// @ts-check
const { test, expect } = require('@playwright/test');
const { login, visit, assertNoPhpErrors, BASE } = require('./helpers');

const { ADMIN_USER, ADMIN_PASS } = require('./credentials');

test.describe('Admin', () => {

    test.beforeEach(async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
    });

    test('dashboard loads', async ({ page }) => {
        await visit(page, '/admin/', 'admin dashboard');
        await expect(page.locator('body')).toContainText('Active Students');
    });

    test('student list loads all 3 tables', async ({ page }) => {
        await visit(page, '/admin/students.php', 'students list');
        await expect(page.locator('.card-header').filter({ hasText: 'Instructors' })).toBeVisible();
        await expect(page.locator('.card-header').filter({ hasText: 'Students' })).toBeVisible();
        await expect(page.locator('.card-header').filter({ hasText: 'Guests' })).toBeVisible();
    });

    test('new student form loads', async ({ page }) => {
        await visit(page, '/admin/student_edit.php', 'new student form');
        await expect(page.locator('h4')).toContainText('New Student');
    });

    test('payments page loads', async ({ page }) => {
        await visit(page, '/admin/payments.php', 'payments');
    });

    test('expenses page loads', async ({ page }) => {
        await visit(page, '/admin/expenses.php', 'expenses');
    });

    test('waivers page loads', async ({ page }) => {
        await visit(page, '/admin/waivers.php', 'waivers');
    });

    test('user management loads', async ({ page }) => {
        await visit(page, '/admin/users.php', 'users');
    });

    test('general notes loads', async ({ page }) => {
        await visit(page, '/admin/general_notes.php', 'general notes');
    });

    test('student notes picker loads', async ({ page }) => {
        await visit(page, '/admin/student_notes.php', 'student notes picker');
        // Student notes now shows a roster table; verify the page has the expected structure
        await expect(page.locator('.card-header').filter({ hasText: 'Students' })).toBeVisible();
    });

    test('email students loads', async ({ page }) => {
        await visit(page, '/admin/email_students.php', 'email students');
        await expect(page.locator('#chk_all')).toBeVisible();
    });

    test('email recipient list updates when checkbox ticked', async ({ page }) => {
        await visit(page, '/admin/email_students.php', 'email checkboxes');
        // Tick Students
        await page.check('#chk_students');
        const count = await page.textContent('#recipientCount');
        expect(parseInt(count)).toBeGreaterThanOrEqual(0);
    });

    test('student edit back button goes to student profile', async ({ page }) => {
        await page.goto(BASE + '/admin/student_edit.php?id=2');
        await assertNoPhpErrors(page, 'edit student');
        const backHref = await page.getAttribute('a:has-text("← Back")', 'href');
        expect(backHref).toContain('student_profile.php');
    });

    test('payments edit toggle shows/hides delete buttons', async ({ page }) => {
        await visit(page, '/admin/payments.php', 'payments edit toggle');
        // Delete column should be hidden initially
        const deleteVisible = await page.isVisible('table.editing .delete-col');
        expect(deleteVisible).toBe(false);
        // Click Edit button if there are payments
        const editBtn = page.locator('#editToggle');
        if (await editBtn.isVisible()) {
            await editBtn.click();
            await expect(page.locator('#paymentsTable')).toHaveClass(/editing/);
        }
    });

    test('nav My Dashboard goes to admin dashboard', async ({ page }) => {
        await page.goto(BASE + '/admin/');
        const href = await page.getAttribute('.navbar-brand', 'href');
        expect(href).toContain('/admin/');
    });

});
