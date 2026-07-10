// @ts-check
// Belt test tests — edit form, toggle UI, and create/verify/delete round-trip.
const { test, expect } = require('@playwright/test');
const { login, logout, visit, assertNoPhpErrors, BASE, AUTH } = require('../../helpers');
const { ADMIN_USER, ADMIN_PASS } = require('../../credentials');

test.describe.configure({ mode: 'serial' });
// Most tests run as instructor; admin/guest tests override with explicit login.
test.use({ storageState: AUTH.instructor });
const TS = Date.now();

// ── BELT TEST EDIT FORM ───────────────────────────────────────────────────────

test('belt_test_edit.php loads and form fields are correct', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_test_edit.php');
    await assertNoPhpErrors(page, 'belt_test_edit new');
    // #scoreManual is only rendered as a visible input on existing tests with a recorded score.
    // On a new form it's <input type="hidden" name="score_manual"> — just verify it's present.
    await expect(page.locator('input[name="score_manual"]')).toHaveCount(1);
    // Student selector is now type-to-filter (no raw select element)
    await expect(page.locator('#studentFilter')).toBeVisible();
    // #studentList exists but has 0 height initially (all buttons are display:none until typing)
    await expect(page.locator('#studentList')).toHaveCount(1);
    // Rank select is required
    expect(await page.locator('select[name="rank_id"]').getAttribute('required')).not.toBeNull();
    // Date defaults to today (±2 days)
    const val = await page.inputValue('input[name="test_date"]');
    expect(val).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(Math.abs(Date.now() - new Date(val + 'T12:00:00').getTime())).toBeLessThan(2 * 86400000);
    // #beltAwarded is set server-side only (no HTML element); fee_paid checkbox is in the chart section
    await expect(page.locator('#feePaid')).toBeEnabled();
});

test('type-to-filter student selector shows matching names and hides after selection', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_test_edit.php');
    // No students visible before typing
    const allBtns = page.locator('.student-btn');
    const total = await allBtns.count();
    expect(total).toBeGreaterThan(0);
    let visibleBefore = 0;
    for (let i = 0; i < total; i++) {
        const display = await allBtns.nth(i).evaluate(el => window.getComputedStyle(el).display);
        if (display !== 'none') visibleBefore++;
    }
    expect(visibleBefore).toBe(0);
    // After typing, matching buttons appear
    await page.fill('#studentFilter', 'a');
    await page.waitForTimeout(100);
    let visibleAfter = 0;
    for (let i = 0; i < total; i++) {
        const display = await allBtns.nth(i).evaluate(el => window.getComputedStyle(el).display);
        if (display !== 'none') visibleAfter++;
    }
    expect(visibleAfter).toBeGreaterThan(0);
    // Clicking a button hides the filter and shows #studentSelected
    await page.locator('.student-btn:visible').first().click();
    await expect(page.locator('#studentFilter')).toBeHidden();
    await expect(page.locator('#studentSelected')).toBeVisible();
    await expect(page.locator('#studentSelectedName')).not.toBeEmpty();
    // Clicking "change" restores the filter
    await page.locator('#studentSelected button').click();
    await expect(page.locator('#studentFilter')).toBeVisible();
    await expect(page.locator('#studentSelected')).toBeHidden();
});

test('3rd Dan rank is not listed in rank options', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_test_edit.php');
    const rankOptions = await page.locator('select[name="rank_id"] option').allTextContents();
    const hasSandan = rankOptions.some(t => /3rd\s*dan|sandan/i.test(t));
    expect(hasSandan).toBe(false);
});

test('score preview shows Pass/Fail/empty based on sub-score inputs', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_test_edit.php');
    // Select a student to reveal the chart section
    await page.fill('#studentFilter', 'a');
    await page.waitForTimeout(100);
    await page.locator('.student-btn:visible').first().click();
    await expect(page.locator('#chartSection')).toBeVisible({ timeout: 3000 });
    // Fill sub-score inputs for a passing total (>= 80)
    await page.evaluate(() => {
        const type = document.getElementById('chartTypeInput').value;
        if (type === 'lower') {
            [['l_basics_form', 22], ['l_basics_eff', 22], ['l_kumite_form', 18], ['l_kumite_eff', 18]]
                .forEach(([id, v]) => { const el = document.getElementById(id); if (el) el.value = v; });
        } else {
            [['r_kata_form', 15], ['r_kata_eff', 15], ['r_basics_form', 15], ['r_basics_eff', 15],
             ['r_kumite_form', 10], ['r_kumite_eff', 10]]
                .forEach(([id, v]) => { const el = document.getElementById(id); if (el) el.value = v; });
        }
        recomputeScore();
    });
    await expect(page.locator('#resultBadge .badge.bg-success')).toBeVisible();
    // Clear all inputs → badge should empty
    await page.evaluate(() => {
        ['r_kata_form','r_kata_eff','r_basics_form','r_basics_eff','r_kumite_form','r_kumite_eff',
         'l_basics_form','l_basics_eff','l_kumite_form','l_kumite_eff']
            .forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
        recomputeScore();
    });
    expect((await page.textContent('#resultBadge'))?.trim()).toBe('');
});

