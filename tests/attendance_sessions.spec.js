// @ts-check
// Tests for instructor/attendance_sessions.php — the class sessions list with
// date filter, quick-filter links, and the click-to-expand accordion rows.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, visit, BASE, AUTH } = require('./helpers');

test.describe('Attendance sessions page', () => {
    test.use({ storageState: AUTH.instructor });

    // ── PAGE LOADS ────────────────────────────────────────────────────────────

    test('attendance_sessions.php loads without PHP errors', async ({ page }) => {
        await visit(page, '/instructor/attendance_sessions.php', 'attendance sessions');
    });

    test('page shows Classes heading', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await expect(page.locator('h4').first()).toContainText('Classes');
    });

    // ── NEW SESSION BUTTON ────────────────────────────────────────────────────

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

    // ── FILTER FORM ───────────────────────────────────────────────────────────

    test('date filter form has from / to / type inputs', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await expect(page.locator('input[name="from"]')).toBeVisible();
        await expect(page.locator('input[name="to"]')).toBeVisible();
        await expect(page.locator('select[name="type"]')).toBeVisible();
    });

    test('type select has Class / Seminar / Private options', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        const opts = await page.locator('select[name="type"] option').allTextContents();
        expect(opts.map(o => o.trim())).toEqual(
            expect.arrayContaining(['All Types', 'Class', 'Seminar', 'Private'])
        );
    });

    test('This Month quick-filter link exists', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await expect(page.locator('a:has-text("This Month")')).toBeVisible();
    });

    test('This Year quick-filter link exists', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await expect(page.locator('a:has-text("This Year")')).toBeVisible();
    });

    test('This Month link loads filtered results without PHP errors', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await page.click('a:has-text("This Month")');
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'sessions this month');
        // URL should contain from= and to= params
        expect(page.url()).toContain('from=');
        expect(page.url()).toContain('to=');
    });

    test('Clear filter link appears after filtering', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await page.click('a:has-text("This Month")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('a:has-text("Clear")')).toBeVisible();
    });

    test('Clear link resets to unfiltered list', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await page.click('a:has-text("This Month")');
        await page.waitForLoadState('domcontentloaded');
        await page.click('a:has-text("Clear")');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).not.toContain('from=');
    });

    // ── DATE LINK IN SESSION ROW ──────────────────────────────────────────────

    test('session date links navigate to attendance.php?date=', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        const link = page.locator('tbody a[href*="attendance.php?date="]').first();
        if (await link.count() === 0) return; // no sessions in DB
        const href = await link.getAttribute('href');
        expect(href).toMatch(/attendance\.php\?date=\d{4}-\d{2}-\d{2}/);
    });

    // ── ACCORDION TOGGLE ──────────────────────────────────────────────────────
    // The sessions table uses JS toggleSession(i) — clicking a row expands a detail row.
    // The toggle cell id="tog-N" sits inside the clickable row; clicking it bubbles to <tr onclick>.

    test('session detail rows are hidden by default', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        if (await page.locator('#det-0').count() === 0) return; // no sessions
        await expect(page.locator('#det-0')).toBeHidden();
    });

    test('clicking a session row expands its detail row', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        if (await page.locator('#det-0').count() === 0) return;
        // Click the toggle cell (▼) — event bubbles up to <tr onclick="toggleSession(0)">
        await page.locator('#tog-0').click();
        await expect(page.locator('#det-0')).toBeVisible();
        // Toggle arrow flips to ▲
        await expect(page.locator('#tog-0')).toContainText('▲');
    });

    test('clicking an expanded session row collapses it', async ({ page }) => {
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        if (await page.locator('#det-0').count() === 0) return;
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
        if (await page.locator('#det-1').count() === 0) return; // need at least 2 sessions
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
        if (await page.locator('#det-0').count() === 0) return;
        await page.locator('#tog-0').click();
        const det = page.locator('#det-0');
        await expect(det).toBeVisible();
        const text = await det.textContent();
        // Either lists names (Present section) or shows "No attendance recorded."
        const hasContent = text?.includes('Present') || text?.includes('No attendance recorded');
        expect(hasContent).toBe(true);
    });

    // ── ACCESS CONTROL ────────────────────────────────────────────────────────

    test('attendance_sessions.php requires login', async ({ page }) => {
        await page.context().clearCookies();
        await page.goto(BASE + '/instructor/attendance_sessions.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('login.php');
    });
});
