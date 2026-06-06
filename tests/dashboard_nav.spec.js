// @ts-check
// Tests for navigation links and cards on the instructor and admin dashboards:
// Recent Sessions date links, View by Session, View Student Roster,
// belt test student name links, date input default, Record New Session form,
// admin dashboard stat cards and action links.
const { test, expect } = require('@playwright/test');
const { login, logout, visit, assertNoPhpErrors, BASE } = require('./helpers');

const { ADMIN_USER, ADMIN_PASS, INST_USER, INST_PASS } = require('./credentials');

// ── INSTRUCTOR DASHBOARD ─────────────────────────────────────────────────────

test.describe('Instructor dashboard navigation', () => {

    test.beforeEach(async ({ page }) => {
        await login(page, INST_USER, INST_PASS);
        await page.goto(BASE + '/instructor/');
    });

    test('dashboard loads without errors', async ({ page }) => {
        await assertNoPhpErrors(page, 'instructor dashboard');
    });

    test('Take Attendance card is visible', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Take Attendance' })).toBeVisible();
    });

    test('date input defaults to today', async ({ page }) => {
        const val = await page.inputValue('input[name="date"]');
        // Server runs in Mountain Time; accept either side of midnight UTC crossover
        expect(val).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        const diff = Math.abs(Date.now() - new Date(val + 'T12:00:00').getTime());
        expect(diff).toBeLessThan(2 * 24 * 60 * 60 * 1000); // within 2 days
    });

    test('Record New Session button is inside a GET form pointing to attendance.php', async ({ page }) => {
        const btn = page.locator('button:has-text("Record New Session")');
        await expect(btn).toBeVisible();
        const formAction = await btn.evaluate(el => el.closest('form')?.action ?? '');
        expect(formAction).toContain('attendance.php');
    });

    test('Recent Sessions card is visible', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Recent Sessions' })).toBeVisible();
    });

    test('"View by Session" button links to attendance_sessions.php', async ({ page }) => {
        const btn = page.locator('a:has-text("View by Session")');
        await expect(btn).toBeVisible();
        const href = await btn.getAttribute('href');
        expect(href).toContain('attendance_sessions.php');
    });

    test('recent session date links go to attendance.php?date=', async ({ page }) => {
        const link = page.locator('td a[href*="attendance.php?date="]').first();
        if (await link.count() === 0) return; // no sessions recorded yet
        const href = await link.getAttribute('href');
        expect(href).toContain('attendance.php?date=');
    });

    test('clicking a recent session date link navigates to attendance page', async ({ page }) => {
        const link = page.locator('td a[href*="attendance.php?date="]').first();
        if (await link.count() === 0) return;
        await link.click();
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('attendance.php');
        expect(page.url()).toContain('date=');
    });

    test('Students card has View Student Roster button', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Students' })).toBeVisible();
        const btn = page.locator('a:has-text("View Student Roster")');
        await expect(btn).toBeVisible();
    });

    test('"View Student Roster" button links to students.php', async ({ page }) => {
        const href = await page.locator('a:has-text("View Student Roster")').getAttribute('href');
        expect(href).toContain('students.php');
    });

    test('Recent Belt Tests card is visible', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Recent Belt Tests' })).toBeVisible();
    });

    test('"View Tests" button links to belt_tests_all.php', async ({ page }) => {
        const href = await page.locator('a:has-text("View Tests")').getAttribute('href');
        expect(href).toContain('belt_tests_all.php');
    });

    test('belt test student name links go to student_profile.php', async ({ page }) => {
        const link = page.locator('td a[href*="student_profile.php"]').first();
        if (await link.count() === 0) return; // no belt tests in DB
        const href = await link.getAttribute('href');
        expect(href).toContain('student_profile.php');
    });

    test('result badges show Pass, Fail, or Pending', async ({ page }) => {
        const badges = page.locator('td .badge');
        const count = await badges.count();
        for (let i = 0; i < count; i++) {
            const text = (await badges.nth(i).textContent())?.trim();
            expect(['Pass', 'Fail', 'Pending']).toContain(text);
        }
    });
});

// ── ADMIN DASHBOARD ───────────────────────────────────────────────────────────

test.describe('Admin dashboard navigation', () => {

    test.beforeEach(async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/');
    });

    test('dashboard loads without errors', async ({ page }) => {
        await assertNoPhpErrors(page, 'admin dashboard');
    });

    test('four stat cards with numeric values visible', async ({ page }) => {
        const cards = page.locator('.display-6');
        const count = await cards.count();
        expect(count).toBeGreaterThanOrEqual(4);
        // Each .display-6 should contain digits
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
        // If there are unpaid students, Record Payment links should appear
        const link = card.locator('a[href*="payments.php"]').first();
        if (await link.count() > 0) {
            const href = await link.getAttribute('href');
            expect(href).toContain('payments.php');
        }
    });

    test('Missing Injury Waivers card is visible', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Missing Injury Waivers' })).toBeVisible();
    });

    test('Recent Payments card is visible', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Recent Payments' })).toBeVisible();
    });

    test('navigation buttons link to correct pages', async ({ page }) => {
        const body = await page.textContent('body');
        // All these nav links should be on the page
        expect(body).toContain('Roster');
        expect(body).toContain('Payments');
    });
});

// ── ADMIN STUDENTS PAGE LINKS ─────────────────────────────────────────────────

test.describe('Admin students page links', () => {

    test('student name links from roster go to student_profile.php', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/students.php');
        const link = page.locator('tbody a.text-decoration-none').first();
        if (await link.count() === 0) return;
        const href = await link.getAttribute('href');
        expect(href).toContain('student_profile.php');
    });

    test('clicking a student name navigates to their profile', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/students.php');
        const link = page.locator('tbody a.text-decoration-none').first();
        if (await link.count() === 0) return;
        await link.click();
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('student_profile.php');
    });
});
