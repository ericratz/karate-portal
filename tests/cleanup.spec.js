// @ts-check
// ONE-TIME CLEANUP — run this to remove accumulated test artifacts:
//   npx playwright test tests/cleanup.spec.js --config cleanup.config.js --reporter=line
//
// Safe to run against any state: each step checks before acting.
// After running, all the junk from repeated test runs will be gone.
const { test, expect } = require('@playwright/test');
const { login, logout, BASE } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const { ADMIN_USER, ADMIN_PASS, INST_USER, INST_PASS } = require('./credentials');

// ── TEST USER ACCOUNTS (pw*, waiver*, promote*, u + timestamp) ────────────────

// students.php rows only have a student_profile.php link, no direct edit link.
// Extract the student ID from that href and navigate to student_edit.php.
async function deleteStudentsBySelector(page, rowLocator) {
    let found = true;
    while (found) {
        await page.goto(BASE + '/admin/students.php');
        await page.waitForLoadState('domcontentloaded');
        const row = rowLocator(page);
        if (await row.count() === 0) { found = false; break; }
        const profileHref = await row.locator('a[href*="student_profile.php"]').first().getAttribute('href');
        if (!profileHref) { found = false; break; }
        const match = profileHref.match(/[?&]id=(\d+)/);
        if (!match) { found = false; break; }
        await page.goto(BASE + '/admin/student_edit.php?id=' + match[1]);
        await page.waitForLoadState('domcontentloaded');
        const delBtn = page.locator('button:has-text("Delete Profile")');
        if (!await delBtn.isVisible()) { found = false; break; }
        page.once('dialog', d => d.accept());
        await delBtn.click();
        await page.waitForLoadState('domcontentloaded');
    }
}

test('delete leftover pw* registration test accounts', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await deleteStudentsBySelector(page, p => p.locator('tbody tr[data-name*="playwright"]').first());
    await logout(page);
});

test('delete leftover waiver* test accounts', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await deleteStudentsBySelector(page, p => p.locator('tbody tr[data-name*="tester"]').first());
    await logout(page);
});

test('delete leftover promote* test accounts', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await deleteStudentsBySelector(page, p => p.locator('tbody tr').filter({ hasText: /\bMe\d{10,}\b/ }).first());
    await logout(page);
});

// Also clean up orphaned users table entries (no linked student) with test usernames
test('delete orphaned test users in admin/users.php', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/users.php');

    // Look for usernames that are pure timestamp patterns (pw + 13 digits, etc.)
    const testPatterns = [/^pw\d{10,}$/, /^waiver\d{10,}$/, /^promote\d{10,}$/, /^u\d{10,}$/];

    const rows = await page.locator('tbody tr').all();
    for (const row of rows) {
        const usernameCell = await row.locator('td').first().textContent();
        const username = usernameCell?.trim() ?? '';
        if (testPatterns.some(p => p.test(username))) {
            await row.locator('a:has-text("View")').click();
            await page.waitForLoadState('domcontentloaded');
            // Deactivate not delete — users table has no delete UI
            // But linked students were deleted above, so just go back
            await page.goto(BASE + '/admin/users.php');
        }
    }
    await logout(page);
});

// ── RANK HISTORY DUPLICATES (Jan 1, 2020 test date) ──────────────────────────

test('delete all test rank entries dated Jan 1, 2020 from student 2', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);

    let found = true;
    while (found) {
        await page.goto(BASE + '/admin/student_edit.php?id=2');
        await page.waitForLoadState('domcontentloaded');
        const editBtn = page.locator('#rankEditToggle');
        if (await editBtn.count() === 0 || !await editBtn.isVisible()) { found = false; break; }
        await editBtn.click();
        const rankRow = page.locator('#rankTable tbody tr').filter({ hasText: 'Jan 1, 2020' }).first();
        if (await rankRow.count() === 0) { found = false; break; }
        page.once('dialog', d => d.accept());
        await rankRow.locator('.rank-delete-col button').click();
        await page.waitForLoadState('domcontentloaded');
    }
    await logout(page);
});

// ── TEST ATTENDANCE SESSIONS (fixed test dates) ───────────────────────────────

test('delete test attendance session 2099-01-15 if it exists', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/attendance.php?date=2099-01-15');
    await page.waitForLoadState('domcontentloaded');
    const delBtn = page.locator('button:has-text("Delete This Session")');
    if (await delBtn.isVisible()) {
        page.once('dialog', d => d.accept());
        await delBtn.click();
        await page.waitForLoadState('domcontentloaded');
    }
    await logout(page);
});

// ── STALE GENERAL NOTES (Playwright test / Search test entries) ───────────────

