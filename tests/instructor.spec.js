// @ts-check
const { test, expect } = require('@playwright/test');
const { login, visit, assertNoPhpErrors, BASE } = require('./helpers');

const { INST_USER, INST_PASS } = require('./credentials');

test.describe('Instructor', () => {

    test.beforeEach(async ({ page }) => {
        await login(page, INST_USER, INST_PASS);
    });

    test('dashboard loads', async ({ page }) => {
        await visit(page, '/instructor/', 'instructor dashboard');
        // Dashboard now shows Take Attendance form and Recent Belt Tests (no tabs)
        await expect(page.locator('body')).toContainText('Take Attendance');
    });

    test('attendance page loads for today', async ({ page }) => {
        const today = new Date().toISOString().slice(0, 10);
        await visit(page, `/instructor/attendance.php?date=${today}`, 'attendance');
    });

    test('student profile loads', async ({ page }) => {
        // Navigate to roster page to find a valid student link
        await page.goto(BASE + '/instructor/students.php');
        const link = page.locator('tbody a.text-decoration-none').first();
        if (await link.isVisible()) {
            await link.click();
            await page.waitForLoadState('domcontentloaded');
            await assertNoPhpErrors(page, 'student profile');
            await expect(page.locator('h4')).toBeVisible();
        }
    });

    test('back button on student profile goes to instructor dashboard', async ({ page }) => {
        await page.goto(BASE + '/instructor/students.php');
        const link = page.locator('tbody a.text-decoration-none').first();
        if (await link.isVisible()) {
            await link.click();
            await page.waitForLoadState('domcontentloaded');
            const backHref = await page.getAttribute('a:has-text("← Back")', 'href');
            expect(backHref).toContain('index.php');
        }
    });

    test('nav My Dashboard goes to instructor dashboard', async ({ page }) => {
        await page.goto(BASE + '/instructor/');
        const href = await page.getAttribute('.navbar-brand', 'href');
        expect(href).toContain('/instructor/');
    });

    test('cannot access admin pages', async ({ page }) => {
        const res = await page.goto(BASE + '/admin/payments.php');
        // Should redirect to login or show 403
        expect([403, 302, 200]).toContain(res.status());
        const url = page.url();
        // If 200, should not be the payments page (should have redirected)
        if (res.status() === 200) {
            const body = await page.textContent('body');
            expect(body).not.toContain('Record Manual Payment');
        }
    });

});
