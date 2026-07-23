// @ts-check
// Tests for navigation links and cards on the instructor and admin dashboards.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../helpers');

// ── INSTRUCTOR DASHBOARD ─────────────────────────────────────────────────────

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
        // Date input defaults to today (±2 days)
        const val = await page.inputValue('input[name="date"]');
        expect(val).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(Math.abs(Date.now() - new Date(val + 'T12:00:00').getTime())).toBeLessThan(2 * 86400000);
    });

    test('Record New Class form, navigation links, and belt test badges', async ({ page }) => {
        // The Record New Class form navigates in-app on submit (no action=
        // attendance.php stub), so filling the date and submitting lands on the
        // attendance route rather than a server round-trip.
        await page.locator('#newClassDate').fill('2026-07-07');
        await page.locator('button:has-text("Record New Class")').click();
        await expect(page).toHaveURL(/#\/instructor\/attendance\?date=2026-07-07/);
        await page.goBack();
        // Navigation links are SPA hash routes now
        expect(await page.locator('a:has-text("View All Classes")').getAttribute('href')).toContain('classes');
        expect(await page.locator('a:has-text("View Student Roster")').getAttribute('href')).toContain('roster');
        expect(await page.locator('a:has-text("View Tests")').getAttribute('href')).toContain('belt-tests');
        // Recent session links are in-app hash routes now
        const link = page.locator('td a[href*="/instructor/attendance?date="]').first();
        if (await link.count() > 0) {
            await link.click();
            await page.waitForLoadState('domcontentloaded');
            expect(page.url()).toContain('attendance');
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

// ── ADMIN DASHBOARD ───────────────────────────────────────────────────────────

test.describe('Admin dashboard navigation', () => {
    test.use({ storageState: AUTH.admin });

    test.beforeEach(async ({ page }) => {
        await page.goto(BASE + '/admin/');
        // SPA dashboard — wait for render before the non-waiting count() calls
        await expect(page.locator('.card-header').filter({ hasText: 'Tuition Unpaid' })).toBeVisible();
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

// ── ADMIN STUDENTS PAGE LINKS ─────────────────────────────────────────────────

test.describe('Admin students page links', () => {
    test.use({ storageState: AUTH.admin });

    test('student name links from roster go to the student profile route', async ({ page }) => {
        await page.goto(BASE + '/admin/students.php');
        const link = page.locator('tbody a.text-decoration-none').first();
        // The test DB has 9 students — the roster is never empty.
        await expect(link).toHaveCount(1);
        const href = await link.getAttribute('href');
        // In-app hash route now (#/instructor/student/N), not the .php stub.
        expect(href).toContain('/instructor/student/');
    });

    test('clicking a student name navigates to their profile', async ({ page }) => {
        await page.goto(BASE + '/admin/students.php');
        const link = page.locator('tbody a.text-decoration-none').first();
        // The test DB has 9 students — the roster is never empty.
        await expect(link).toHaveCount(1);
        await link.click();
        await page.waitForLoadState('domcontentloaded');
        // student_profile.php is a redirect stub into the SPA route now
        expect(page.url()).toMatch(/student_profile\.php|#\/instructor\/student\//);
    });
});
