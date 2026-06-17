// @ts-check
// Tests for client-side filter UI on admin/students.php and instructor/students.php:
// search box, status filter, waiver filter, count badges, New Student button,
// and student name links.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('./helpers');

// ── ADMIN ROSTER ──────────────────────────────────────────────────────────────

test.describe('Admin roster filters', () => {
    test.use({ storageState: AUTH.admin });

    test.beforeEach(async ({ page }) => {
        await page.goto(BASE + '/admin/students.php');
    });

    test('roster page loads without errors', async ({ page }) => {
        await assertNoPhpErrors(page, 'admin roster');
    });

    test('search box #rosterSearch exists', async ({ page }) => {
        await expect(page.locator('#rosterSearch')).toBeVisible();
    });

    test('status filter #filterStatus exists', async ({ page }) => {
        await expect(page.locator('#filterStatus')).toBeVisible();
    });

    test('waiver filter exists', async ({ page }) => {
        // Filter select for waiver status
        await expect(page.locator('#filterWaiver, select[id*="waiver"]')).toBeVisible();
    });

    test('+ New Participant button links to student_edit.php', async ({ page }) => {
        const btn = page.locator('a:has-text("+ New Participant")');
        await expect(btn).toBeVisible();
        const href = await btn.getAttribute('href');
        expect(href).toContain('student_edit.php');
    });

    test('student name links in table go to student_profile.php', async ({ page }) => {
        const link = page.locator('tbody a.text-decoration-none').first();
        if (await link.count() === 0) return;
        const href = await link.getAttribute('href');
        expect(href).toContain('student_profile.php');
    });

    test('typing in search box hides non-matching rows', async ({ page }) => {
        const totalRows = await page.locator('tbody tr[data-name]').count();
        if (totalRows === 0) return;

        await page.fill('#rosterSearch', 'zzznomatch');
        // Allow JS to filter
        await page.waitForTimeout(200);

        let visible = 0;
        for (let i = 0; i < totalRows; i++) {
            const display = await page.locator('tbody tr[data-name]').nth(i)
                .evaluate(el => window.getComputedStyle(el).display);
            if (display !== 'none') visible++;
        }
        expect(visible).toBe(0);
    });

    test('clearing search box restores all rows', async ({ page }) => {
        const totalRows = await page.locator('tbody tr[data-name]').count();
        if (totalRows === 0) return;

        await page.fill('#rosterSearch', 'zzznomatch');
        await page.waitForTimeout(200);
        await page.fill('#rosterSearch', '');
        await page.waitForTimeout(200);

        let visible = 0;
        for (let i = 0; i < totalRows; i++) {
            const display = await page.locator('tbody tr[data-name]').nth(i)
                .evaluate(el => window.getComputedStyle(el).display);
            if (display !== 'none') visible++;
        }
        expect(visible).toBe(totalRows);
    });

    test('status filter Active hides inactive rows', async ({ page }) => {
        await page.selectOption('#filterStatus', 'active');
        await page.waitForTimeout(200);
        const inactiveRows = page.locator('tbody tr[data-status="inactive"]');
        const count = await inactiveRows.count();
        for (let i = 0; i < count; i++) {
            const display = await inactiveRows.nth(i).evaluate(el => window.getComputedStyle(el).display);
            expect(display).toBe('none');
        }
    });

    test('status filter Inactive hides active rows', async ({ page }) => {
        await page.selectOption('#filterStatus', 'inactive');
        await page.waitForTimeout(200);
        const activeRows = page.locator('tbody tr[data-status="active"]');
        const count = await activeRows.count();
        for (let i = 0; i < count; i++) {
            const display = await activeRows.nth(i).evaluate(el => window.getComputedStyle(el).display);
            expect(display).toBe('none');
        }
    });

    test('waiver filter Signed hides unsigned rows', async ({ page }) => {
        const filterEl = page.locator('#filterWaiver, select[id*="waiver"]').first();
        if (await filterEl.count() === 0) return;
        await filterEl.selectOption('yes');
        await page.waitForTimeout(200);
        const unsignedRows = page.locator('tbody tr[data-waiver="no"]');
        const count = await unsignedRows.count();
        for (let i = 0; i < count; i++) {
            const display = await unsignedRows.nth(i).evaluate(el => window.getComputedStyle(el).display);
            expect(display).toBe('none');
        }
    });

    test('card count badges update when filter applied', async ({ page }) => {
        // Get initial Students badge count
        const studentsBadge = page.locator('.card-header').filter({ hasText: 'Students' }).locator('.badge');
        const initialCount = parseInt(await studentsBadge.textContent() ?? '0', 10);

        // Apply a filter that should reduce results (status=active only, if any inactive)
        await page.selectOption('#filterStatus', 'inactive');
        await page.waitForTimeout(200);
        const newCount = parseInt(await studentsBadge.textContent() ?? '0', 10);

        // Either the count dropped, or it stayed the same (all are inactive)
        expect(newCount).toBeLessThanOrEqual(initialCount);
    });

    test('resetting status filter to All restores counts', async ({ page }) => {
        const studentsBadge = page.locator('.card-header').filter({ hasText: 'Students' }).locator('.badge');
        const initial = parseInt(await studentsBadge.textContent() ?? '0', 10);

        await page.selectOption('#filterStatus', 'active');
        await page.waitForTimeout(200);
        await page.selectOption('#filterStatus', '');
        await page.waitForTimeout(200);

        const restored = parseInt(await studentsBadge.textContent() ?? '0', 10);
        expect(restored).toBe(initial);
    });

    test('three card sections visible (Instructors, Students, Guests)', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Instructors' })).toBeVisible();
        await expect(page.locator('.card-header').filter({ hasText: 'Students' })).toBeVisible();
        await expect(page.locator('.card-header').filter({ hasText: 'Guests' })).toBeVisible();
    });
});

// ── INSTRUCTOR ROSTER ─────────────────────────────────────────────────────────

test.describe('Instructor roster filters', () => {
    test.use({ storageState: AUTH.instructor });

    test.beforeEach(async ({ page }) => {
        await page.goto(BASE + '/instructor/students.php');
    });

    test('instructor roster loads without errors', async ({ page }) => {
        await assertNoPhpErrors(page, 'instructor roster');
    });

    test('search box exists', async ({ page }) => {
        await expect(page.locator('#rosterSearch')).toBeVisible();
    });

    test('typing in search hides non-matching rows', async ({ page }) => {
        const totalRows = await page.locator('tbody tr[data-name]').count();
        if (totalRows === 0) return;

        await page.fill('#rosterSearch', 'zzznomatch');
        await page.waitForTimeout(200);

        let visible = 0;
        for (let i = 0; i < totalRows; i++) {
            const display = await page.locator('tbody tr[data-name]').nth(i)
                .evaluate(el => window.getComputedStyle(el).display);
            if (display !== 'none') visible++;
        }
        expect(visible).toBe(0);
    });

    test('status filter select exists', async ({ page }) => {
        await expect(page.locator('#filterStatus, select[id*="status"]')).toBeVisible();
    });

    test('three card sections visible', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Instructors' })).toBeVisible();
        await expect(page.locator('.card-header').filter({ hasText: 'Students' })).toBeVisible();
        await expect(page.locator('.card-header').filter({ hasText: 'Guests' })).toBeVisible();
    });

    test('student name link points to student_profile.php', async ({ page }) => {
        const link = page.locator('tbody a.text-decoration-none').first();
        if (await link.count() === 0) return;
        const href = await link.getAttribute('href');
        expect(href).toContain('student_profile.php');
    });
});
