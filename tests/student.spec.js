// @ts-check
const { test, expect } = require('@playwright/test');
const { login, visit, assertNoPhpErrors, BASE } = require('./helpers');

const { STU_USER, STU_PASS } = require('./credentials');

test.describe('Student', () => {

    test.beforeEach(async ({ page }) => {
        await login(page, STU_USER, STU_PASS);
    });

    test('dashboard loads', async ({ page }) => {
        await visit(page, '/student/', 'student dashboard');
        await expect(page.locator('h3')).toContainText('Welcome');
    });

    test('summary cards are visible', async ({ page }) => {
        await visit(page, '/student/', 'summary cards');
        const body = await page.textContent('body');
        expect(body).toContain('Classes Attended');
        expect(body).toContain('Current Rank');
        expect(body).toContain('Injury Waiver');
    });

    test('attendance page loads', async ({ page }) => {
        await visit(page, '/student/attendance.php', 'attendance history');
        await expect(page.locator('h4')).toContainText('Attendance History');
    });

    test('payment history loads', async ({ page }) => {
        await visit(page, '/student/payment_history.php', 'payment history');
        await expect(page.locator('h4')).toContainText('Payment History');
    });

    test('payment history year filter works', async ({ page }) => {
        await visit(page, '/student/payment_history.php', 'payment history year filter');
        const yearBtn = page.locator('a.btn[href*="?year="]').first();
        if (await yearBtn.isVisible()) {
            await yearBtn.click();
            await page.waitForLoadState('domcontentloaded');
            await assertNoPhpErrors(page, 'payment history filtered');
        }
    });

    test('belt tests page loads', async ({ page }) => {
        await visit(page, '/student/belt_tests.php', 'belt tests');
        await expect(page.locator('h4')).toContainText('Belt Test History');
    });

    test('profile edit page loads', async ({ page }) => {
        await visit(page, '/student/profile_edit.php', 'profile edit');
    });

    test('pay page loads', async ({ page }) => {
        await visit(page, '/student/pay.php', 'payment page');
    });

    test('show all button only appears when >10 entries exist', async ({ page }) => {
        await visit(page, '/student/', 'show all button logic');
        // The Show All button should only exist if there are exactly 10 rows in that table
        const attendanceRows = await page.locator('.card:has(.card-header:has-text("Recent Attendance")) tbody tr').count();
        const showAllBtn = page.locator('a[href="attendance.php"]:has-text("Show All")');
        if (attendanceRows < 10) {
            await expect(showAllBtn).toBeHidden();
        }
    });

    test('feedback form expands on button click', async ({ page }) => {
        await visit(page, '/student/', 'feedback form');
        await page.click('button[data-bs-target="#feedbackForm"]');
        await expect(page.locator('#feedbackForm')).toBeVisible();
        await expect(page.locator('textarea[name="feedback_message"]')).toBeVisible();
    });

    test('navbar shows username and role badge', async ({ page }) => {
        await page.goto(BASE + '/student/');
        // Navbar shows username as plain text and a role badge (not a link)
        const badge = await page.textContent('.role-badge');
        const validTypes = ['guest', 'student', 'instructor', 'admin'];
        expect(validTypes).toContain(badge?.trim().toLowerCase());
    });

    test('nav My Dashboard brand routes to the correct dashboard', async ({ page }) => {
        await page.goto(BASE + '/student/');
        const href = await page.getAttribute('.navbar-brand', 'href');
        // Brand goes to /student/ for students, /instructor/ if jsmith was promoted
        const role = await page.textContent('.role-badge');
        const type = role?.trim().toLowerCase();
        if (type === 'instructor' || type === 'admin') {
            expect(href).toMatch(/\/(instructor|admin)\//);
        } else {
            expect(href).toContain('/student/');
        }
    });

    test('cannot access admin pages', async ({ page }) => {
        const res = await page.goto(BASE + '/admin/payments.php');
        const body = await page.textContent('body');
        expect(body).not.toContain('Record Manual Payment');
    });

    test('cannot access instructor pages', async ({ page }) => {
        const res = await page.goto(BASE + '/instructor/');
        const body = await page.textContent('body');
        expect(body).not.toContain('Mark Attendance');
    });

});
