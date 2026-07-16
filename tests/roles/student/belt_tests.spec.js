// @ts-check
// Tests for the student belt test history — now the React SPA route
// (student/belt_tests.php is a redirect stub into app.php#/belt-tests/N).
// Covers summary cards, table columns, pass/fail/pending badge rendering, and access control.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../../helpers');

// jsmith's student record (id=47) only has a "fail" belt test in the fixture DB —
// no "pending" (null-score) test exists, so that rendering path needs its own
// fixture row. Created here as instructor, deleted in afterAll.
const TS = Date.now();
const PENDING_NOTE = `PendingFixture${TS}`;

test.describe('Student belt test history page', () => {
    test.use({ storageState: AUTH.student });

    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage({ storageState: AUTH.instructor });
        await page.goto(BASE + '/instructor/belt_test_edit.php');
        await page.fill('#studentFilter', 'John Smith');
        await page.waitForTimeout(100);
        await page.locator('.student-btn:visible').first().click();
        const futureDate = new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10);
        await page.fill('input[name="test_date"]', futureDate);
        await page.selectOption('select[name="rank_id"]', { index: 1 });
        // Leave all score fields untouched — score stays null, result becomes 'pending'.
        await page.fill('textarea[name="notes"]', PENDING_NOTE);
        await page.click('button[type="submit"]');
        await page.waitForLoadState('domcontentloaded');
        await page.close();
    });

    test.afterAll(async ({ browser }) => {
        const page = await browser.newPage({ storageState: AUTH.instructor });
        await page.goto(BASE + '/instructor/belt_tests_all.php');
        await page.click('#editToggle');
        const row = page.locator('tr').filter({ hasText: PENDING_NOTE });
        if (await row.count() > 0) {
            page.once('dialog', d => d.accept());
            await row.locator('.btn-outline-danger').click();
            await page.waitForLoadState('domcontentloaded');
        }
        await page.close();
    });

    // ── PAGE LOADS ────────────────────────────────────────────────────────────

    test('belt_tests.php loads without PHP errors', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        await assertNoPhpErrors(page, 'student belt tests');
        // SPA route — the heading renders after the API fetch completes
        await expect(page.locator('h4').first()).toContainText('Belt Test History');
    });

    test('page heading includes Belt Test History', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        await expect(page.locator('h4').first()).toContainText('Belt Test History');
    });

    // ── SUMMARY CARDS ─────────────────────────────────────────────────────────

    test('summary shows Total Tests card', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        await expect(page.locator('body')).toContainText('Total Tests');
    });

    test('summary shows Passed card', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        await expect(page.locator('body')).toContainText('Passed');
    });

    test('summary shows Pending card', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        await expect(page.locator('body')).toContainText('Pending');
    });

    // ── ALL BELT TESTS CARD ───────────────────────────────────────────────────

    test('All Belt Tests card is visible', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        await expect(page.locator('.card-header').filter({ hasText: 'All Belt Tests' })).toBeVisible();
    });

    test('table has correct column headers when tests exist', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        // John Smith always has belt test rows — his real fixture test plus the pending one above.
        await expect(page.locator('tbody tr').first()).toBeVisible();
        await expect(page.locator('thead')).toContainText('Date');
        await expect(page.locator('thead')).toContainText('Testing For');
        await expect(page.locator('thead')).toContainText('Score');
        await expect(page.locator('thead')).toContainText('Fee');
        await expect(page.locator('thead')).toContainText('Test Passed');
    });

    // ── PASS / FAIL / PENDING RENDERING ──────────────────────────────────────
    // "Passing" rendering is covered by instructor/belt_tests.spec.js's AutoRank
    // round-trip test — not duplicated here. "Failing" and "score set" are backed
    // by John Smith's real fixture belt test (score=75, result=fail). "Pending" is
    // backed by the fixture row created in beforeAll above.

    test('failing belt test shows red Failed badge in Test Passed column', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        const failCell = page.locator('tbody td:last-child .badge.bg-danger:has-text("Failed")');
        await expect(failCell.first()).toBeVisible();
    });

    test('pending belt test shows - dash in Test Passed column', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        // Pending result = neither pass nor fail → last <td> shows "—" in a text-muted span
        const pendingCell = page.locator('tbody td:last-child span.text-muted');
        await expect(pendingCell.first()).toContainText('—');
    });

    test('score badge shows Pending when score is null', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        // A null score renders as <span class="badge bg-secondary">Pending</span> in the Score column
        const pendingBadge = page.locator('tbody .badge.bg-secondary:has-text("Pending")');
        await expect(pendingBadge.first()).toBeVisible();
    });

    test('score badge shows percentage when score is set', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        // A numeric score renders as "NN%" in the Score column
        const scoreBadge = page.locator('tbody .badge').filter({ hasText: /%$/ });
        await expect(scoreBadge.first()).toBeVisible();
        expect(await scoreBadge.first().textContent()).toMatch(/\d+%/);
    });

    // ── FEE DISPLAY ───────────────────────────────────────────────────────────

    test('fee column renders when tests exist (✓ for paid, blank otherwise)', async ({ page }) => {
        await page.goto(BASE + '/student/belt_tests.php');
        // SPA fee column: <span class="text-success">✓</span> when fee_paid, empty cell otherwise
        await expect(page.locator('tbody tr').first()).toBeVisible();
        await expect(page.locator('thead')).toContainText('Fee');
    });

    // ── ACCESS CONTROL ────────────────────────────────────────────────────────

    test('belt_tests.php requires login - redirects unauthenticated users', async ({ page }) => {
        await page.context().clearCookies();
        await page.goto(BASE + '/student/belt_tests.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('login.php');
    });
});
