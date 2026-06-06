// @ts-check
const { test, expect } = require('@playwright/test');
const { login, visit, assertNoPhpErrors, BASE } = require('./helpers');

const { GUEST_USER, GUEST_PASS } = require('./credentials');

test.describe('Guest', () => {

    test.beforeEach(async ({ page }) => {
        await login(page, GUEST_USER, GUEST_PASS);
    });

    test('dashboard loads', async ({ page }) => {
        await visit(page, '/student/', 'guest dashboard');
        await expect(page.locator('h3')).toContainText('Welcome');
    });

    test('cannot access admin pages', async ({ page }) => {
        await page.goto(BASE + '/admin/payments.php');
        const body = await page.textContent('body');
        expect(body).not.toContain('Record Manual Payment');
    });

    test('cannot access instructor pages', async ({ page }) => {
        await page.goto(BASE + '/instructor/');
        const body = await page.textContent('body');
        expect(body).not.toContain('Mark Attendance');
    });

});
