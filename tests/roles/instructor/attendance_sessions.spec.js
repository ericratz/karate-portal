// @ts-check
// Tests for instructor/attendance_sessions.php — the class sessions list with
// date filter, quick-filter links, and the click-to-expand accordion rows.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, visit, BASE, AUTH } = require('../../helpers');

test.describe('Attendance sessions page', () => {
    test.use({ storageState: AUTH.instructor });

    // ── PAGE LOADS ──────────────────────────────────────────────────────

    test('attendance_sessions.php loads without PHP errors', async ({ page }) => {
        await visit(page, '/instructor/attendance_sessions.php', 'attendance sessions');
    });

    test('page shows Classes heading', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await expect(page.locator('h4').first()).toContainText('Classes');
    });

    // ── NEW SESSION BUTTON ──────────────────────────────────────────────

    test('+ Record New Class link exists and points to attendance.php with a date', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        const link = page.locator('#newSessionBtn');
        await expect(link).toBeVisible();
        const href = await link.getAttribute('href');
        expect(href).toMatch(/attendance\.php\?date=\d{4}-\d{2}-\d{2}/);
    });

    test('changing date input updates the + Record New Class href', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await page.fill('#newSessionDate', '2025-06-01');
        const href = await page.locator('#newSessionBtn').getAttribute('href');
        expect(href).toContain('2025-06-01');
    });

    // ── FILTER FORM ─────────────────────────────────────────────────────

    test('filter form has year / type selects', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await expect(page.locator('select[name="year"]')).toBeVisible();
        await expect(page.locator('select[name="type"]')).toBeVisible();
    });

    test('type select has Class / Seminar / Private options', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        const opts = await page.locator('select[name="type"] option').allTextContents();
        expect(opts.map(o => o.trim())).toEqual(
            expect.arrayContaining(['All Types', 'Class', 'Seminar', 'Private'])
        );
    });

    test('Year select has current year as an option', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        const currentYear = new Date().getFullYear().toString();
        await expect(page.locator(`select[name="year"] option[value="${currentYear}"]`)).toHaveCount(1);
    });

    test('selecting a year loads filtered results without PHP errors', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        const currentYear = new Date().getFullYear().toString();
        await Promise.all([
            page.waitForResponse(r => r.url().includes('/instructor/attendance_sessions.php')),
            page.selectOption('select[name="year"]', currentYear),
        ]);
        await assertNoPhpErrors(page, 'sessions filtered by year');
        expect(page.url()).toContain('year=' + currentYear);
    });

    test('Clear filter link appears after filtering', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        const currentYear = new Date().getFullYear().toString();
        await Promise.all([
            page.waitForResponse(r => r.url().includes('/instructor/attendance_sessions.php')),
            page.selectOption('select[name="year"]', currentYear),
        ]);
        await expect(page.locator('a:has-text("Clear")')).toBeVisible();
    });

    test('Clear link resets to unfiltered list', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        const currentYear = new Date().getFullYear().toString();
        await Promise.all([
            page.waitForResponse(r => r.url().includes('/instructor/attendance_sessions.php')),
            page.selectOption('select[name="year"]', currentYear),
        ]);
        await Promise.all([
            page.waitForResponse(r => r.url().includes('/instructor/attendance_sessions.php')),
            page.click('a:has-text("Clear")'),
        ]);
        // htmx swaps the URL via history.pushState asynchronously after the
        // response — wait for the Clear link itself to disappear (filtering
        // is now false) before checking the URL.
        await expect(page.locator('a:has-text("Clear")')).toHaveCount(0);
        expect(page.url()).not.toContain('year=');
    });

    // ── DATE LINK IN SESSION ROW ────────────────────────────────────────

    test('session date links navigate to attendance.php?date=', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        const link = page.locator('tbody a[href*="attendance.php?date="]').first();
        // The test DB has 10+ class sessions and the default view is unfiltered — always present.
        await expect(link).toHaveCount(1);
        const href = await link.getAttribute('href');
        expect(href).toMatch(/attendance\.php\?date=\d{4}-\d{2}-\d{2}/);
    });

    // ── ACCORDION TOGGLE ────────────────────────────────────────────────
    // The sessions table uses JS toggleSession(i) — clicking a row expands a detail row.
    // The toggle cell id="tog-N" sits inside the clickable row; clicking it bubbles to <tr onclick>.

    test('session detail rows are hidden by default', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await expect(page.locator('#det-0')).toHaveCount(1); // always present — see note above
        await expect(page.locator('#det-0')).toBeHidden();
    });

    test('clicking a session row expands its detail row', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await expect(page.locator('#det-0')).toHaveCount(1);
        // Click the toggle cell (▼) — event bubbles up to <tr onclick="toggleSession(0)">
        await page.locator('#tog-0').click();
        await expect(page.locator('#det-0')).toBeVisible();
        // Toggle arrow flips to ▲
        await expect(page.locator('#tog-0')).toContainText('▲');
    });

    test('clicking an expanded session row collapses it', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await expect(page.locator('#det-0')).toHaveCount(1);
        // Open
        await page.locator('#tog-0').click();
        await expect(page.locator('#det-0')).toBeVisible();
        // Click again — should collapse
        await page.locator('#tog-0').click();
        await expect(page.locator('#det-0')).toBeHidden();
        await expect(page.locator('#tog-0')).toContainText('▼');
    });

    test('opening a second session collapses the first', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        // The test DB has 10+ class sessions, so a second row always exists.
        await expect(page.locator('#det-1')).toHaveCount(1);
        // Open first row
        await page.locator('#tog-0').click();
        await expect(page.locator('#det-0')).toBeVisible();
        // Open second row — first should auto-collapse
        await page.locator('#tog-1').click();
        await expect(page.locator('#det-0')).toBeHidden();
        await expect(page.locator('#det-1')).toBeVisible();
    });

    test('expanded session shows attendance names or "No attendance" message', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await expect(page.locator('#det-0')).toHaveCount(1);
        await page.locator('#tog-0').click();
        const det = page.locator('#det-0');
        await expect(det).toBeVisible();
        const text = await det.textContent();
        // Either lists names (Present section) or shows "No attendance recorded."
        const hasContent = text?.includes('Present') || text?.includes('No attendance recorded');
        expect(hasContent).toBe(true);
    });

    // ── ACCESS CONTROL ──────────────────────────────────────────────────

    test('attendance_sessions.php requires login', async ({ page }) => {
        await page.context().clearCookies();
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('login.php');
    });
});