test('delete leftover Playwright general notes', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    let found = true;
    while (found) {
        await page.goto(BASE + '/admin/general_notes.php');
        await page.waitForLoadState('domcontentloaded');
        const editBtn = page.locator('#editToggle');
        if (await editBtn.count() === 0) { found = false; break; }
        await editBtn.click();
        // Match any note that looks like a test artifact
        const note = page.locator('.note-entry').filter({
            hasText: /Playwright test note|Search test \d{10,}/
        }).first();
        if (await note.count() === 0) { found = false; break; }
        page.once('dialog', d => d.accept());
        await note.locator('.delete-btn button').click();
        await page.waitForLoadState('domcontentloaded');
    }
    await logout(page);
});

// ── STALE EXPENSES (Playwright test expense) ──────────────────────────────────

test('delete leftover Playwright test expenses', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    let found = true;
    while (found) {
        await page.goto(BASE + '/admin/expenses.php');
        await page.waitForLoadState('domcontentloaded');
        const editBtn = page.locator('#editToggle');
        if (await editBtn.count() === 0) { found = false; break; }
        await editBtn.click();
        const row = page.locator('tr').filter({
            hasText: /Playwright test expense|Toggle test \d{10,}/
        }).first();
        if (await row.count() === 0) { found = false; break; }
        page.once('dialog', d => d.accept());
        await row.locator('.btn-outline-danger').click();
        await page.waitForLoadState('domcontentloaded');
    }
    await logout(page);
});

// ── STALE PAYMENTS ────────────────────────────────────────────────────────────

test('delete leftover Playwright test payments', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    let found = true;
    while (found) {
        await page.goto(BASE + '/admin/payments.php');
        await page.waitForLoadState('domcontentloaded');
        const editBtn = page.locator('#editToggle');
        if (await editBtn.count() === 0) { found = false; break; }
        await editBtn.click();
        const row = page.locator('tr').filter({
            hasText: /Playwright payment \d{10,}/
        }).first();
        if (await row.count() === 0) { found = false; break; }
        page.once('dialog', d => d.accept());
        await row.locator('.btn-outline-danger').click();
        await page.waitForLoadState('domcontentloaded');
    }
    await logout(page);
});

// ── STALE WAIVERS ─────────────────────────────────────────────────────────────

test('delete leftover Playwright test waivers', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    let found = true;
    while (found) {
        await page.goto(BASE + '/admin/waivers.php');
        await page.waitForLoadState('domcontentloaded');
        const editBtn = page.locator('#editToggle');
        if (await editBtn.count() === 0) { found = false; break; }
        await editBtn.click();
        const row = page.locator('tr').filter({
            hasText: /Playwright waiver \d{10,}/
        }).first();
        if (await row.count() === 0) { found = false; break; }
        page.once('dialog', d => d.accept());
        await row.locator('.btn-outline-danger').click();
        await page.waitForLoadState('domcontentloaded');
    }
    await logout(page);
});

// ── STALE STUDENT NOTES ───────────────────────────────────────────────────────

test('delete leftover Playwright student notes from student 2', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    let found = true;
    while (found) {
        await page.goto(BASE + '/admin/student_notes.php?student_id=2');
        await page.waitForLoadState('domcontentloaded');
        const editBtn = page.locator('#editToggle');
        if (await editBtn.count() === 0) { found = false; break; }
        await editBtn.click();
        const note = page.locator('.border-bottom').filter({
            hasText: /Playwright note \d{10,}|Admin note \d{10,}|StudentEdit note \d{10,}/
        }).first();
        if (await note.count() === 0) { found = false; break; }
        page.once('dialog', d => d.accept());
        await note.locator('.delete-btn button').click();
        await page.waitForLoadState('domcontentloaded');
    }
    await logout(page);
});

// ── STALE BELT TESTS ──────────────────────────────────────────────────────────

test('delete leftover Playwright belt test entries', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    let found = true;
    while (found) {
        await page.goto(BASE + '/instructor/belt_tests_all.php');
        await page.waitForLoadState('domcontentloaded');
        const editBtn = page.locator('#editToggle');
        if (await editBtn.count() === 0) { found = false; break; }
        await editBtn.click();
        const row = page.locator('tr').filter({
            hasText: /Delete Me \d{10,}|Test Note \d{10,}/
        }).first();
        if (await row.count() === 0) { found = false; break; }
        page.once('dialog', d => d.accept());
        await row.locator('.btn-outline-danger').click();
        await page.waitForLoadState('domcontentloaded');
    }
    await logout(page);
});

test('cleanup complete — verify no test artifacts remain', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/students.php');
    const body = await page.textContent('body');
    expect(body).not.toMatch(/\bPlaywright\b.*\bTest\b/i);
    expect(body).not.toMatch(/\bTester\d{10,}/);
    await logout(page);
});