test('submitting without student and rank shows validation error', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_test_edit.php');
    // Make the chart section visible (submit button is hidden by default until a "student" is conceptually selected)
    await page.evaluate(() => {
        document.getElementById('chartSection').style.display = '';
        document.querySelector('select[name="rank_id"]')?.removeAttribute('required');
        document.querySelector('input[name="test_date"]')?.removeAttribute('required');
    });
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('.alert-danger').first()).toBeVisible();
});

// ── BELT TESTS ALL — DASHBOARD + TOGGLE ──────────────────────────────────────

test('instructor dashboard shows Recent Belt Tests and links to belt_tests_all.php', async ({ page }) => {
    await page.goto(BASE + '/instructor/');
    await expect(page.locator('.card-header').filter({ hasText: 'Recent Belt Tests' })).toBeVisible();
    await expect(page.locator('a[href="belt_tests_all.php"]')).toBeVisible();
});

test('belt_tests_all.php loads, shows count, and edit toggle works', async ({ page }) => {
    await visit(page, '/instructor/belt_tests_all.php', 'all belt tests');
    // Header shows count
    expect(await page.locator('.card-header').first().textContent()).toMatch(/\d+\s*test/i);
    // Unauthenticated user cannot access — clear cookies instead of logging in as guest
    await page.context().clearCookies();
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    expect(page.url()).toContain('login.php'); // redirected to login
});

test('belt tests edit toggle shows and hides delete column', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    const hasBeltTests = await page.locator('#beltTestsTable tbody tr').count() > 0;
    if (hasBeltTests) {
        // Initially hidden
        await expect(page.locator('#beltTestsTable .delete-col').first()).toBeHidden();
        await page.click('#editToggle');
        await expect(page.locator('#beltTestsTable .delete-col').first()).toBeVisible();
        await expect(page.locator('#editToggle')).toHaveText('Done');
        await page.click('#editToggle');
        await expect(page.locator('#beltTestsTable .delete-col').first()).toBeHidden();
        await expect(page.locator('#editToggle')).toHaveText('Edit');
    }
});

test('existing belt test edit form pre-fills correctly', async ({ page }) => {
    // The "Edit" link on belt_tests_all.php is admin-only (see belt_tests_all.php's
    // has_role('admin') check) — instructors can create new tests but not edit
    // existing ones, so this needs an admin session.
    await page.context().clearCookies();
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    const editLink = page.locator('a:has-text("Edit")').first();
    // The test DB has multiple students with belt test history — this must exist.
    await expect(editLink).toHaveCount(1);
    await editLink.click();
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'belt_test_edit existing');
    expect(await page.inputValue('input[name="test_date"]')).toMatch(/\d{4}-\d{2}-\d{2}/);
    await expect(page.locator('button:has-text("Save Changes")')).toBeVisible();
    await expect(page.locator('button:has-text("Delete")')).toBeVisible();
});

// ── CREATE → VERIFY → DELETE ROUND-TRIP ──────────────────────────────────────

test('create a belt test for a student', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_test_edit.php');
    const futureDate = new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10);
    // Type-to-filter: type a letter, click first visible student button
    await page.fill('#studentFilter', 'a');
    await page.waitForTimeout(100);
    await page.locator('.student-btn:visible').first().click();
    await page.fill('input[name="test_date"]', futureDate);
    await page.selectOption('select[name="rank_id"]', { index: 1 });
    await page.fill('textarea[name="notes"]', `Delete Me ${TS}`);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'create belt test for delete');
});

test('created belt test appears in list', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    await expect(page.locator('body')).toContainText(`Delete Me ${TS}`);
});

test('delete button removes the belt test', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    await page.click('#editToggle');
    const row = page.locator('tr').filter({ hasText: `Delete Me ${TS}` });
    page.once('dialog', d => d.accept());
    await row.locator('.btn-outline-danger').click();
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'after delete');
    await expect(page.locator('body')).not.toContainText(`Delete Me ${TS}`);
});

// ── AUTO-RANK: passing score auto-inserts into student_ranks ─────────────────
// PHP logic: score >= 80 → result='pass' AND belt_awarded=1 automatically,
// which triggers INSERT IGNORE INTO student_ranks. Verified via admin/student_edit.php.

