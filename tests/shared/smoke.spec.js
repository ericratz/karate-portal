// @ts-check
// Smoke tests — each role's pages load without PHP errors or HTTP 4xx.
const { test, expect } = require('@playwright/test');
const { visit, assertNoPhpErrors, BASE, AUTH } = require('../helpers');

test.describe('admin smoke', () => {
    test.use({ storageState: AUTH.admin });

    test('admin pages load without errors', async ({ page }) => {
        const pages = [
            ['/admin/',                            'admin dashboard'],
            ['/admin/students.php',                'students list'],
            ['/admin/student_edit.php',            'new student form'],
            ['/admin/payments.php',                'payments'],
            ['/admin/expenses.php',                'expenses'],
            ['/admin/waivers.php',                 'waivers'],
            ['/admin/users.php',                   'users'],
            ['/admin/student_notes.php',           'class notes'],
            ['/admin/email_students.php',          'email students'],
            ['/admin/donations.php',               'donations'],
            ['/admin/audit_log.php',               'audit log'],
            ['/admin/logs.php',                    'combined logs'],
            ['/admin/email_log.php',               'email log'],
            ['/admin/checkin_pin.php',             'checkin pin admin'],
            ['/admin/member_card.php?student_id=2','member card'],
            ['/admin/certificate.php?student_id=2&rank_id=3', 'certificate'], // rank_id=3 (8th Kyu) is real for student 2 — an invalid combo would silently redirect instead of testing the page
        ];
        for (const [path, label] of pages) await visit(page, path, label);
        // app_log.php intentionally displays error log entries that may contain
        // "Uncaught", "Warning:", etc. — check HTTP status only, not page content.
        const res = await page.goto(BASE + '/admin/app_log.php', { waitUntil: 'domcontentloaded' });
        if (res.status() >= 400) throw new Error(`HTTP ${res.status()} on /admin/app_log.php`);
    });

    test('admin dashboard shows key cards and student list has 4 tables', async ({ page }) => {
        await page.goto(BASE + '/admin/');
        await expect(page.locator('body')).toContainText('Active Students');
        await expect(page.locator('.card-header').filter({ hasText: 'Tuition Unpaid' })).toBeVisible();
        await expect(page.locator('.card-header').filter({ hasText: 'Missing Waivers' })).toBeVisible();
        await expect(page.locator('.card-header').filter({ hasText: 'Recent Payments' })).toBeVisible();
        await page.goto(BASE + '/admin/students.php');
        for (const h of ['Instructors','Parents','Students','Guests']) {
            await expect(page.locator('.card-header').filter({ hasText: h })).toBeVisible();
        }
    });

    // V3.3: admin/certificate.php generates a printable certificate with student
    // name, rank, and cert number. Student 2 (Sarah Johnson) really has rank_id=3
    // (8th Kyu, cert_number SKSD-1003) on record — a functional check beyond the
    // generic "loads without error" smoke test above.
    test('certificate.php shows student name, rank, cert number, and download button', async ({ page }) => {
        await page.goto(BASE + '/admin/certificate.php?student_id=2&rank_id=3');
        await assertNoPhpErrors(page, 'certificate content');
        await expect(page.locator('.name-field')).toContainText('Sarah Johnson');
        await expect(page.locator('.number-value')).toContainText('SKSD-1003');
        await expect(page.locator('#downloadBtn')).toBeVisible();
    });

    test('certificate.php redirects for a rank the student was never awarded', async ({ page }) => {
        // rank_id=99999 doesn't exist in the ranks table at all (max real id is 13),
        // so this is unambiguous regardless of what other parallel tests award
        // student 2 in the meantime — rank_id=1 (10th Kyu) was a bad choice here
        // since instructor/belt_tests.spec.js's AutoRank test awards that exact
        // rank to this exact student concurrently in another worker.
        await page.goto(BASE + '/admin/certificate.php?student_id=2&rank_id=99999');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).not.toContain('certificate.php');
    });

    test('admin student edit page loads and nav works', async ({ page }) => {
        await page.goto(BASE + '/admin/student_edit.php');
        await expect(page.locator('h4')).toContainText('New Student');
        await page.goto(BASE + '/admin/student_edit.php?id=2');
        await assertNoPhpErrors(page, 'edit student');
        await expect(page.locator('h4').first()).toContainText('Edit');
        // Nav brand is the SPA hash-router admin home link now
        expect(await page.getAttribute('.navbar-brand', 'href')).toContain('/admin');
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
        // SPA page — wait for the results card to render first
        await expect(page.locator('#payments-results')).toBeVisible();
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
            ['/instructor/student_profile.php?id=2', 'student profile'],
        ];
        for (const [path, label] of pages) await visit(page, path, label);
        // SPA hash-router brand link points at the instructor home route
        expect(await page.getAttribute('.navbar-brand', 'href')).toContain('instructor');
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
        // Record New Class submits in-app (no action= stub); the button is present.
        await expect(page.locator('button:has-text("Record New Class")')).toBeVisible();
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
        // SPA renders after the API fetch — toContainText retries until then
        await expect(page.locator('body')).toContainText('Classes Attended');
        await expect(page.locator('body')).toContainText('Current Rank');
        await expect(page.locator('body')).toContainText('Recent Payments');
        // Nav brand is the SPA hash-router home link
        const href = await page.getAttribute('.navbar-brand', 'href');
        expect(href).toContain('#/');
    });
});

test.describe('public checkin smoke', () => {
    // checkin.php is unauthenticated — no storageState needed

    test('checkin.php loads and shows PIN gate', async ({ page }) => {
        await page.goto(BASE + '/checkin.php');
        // Page should render (no PHP fatal errors) and show PIN entry UI
        const body = await page.textContent('body');
        expect(body).not.toContain('Fatal error');
        expect(body).not.toContain('Parse error');
        await expect(page.locator('input[name="pin"]')).toBeVisible();
        await expect(page.locator('button:has-text("Enter")')).toBeVisible();
    });
});

test.describe('parent smoke', () => {
    test.use({ storageState: AUTH.parent });

    test('parent pages load without errors', async ({ page }) => {
        // Use child id=4 (Emily) for sub-pages that require ?student_id=
        const pages = [
            ['/parent/',                                        'parent dashboard'],
            ['/parent/?student_id=4',                          'parent dashboard Emily tab'],
            ['/parent/belt_tests.php?student_id=4',            'parent belt tests'],
            ['/parent/attendance.php?student_id=4',            'parent attendance'],
            ['/parent/pay.php',                                 'parent pay'],
            ['/parent/payment_history.php?student_id=4',       'parent payment history'],
            ['/parent/profile_edit.php?student_id=6',          'parent profile edit'],
        ];
        for (const [path, label] of pages) await visit(page, path, label);
    });
});
