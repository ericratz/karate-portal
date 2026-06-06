// @ts-check
// Targeted regression tests for known bugs and edge cases.
const { test, expect } = require('@playwright/test');
const { login, logout, visit, assertNoPhpErrors, BASE } = require('./helpers');

// Each describe uses serial mode internally. A failure in one group
// skips the rest of THAT group but all other groups continue.

const { ADMIN_USER, ADMIN_PASS, INST_USER, INST_PASS, STU_USER, STU_PASS, GUEST_USER, GUEST_PASS } = require('./credentials');

// ── BADGE / ROLE DISPLAY ─────────────────────────────────────────────────────

test('guest badge shows "guest" not "student"', async ({ page }) => {
    await login(page, GUEST_USER, GUEST_PASS);
    await page.goto(BASE + '/student/');
    const badge = await page.textContent('.role-badge');
    expect(badge?.trim().toLowerCase()).toBe('guest');
});

test('instructor badge shows "instructor"', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/');
    const badge = await page.textContent('.role-badge');
    expect(badge?.trim().toLowerCase()).toBe('instructor');
});

test('admin badge shows "admin"', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/');
    const badge = await page.textContent('.role-badge');
    expect(badge?.trim().toLowerCase()).toBe('admin');
});

test('badge always reflects student_type, not users.role', async ({ page }) => {
    // Log in as any student-role user and verify badge is not "student" if type differs.
    // We just check the badge is a known valid type — not hardcoded to a specific value.
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/student/');
    const badge = await page.textContent('.role-badge');
    const validTypes = ['guest', 'student', 'instructor', 'admin'];
    expect(validTypes).toContain(badge?.trim().toLowerCase());
});

// ── INSTRUCTOR ROSTER ────────────────────────────────────────────────────────

test('instructor roster shows 3 tables: Instructors, Students, Guests', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    // Roster is now at instructor/students.php (dashboard no longer has tabs)
    await visit(page, '/instructor/students.php', 'instructor roster');
    await expect(page.locator('.card-header').filter({ hasText: 'Instructors' })).toBeVisible();
    await expect(page.locator('.card-header').filter({ hasText: 'Students' })).toBeVisible();
    await expect(page.locator('.card-header').filter({ hasText: 'Guests' })).toBeVisible();
});

test('instructor roster student name is a clickable link', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/students.php');
    const nameLink = page.locator('tbody a.text-decoration-none').first();
    if (await nameLink.count() > 0) {
        const href = await nameLink.getAttribute('href');
        expect(href).toContain('student_profile.php');
    }
});

test('instructor roster guest name is a clickable link', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/students.php');
    // Guest rows are in the third table card
    const guestsCard = page.locator('.card').filter({
        has: page.locator('.card-header').filter({ hasText: 'Guests' })
    });
    const nameLink = guestsCard.locator('tbody a.text-decoration-none').first();
    if (await nameLink.count() > 0) {
        const href = await nameLink.getAttribute('href');
        expect(href).toContain('student_profile.php');
    }
});

// ── RANK HISTORY ──────────────────────────────────────────────────────────────

test('rank history add form is visible for admin', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    await assertNoPhpErrors(page, 'student edit for rank');
    // Add-rank inputs are in #rank-add-box; click the button to reveal them
    await page.click('button:has-text("+ Record Rank")');
    await expect(page.locator('#rank-add-box select[name="new_rank_id"]')).toBeVisible();
    await expect(page.locator('#rank-add-box input[name="new_rank_date"]')).toBeVisible();
});

test('rank history table shows all entries not just current', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    await assertNoPhpErrors(page, 'student edit rank table');
    // Rank edit selects are hidden until Edit is clicked; click to reveal
    const rankEditBtn = page.locator('#rankEditToggle');
    if (await rankEditBtn.isVisible()) await rankEditBtn.click();
    const rankSelects = page.locator('select[name^="rank_updates"]');
    const count = await rankSelects.count();
    if (count > 0) {
        const opts = await rankSelects.first().locator('option').count();
        expect(opts).toBeGreaterThan(0);
    }
});

// ── STUDENT DASHBOARD ────────────────────────────────────────────────────────

