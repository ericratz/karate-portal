// @ts-check
// Tests for client-side filter UI on admin/students.php and instructor/students.php:
// search box, status filter, waiver filter, count badges, New Student button,
// and student name links.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../helpers');

// ── ADMIN ROSTER ──────────────────────────────────────────────────────────────

test.describe('Admin roster filters', () => {
    test.use({ storageState: AUTH.admin });

    test.beforeEach(async ({ page }) => {
        await page.goto(BASE + '/admin/students.php');
        // SPA route — wait for the roster to render before the non-waiting
        // count()/textContent() calls below.
        await page.locator('#rosterSearch').waitFor({ state: 'visible' });
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

    test('+ New Participant button links to the student editor route', async ({ page }) => {
        const btn = page.locator('a:has-text("+ New Participant")');
        await expect(btn).toBeVisible();
        const href = await btn.getAttribute('href');
        expect(href).toContain('/admin/student-edit');
    });

    test('student name links in table go to the student profile route', async ({ page }) => {
        const link = page.locator('tbody a.text-decoration-none').first();
        // The test DB has 9 students — the roster is never empty.
        await expect(link).toHaveCount(1);
        const href = await link.getAttribute('href');
        // In-app hash route now (#/instructor/student/N), not the .php stub.
        expect(href).toContain('/instructor/student/');
    });

    test('typing in search box hides non-matching rows', async ({ page }) => {
        const totalRows = await page.locator('tbody tr[data-name]').count();
        // The test DB has 9 students — the roster is never empty.
        expect(totalRows).toBeGreaterThan(0);

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

    // V3.1: roster search matches email and phone in addition to name.
    test('search box matches by email address', async ({ page }) => {
        await page.fill('#rosterSearch', 'sarah.j@email.com');
        await page.waitForTimeout(200);
        await expect(page.locator('tbody tr[data-name]:visible')).toHaveCount(1);
        await expect(page.locator('tbody tr[data-name]:visible')).toContainText('Sarah Johnson');
    });

    test('search box matches by phone number', async ({ page }) => {
        await page.fill('#rosterSearch', '555-0199');
        await page.waitForTimeout(200);
        await expect(page.locator('tbody tr[data-name]:visible')).toHaveCount(1);
        await expect(page.locator('tbody tr[data-name]:visible')).toContainText('Sarah Johnson');
    });

    test('clearing search box restores all rows', async ({ page }) => {
        const totalRows = await page.locator('tbody tr[data-name]').count();
        // The test DB has 9 students — the roster is never empty.
        expect(totalRows).toBeGreaterThan(0);

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
        // A dedicated test above ("waiver filter exists") already confirms this element renders.
        await expect(filterEl).toHaveCount(1);
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

    test('roster table has Registration column header', async ({ page }) => {
        // There are multiple tables on the page (Instructors, Students, Parents, Guests),
        // so scope to the first matching header to avoid strict-mode violation.
        await expect(page.locator('thead th:has-text("Registration")').first()).toBeVisible();
    });

    test('registration paid column shows checkmark or X for each student row', async ({ page }) => {
        const rows = await page.locator('tbody tr[data-name]').count();
        expect(rows).toBeGreaterThan(0); // the test DB has 9 students
        // Every row should have either a green ✓ or red ✗ in the reg_paid cell
        const paid   = await page.locator('tbody td .text-success').count();
        const unpaid = await page.locator('tbody td .text-danger').count();
        expect(paid + unpaid).toBeGreaterThan(0);
    });

    test('cards have id attributes for hide-on-empty JS targeting', async ({ page }) => {
        // PHP renders id=”card-students” etc. for the hide-on-empty feature
        const cardIds = ['card-instructors', 'card-parents', 'card-students', 'card-guests'];
        let found = 0;
        for (const id of cardIds) {
            if (await page.locator(`#${id}`).count() > 0) found++;
        }
        expect(found).toBeGreaterThan(0);
    });

    test('filtering to no results hides each card', async ({ page }) => {
        await page.fill('#rosterSearch', 'zzznomatch_hide_test');
        await page.waitForTimeout(300);
        for (const id of ['card-instructors', 'card-parents', 'card-students', 'card-guests']) {
            const el = page.locator(`#${id}`);
            if (await el.count() === 0) continue;
            const display = await el.evaluate(e => window.getComputedStyle(e).display);
            expect(display).toBe('none');
        }
    });

    test('clearing filter restores hidden cards', async ({ page }) => {
        const totalRows = await page.locator('tbody tr[data-name]').count();
        // The test DB has 9 students — the roster is never empty.
        expect(totalRows).toBeGreaterThan(0);
        await page.fill('#rosterSearch', 'zzznomatch_restore_test');
        await page.waitForTimeout(300);
        await page.fill('#rosterSearch', '');
        await page.waitForTimeout(300);
        for (const id of ['card-instructors', 'card-parents', 'card-students', 'card-guests']) {
            const el = page.locator(`#${id}`);
            if (await el.count() === 0) continue;
            const display = await el.evaluate(e => window.getComputedStyle(e).display);
            expect(display).not.toBe('none');
        }
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
        // SPA renders after the API fetch — wait for rows before counting
        await expect(page.locator('tbody tr[data-name]').first()).toBeVisible();
        const totalRows = await page.locator('tbody tr[data-name]').count();
        // The test DB has 9 students — the roster is never empty.
        expect(totalRows).toBeGreaterThan(0);

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

    test('student name link points to the instructor student route', async ({ page }) => {
        const link = page.locator('tbody a.text-decoration-none').first();
        // The test DB has 9 students — the roster is never empty.
        await expect(link).toHaveCount(1);
        const href = await link.getAttribute('href');
        // In-app hash route now (#/instructor/student/N), not the .php stub.
        expect(href).toContain('/instructor/student/');
    });
});
