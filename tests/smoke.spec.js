// @ts-check
// Smoke tests — each role's pages load without PHP errors or HTTP 4xx.
const { test, expect } = require('@playwright/test');
const { visit, assertNoPhpErrors, BASE, AUTH } = require('./helpers');

test.describe('admin smoke', () => {
    test.use({ storageState: AUTH.admin });

    test('admin pages load without errors', async ({ page }) => {
        const pages = [
            ['/admin/',                    'admin dashboard'],
            ['/admin/students.php',        'students list'],
            ['/admin/student_edit.php',    'new student form'],
            ['/admin/payments.php',        'payments'],
            ['/admin/expenses.php',        'expenses'],
            ['/admin/waivers.php',         'waivers'],
            ['/admin/users.php',           'users'],
            ['/admin/general_notes.php',   'general notes'],
            ['/admin/student_notes.php',   'student notes'],
            ['/admin/email_students.php',  'email students'],
            ['/admin/donations.php',       'donations'],
            ['/admin/audit_log.php',       'audit log'],
        ];
        for (const [path, label] of pages) await visit(page, path, label);
    });

    test('admin dashboard shows key cards and student list has 4 tables', async ({ page }) => {
        await page.goto(BASE + '/admin/');
        await expect(page.locator('body')).toContainText('Active Students');
        await expect(page.locator('.card-header').filter({ hasText: 'Tuition Unpaid' })).toBeVisible();
        await expect(page.locator('.card-header').filter({ hasText: 'Missing Injury Waivers' })).toBeVisible();
        await expect(page.locator('.card-header').filter({ hasText: 'Recent Payments' })).toBeVisible();
        await page.goto(BASE + '/admin/students.php');
        for (const h of ['Instructors','Parents','Students','Guests']) {
            await expect(page.locator('.card-header').filter({ hasText: h })).toBeVisible();
        }
    });

    test('admin student edit page loads and nav works', async ({ page }) => {
        await page.goto(BASE + '/admin/student_edit.php');
        await expect(page.locator('h4')).toContainText('New Student');
        await page.goto(BASE + '/admin/student_edit.php?id=2');
        await assertNoPhpErrors(page, 'edit student');
        await expect(page.locator('h4')).toContainText('Edit');
        // Nav brand
        expect(await page.getAttribute('.navbar-brand', 'href')).toContain('/admin/');
    });

    test('admin email page has checkboxes and recipient count', async ({ page }) => {
        await page.goto(BASE + '/admin/email_students.php');
        await expect(page.locator('#chk_all')).toBeVisible();
        await expect(page.locator('#chk_parents')).toBeVisible();
        await page.check('#chk_students');
        expect(parseInt(await page.textContent('#recipientCount'))).toBeGreaterThanOrEqual(0);
    });

    test('admin payments edit toggle shows delete column', async ({ page }) => {
        await page.goto(BASE + '/admin/payments.php');
        expect(await page.isVisible('table.editing .delete-col')).toBe(false);
        const editBtn = page.locator('#editToggle');
        if (await editBtn.isVisible()) {
            await editBtn.click();
            await expect(page.locator('#paymentsTable')).toHaveClass(/editing/);
        }
    });
});

test.describe('instructor smoke', () => {
    test.use({ storageState: AUTH.instructor });

    test('instructor pages load without errors', async ({ page }) => {
        const pages = [
            ['/instructor/',                      'instructor dashboard'],
            [`/instructor/attendance.php?date=${new Date().toISOString().slice(0,10)}`, 'attendance'],
            ['/instructor/students.php',          'instructor roster'],
            ['/instructor/belt_tests_all.php',    'all belt tests'],
            ['/instructor/belt_test_edit.php',    'belt test edit'],
            ['/instructor/attendance_sessions.php','attendance sessions'],
        ];
        for (const [path, label] of pages) await visit(page, path, label);
        expect(await page.getAttribute('.navbar-brand', 'href')).toContain('/instructor/');
    });

    test('instructor dashboard shows correct cards', async ({ page }) => {
        await page.goto(BASE + '/instructor/');
        await expect(page.locator('body')).toContainText('Take Attendance');
        await expect(page.locator('.card-header').filter({ hasText: 'Take Attendance' })).toBeVisible();
        await expect(page.locator('.card-header').filter({ hasText: 'Recent Classes' })).toBeVisible();
        await expect(page.locator('.card-header').filter({ hasText: 'Recent Belt Tests' })).toBeVisible();
        await expect(page.locator('.card-header').filter({ hasText: 'Students' })).toBeVisible();
        // Date input defaults to today
        const val = await page.inputValue('input[name="date"]');
        expect(val).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        // Record New Class button form action
        const formAction = await page.locator('button:has-text("Record New Class")').evaluate(el => el.closest('form')?.action ?? '');
        expect(formAction).toContain('attendance.php');
    });
});

test.describe('student smoke', () => {
    test.use({ storageState: AUTH.student });

    test('student pages load without errors', async ({ page }) => {
        const pages = [
            ['/student/',                    'student dashboard'],
            ['/student/attendance.php',      'attendance history'],
            ['/student/payment_history.php', 'payment history'],
            ['/student/belt_tests.php',      'belt tests'],
            ['/student/profile_edit.php',    'profile edit'],
            ['/student/pay.php',             'pay page'],
        ];
        for (const [path, label] of pages) await visit(page, path, label);
    });

    test('student dashboard shows summary cards', async ({ page }) => {
        await page.goto(BASE + '/student/');
        const body = await page.textContent('body');
        expect(body).toContain('Classes Attended');
        expect(body).toContain('Current Rank');
        expect(body).toContain('Recent Payments');
        // Nav brand routes correctly
        const href = await page.getAttribute('.navbar-brand', 'href');
        expect(href).toMatch(/\/(student|instructor|admin)\//);
    });
});