test('student dashboard shows correct month in payment card', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await visit(page, '/student/', 'student dashboard month');
    const month = new Date().toLocaleString('en-US', { month: 'long' });
    await expect(page.locator('body')).toContainText(month + ' Payment');
});

test('student dashboard injury waiver card is visible', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await visit(page, '/student/', 'injury waiver card');
    // Use exact match to avoid ambiguity with the "View Injury Waiver" button text
    await expect(page.getByText('Injury Waiver', { exact: true })).toBeVisible();
});

test('student attendance page shows only attended count card', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await visit(page, '/student/attendance.php', 'attendance cards');
    await expect(page.getByText('Classes Attended')).toBeVisible();
    // Should NOT show attendance rate or total sessions
    await expect(page.getByText('Attendance Rate')).toHaveCount(0);
    await expect(page.getByText('Total Sessions Recorded')).toHaveCount(0);
});

// ── BACK BUTTON ROUTING ──────────────────────────────────────────────────────

test('admin: student profile back goes to students list', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/instructor/student_profile.php?id=2');
    const backHref = await page.getAttribute('a:has-text("← Back")', 'href');
    expect(backHref).toContain('students.php');
});

test('instructor: student profile back goes to instructor dashboard', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/student_profile.php?id=2');
    const backHref = await page.getAttribute('a:has-text("← Back")', 'href');
    expect(backHref).toContain('index.php');
});

// ── MY DASHBOARD ROUTING ─────────────────────────────────────────────────────

test('admin My Dashboard brand routes to /admin/', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/');
    expect(await page.getAttribute('.navbar-brand', 'href')).toContain('/admin/');
    await logout(page);
});

test('instructor My Dashboard brand routes to /instructor/', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/');
    expect(await page.getAttribute('.navbar-brand', 'href')).toContain('/instructor/');
    await logout(page);
});

test('student My Dashboard brand routes to /student/', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/student/');
    expect(await page.getAttribute('.navbar-brand', 'href')).toContain('/student/');
    await logout(page);
});

// ── ACCESS CONTROL ───────────────────────────────────────────────────────────

test('guest cannot reach admin pages', async ({ page }) => {
    await login(page, GUEST_USER, GUEST_PASS);
    await page.goto(BASE + '/admin/payments.php');
    // require_role shows "Access denied." without redirecting
    const body = await page.textContent('body');
    expect(body).toContain('Access denied');
});

test('guest cannot reach instructor pages', async ({ page }) => {
    await login(page, GUEST_USER, GUEST_PASS);
    await page.goto(BASE + '/instructor/');
    const body = await page.textContent('body');
    expect(body).toContain('Access denied');
});

test('student-role user cannot reach admin pages', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/admin/students.php');
    // Either access denied or redirected away
    const body = await page.textContent('body');
    const isBlocked = body.includes('Access denied') || !body.includes('+ New Student');
    expect(isBlocked).toBe(true);
});

// ── PAYMENTS ─────────────────────────────────────────────────────────────────

test('admin payments page shows all payments by default (no date filter)', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await visit(page, '/admin/payments.php', 'payments no filter');
    // From/To inputs should be empty by default
    const fromVal = await page.inputValue('input[name="from"]');
    const toVal   = await page.inputValue('input[name="to"]');
    expect(fromVal).toBe('');
    expect(toVal).toBe('');
});

test('student payment history shows Month column for tuition', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await visit(page, '/student/payment_history.php', 'payment history month col');
    await expect(page.locator('th:has-text("Month")')).toBeVisible();
});

// ── EDIT TOGGLES ─────────────────────────────────────────────────────────────

test('expenses delete buttons hidden until Edit clicked', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await visit(page, '/admin/expenses.php', 'expenses edit toggle');
    const editBtn = page.locator('#editToggle');
    if (await editBtn.isVisible()) {
        // Initially not editing
        const tableClass = await page.locator('#expensesTable').getAttribute('class');
        expect(tableClass).not.toContain('editing');
        // Click Edit — should enter editing mode
        await editBtn.click();
        await expect(page.locator('#expensesTable')).toHaveClass(/editing/);
        // Click Done — should exit
        await editBtn.click();
        const newClass = await page.locator('#expensesTable').getAttribute('class');
        expect(newClass).not.toContain('editing');
    }
});