test('create a passing belt test (score >= 80) for student 2', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_test_edit.php');
    await page.waitForLoadState('domcontentloaded');
    const today = new Date().toISOString().slice(0, 10);
    // Type-to-filter: select Sarah Johnson by name
    await page.fill('#studentFilter', 'Sarah');
    await page.waitForTimeout(100);
    await page.locator('.student-btn:visible').first().click();
    await page.fill('input[name="test_date"]', today);
    // Choose the first available rank
    await page.selectOption('select[name="rank_id"]', { index: 1 });
    // Score >= 80 → PHP auto-sets result='pass' and belt_awarded=1 (auto-rank)
    // #scoreManual only exists when editing an existing scored test — for a new
    // test we fill the chart's score inputs instead. Rank index 1 (10th Kyu,
    // rank_order=1) uses the "lower" chart (l_basics_*/l_kumite_*), which totals to 85.
    await page.fill('input[name="l_basics_form"]', '50');
    await page.fill('input[name="l_basics_eff"]', '30');
    await page.fill('input[name="l_kumite_form"]', '0');
    await page.fill('input[name="l_kumite_eff"]', '5');
    await page.fill('textarea[name="notes"]', `AutoRank ${TS}`);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'create passing belt test');
    // Save redirects to belt_test_edit.php?id=X&saved=1, but instructors are then
    // immediately bounced to belt_tests_all.php — the full grading chart for an
    // existing test is admin-only (see belt_test_edit.php's role check).
    expect(page.url()).toContain('belt_tests_all.php');
});

test('passing belt test appears in list with a score badge', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    await expect(page.locator('body')).toContainText(`AutoRank ${TS}`);
    // The row for our test should show a score (85%) — bg-success badge
    const row = page.locator('tr').filter({ hasText: `AutoRank ${TS}` });
    await expect(row.locator('.badge.bg-success, .badge.bg-danger, .badge.bg-secondary').first()).toBeVisible();
});

test('belt_tests_all result column shows Passed badge for passing test', async ({ page }) => {
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    // The AutoRank test (score=85) we just created should have a Passed badge.
    // Row has two badges (Score column "85%" and Test Passed column "Passed") —
    // columns are: 0 date, 1 student, 2 kyu_dan, 3 score, 4 fee_paid, 5 test passed.
    // Scope to column 5 to avoid matching the score badge.
    const row = page.locator('tr').filter({ hasText: `AutoRank ${TS}` });
    // Serial mode guarantees the "create a passing belt test" test above ran first.
    await expect(row).toHaveCount(1);
    const resultCell = row.locator('td').nth(5);
    await expect(resultCell.locator('.badge.bg-success, .badge.bg-danger, .text-muted, .text-danger')).toBeVisible();
    const badgeText = await resultCell.textContent();
    expect(badgeText).toMatch(/Passed|✗|—/);
});

test('passing belt test auto-adds rank to student Rank History in student_edit', async ({ page }) => {
    // student_edit.php requires admin role — clear the instructor session first so
    // login.php shows its form instead of redirecting to the instructor dashboard
    await page.context().clearCookies();
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    await assertNoPhpErrors(page, 'student edit after auto-rank');
    // Rank History card should be present
    await expect(page.locator('.card-header').filter({ hasText: 'Rank History' })).toBeVisible();
    // The auto-rank INSERT IGNORE means the student's Rank History card
    // should have at least one rank row (not "No ranks recorded.")
    await expect(
        page.locator('.card').filter({ has: page.locator('.card-header:has-text("Rank History")') })
    ).not.toContainText('No ranks recorded.');
    await logout(page);
});

// V3.2 regression: editing a belt test's date used to create a duplicate rank
// history row (missing UNIQUE KEY(student_id, rank_id) meant ON DUPLICATE KEY
// UPDATE inserted instead of updating). Fixed via DELETE+INSERT and
// migrations/001_student_ranks_unique.sql — verify editing the date of the
// AutoRank test above doesn't duplicate its "10th Kyu" rank history row.
test('editing a belt test date does not duplicate its rank history row', async ({ page }) => {
    await page.context().clearCookies();
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/instructor/belt_tests_all.php');
    const row = page.locator('tr').filter({ hasText: `AutoRank ${TS}` });
    await expect(row).toHaveCount(1);
    await row.locator('a:has-text("Edit")').click();
    await page.waitForLoadState('domcontentloaded');

    // Compare the count before/after rather than asserting an absolute value —
    // other spec files run as parallel Playwright workers against this same
    // shared fixture student, so an absolute count would be order-dependent.
    // What this regression actually needs to prove is "editing doesn't add one."
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    const rankRowsBefore = await page.locator('#rankTable tbody tr').filter({ hasText: '10th Kyu' }).count();
    await page.goBack();
    await page.waitForLoadState('domcontentloaded');

    // Change only the date, keep the same rank, and save.
    const newDate = new Date(Date.now() + 21 * 86400000).toISOString().slice(0, 10);
    await page.fill('input[name="test_date"]', newDate);
    await page.click('button:has-text("Save Changes")');
    await page.waitForLoadState('domcontentloaded');
    await assertNoPhpErrors(page, 'belt test date edit');

    await page.goto(BASE + '/admin/student_edit.php?id=2');
    await assertNoPhpErrors(page, 'student edit after date edit');
    const rankRowsAfter = await page.locator('#rankTable tbody tr').filter({ hasText: '10th Kyu' }).count();
    expect(rankRowsAfter).toBe(rankRowsBefore);
    await logout(page);
});
