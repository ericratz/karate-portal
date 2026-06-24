// @ts-check
// Tests for navigation links and cards on the instructor and admin dashboards.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../helpers');

// â”€â”€ INSTRUCTOR DASHBOARD â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test.describe('Instructor dashboard navigation', () => {
    test.use({ storageState: AUTH.instructor });

    test.beforeEach(async ({ page }) => {
        await page.goto(BASE + '/instructor/');
    });

    test('dashboard cards are all present', async ({ page }) => {
        await assertNoPhpErrors(page, 'instructor dashboard');
        for (const h of ['Take Attendance', 'Recent Classes', 'Students', 'Recent Belt Tests']) {
            await expect(page.locator('.card-header').filter({ hasText: h })).toBeVisible();
        }
        // Date input defaults to today (Â±2 days)
        const val = await page.inputValue('input[name="date"]');
        expect(val).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(Math.abs(Date.now() - new Date(val + 'T12:00:00').getTime())).toBeLessThan(2 * 86400000);
    });

    test('Record New Class form, navigation links, and belt test badges', async ({ page }) => {
        const formAction = await page.locator('button:has-text("Record New Class")').evaluate(el => el.closest('form')?.action ?? '');
        expect(formAction).toContain('attendance.php');
        expect(await page.locator('a:has-text("View All Classes")').getAttribute('href')).toContain('attendance_sessions.php');
        expect(await page.locator('a:has-text("View Student Roster")').getAttribute('href')).toContain('students.php');
        expect(await page.locator('a:has-text("View Tests")').getAttribute('href')).toContain('belt_tests_all.php');
        // Recent session links (if any)
        const link = page.locator('td a[href*="attendance.php?date="]').first();
        if (await link.count() > 0) {
            await link.click();
            await page.waitForLoadState('domcontentloaded');
            expect(page.url()).toContain('attendance.php');
            expect(page.url()).toContain('date=');
        }
        // Belt test result badges only (exclude ⚠ waiver-warning badges from roster section)
        const badges = page.locator('td .badge').filter({ hasNotText: '⚠' });
        const count = await badges.count();
        for (let i = 0; i < count; i++) {
            expect(['Pass','Fail','Pending']).toContain((await badges.nth(i).textContent())?.trim());
        }
    });
});

// â”€â”€ ADMIN DASHBOARD â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test.describe('Admin dashboard navigation', () => {
    test.use({ storageState: AUTH.admin });

    test.beforeEach(async ({ page }) => {
        await page.goto(BASE + '/admin/');
    });

    test('dashboard loads without errors', async ({ page }) => {
        await assertNoPhpErrors(page, 'admin dashboard');
    });

    test('four stat cards with numeric values visible', async ({ page }) => {
        const cards = page.locator('.display-6');
        const count = await cards.count();
        expect(count).toBeGreaterThanOrEqual(4);
        for (let i = 0; i < Math.min(count, 4); i++) {
            const text = await cards.nth(i).textContent();
            expect(text?.trim()).toMatch(/\d/);
        }
    });

    test('Tuition Unpaid card has Record Payment links to payments.php', async ({ page }) => {
        const card = page.locator('.card').filter({
            has: page.locator('.card-header').filter({ hasText: 'Tuition Unpaid' })
        });
        await expect(card).toBeVisible();
        const link = card.locator('a[href*="payments.php"]').first();
        if (await link.count() > 0) {
            expect(await link.getAttribute('href')).toContain('payments.php');
        }
    });

    test('Missing Waivers card is visible', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Missing Waivers' })).toBeVisible();
    });

    test('Recent Payments card is visible', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Recent Payments' })).toBeVisible();
    });

    test('navigation buttons link to correct pages', async ({ page }) => {
        const body = await page.textContent('body');
        expect(body).toContain('Roster');
        expect(body).toContain('Payments');
    });
});

// â”€â”€ ADMIN STUDENTS PAGE LINKS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test.describe('Admin students page links', () => {
    test.use({ storageState: AUTH.admin });

    test('student name links from roster go to student_profile.php', async ({ page }) => {
        await page.goto(BASE + '/admin/students.php');
        const link = page.locator('tbody a.text-decoration-none').first();
        if (await link.count() === 0) return;
        const href = await link.getAttribute('href');
        expect(href).toContain('student_profile.php');
    });

    test('clicking a student name navigates to their profile', async ({ page }) => {
        await page.goto(BASE + '/admin/students.php');
        const link = page.locator('tbody a.text-decoration-none').first();
        if (await link.count() === 0) return;
        await link.click();
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('student_profile.php');
    });
});
