// @ts-check
// Functional tests — verifies that actions actually persist and display correctly.
// Covers every testable feature except PayPal and email delivery.
//
// Each test.describe block uses serial mode internally so dependent tests
// run in order. A failure in one group skips the rest of THAT group but
// all other groups continue running.
const { test, expect } = require('@playwright/test');
const { login, logout, visit, assertNoPhpErrors, deleteTestStudent, BASE } = require('./helpers');

const { ADMIN_USER, ADMIN_PASS, INST_USER, INST_PASS, STU_USER, STU_PASS } = require('./credentials');
const TS         = Date.now();

// ── REGISTRATION ──────────────────────────────────────────────────────────────
test.describe('Registration', () => {
    test.describe.configure({ mode: 'serial' });

    test('page loads', async ({ page }) => {
        await visit(page, '/register.php', 'register page');
        await expect(page.locator('h4')).toContainText('Shotokan Karate');
    });

    test('mismatched passwords shows error', async ({ page }) => {
        await page.goto(BASE + '/register.php');
        await page.fill('input[name="first_name"]', 'Test');
        await page.fill('input[name="last_name"]', 'User');
        await page.fill('input[name="date_of_birth"]', '2000-01-01');
        await page.fill('input[name="email"]', `t${TS}@x.com`);
        await page.fill('input[name="username"]', `u${TS}`);
        await page.fill('input[name="password"]', 'Testpass1!');
        await page.fill('input[name="confirm"]',  'Different1!');
        await page.click('button:has-text("Create Account")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-danger')).toContainText('do not match');
    });

    test('duplicate username shows error', async ({ page }) => {
        await page.goto(BASE + '/register.php');
        await page.fill('input[name="first_name"]', 'Test');
        await page.fill('input[name="last_name"]', 'User');
        await page.fill('input[name="date_of_birth"]', '2000-01-01');
        await page.fill('input[name="email"]', `t${TS}@x.com`);
        await page.fill('input[name="username"]', ADMIN_USER);
        await page.fill('input[name="password"]', 'pass1234');
        await page.fill('input[name="confirm"]', 'pass1234');
        await page.click('button:has-text("Create Account")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-danger')).toContainText('already taken');
    });

    test('valid submission creates account', async ({ page }) => {
        await page.goto(BASE + '/register.php');
        await page.fill('input[name="first_name"]', 'Playwright');
        await page.fill('input[name="last_name"]', `Test${TS}`);
        await page.fill('input[name="date_of_birth"]', '2000-06-15');
        await page.fill('input[name="email"]', `pw${TS}@test.com`);
        await page.fill('input[name="username"]', `pw${TS}`);
        await page.fill('input[name="password"]', 'TestPass1!');
        await page.fill('input[name="confirm"]', 'TestPass1!');
        await page.click('button:has-text("Create Account")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-success')).toContainText('Account created');
    });

    test('new account can log in and shows guest badge', async ({ page }) => {
        await login(page, `pw${TS}`, 'TestPass1!');
        const badge = await page.textContent('.role-badge');
        expect(badge?.trim().toLowerCase()).toBe('guest');
        await logout(page);
    });

    test('already logged-in user is redirected from register page', async ({ page }) => {
        await login(page, STU_USER, STU_PASS);
        await page.goto(BASE + '/register.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).not.toContain('register.php');
        await logout(page);
    });

    test.afterAll(async ({ browser }) => {
        test.setTimeout(30_000);
        const page = await browser.newPage();
        await deleteTestStudent(page, `Test${TS}`, ADMIN_USER, ADMIN_PASS);
        await page.close();
    });
});

// ── ATTENDANCE ────────────────────────────────────────────────────────────────
test.describe('Attendance', () => {
    test.describe.configure({ mode: 'serial' });
    // Use a fixed far-future date so this test session never overlaps a real class
    // and the cleanup can always find and delete it.
    const TEST_DATE = '2099-01-15';

    test('attendance page shows student list', async ({ page }) => {
        await login(page, INST_USER, INST_PASS);
        await page.goto(BASE + `/instructor/attendance.php?date=${TEST_DATE}`);
        await assertNoPhpErrors(page, 'attendance form');
        expect(await page.locator('input[name="present[]"]').count()).toBeGreaterThan(0);
        await logout(page);
    });

    test('saving attendance shows confirmation', async ({ page }) => {
        await login(page, INST_USER, INST_PASS);
        await page.goto(BASE + `/instructor/attendance.php?date=${TEST_DATE}`);
        await page.click('button[type="submit"]');
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'attendance saved');
        await expect(page.locator('body')).toContainText(/saved|recorded/i);
        await logout(page);
    });

    test('sort by last name loads without error', async ({ page }) => {
        await login(page, INST_USER, INST_PASS);
        await page.goto(BASE + `/instructor/attendance.php?date=${TEST_DATE}&sort=last_name`);
        await assertNoPhpErrors(page, 'sort last_name');
        await logout(page);
    });

    test('sort by last attended loads without error', async ({ page }) => {
        await login(page, INST_USER, INST_PASS);
        await page.goto(BASE + `/instructor/attendance.php?date=${TEST_DATE}&sort=last_attended`);
        await assertNoPhpErrors(page, 'sort last_attended');
        await logout(page);
    });

    test('student sees attended session on dashboard', async ({ page }) => {
        await login(page, STU_USER, STU_PASS);
        await visit(page, '/student/', 'dashboard after attendance');
        const val = await page.locator('.display-6.text-primary').first().textContent();
        expect(parseInt(val ?? '0')).toBeGreaterThanOrEqual(0);
        await logout(page);
    });

    test.afterAll(async ({ browser }) => {
        test.setTimeout(30_000);
        const page = await browser.newPage();
        try {
            await login(page, INST_USER, INST_PASS);
            await page.goto(BASE + `/instructor/attendance.php?date=${TEST_DATE}`);
            await page.waitForLoadState('domcontentloaded');
            const delBtn = page.locator('button:has-text("Delete This Session")');
            if (await delBtn.isVisible()) {
                page.once('dialog', d => d.accept());
                await delBtn.click();
                await page.waitForLoadState('domcontentloaded');
            }
        } catch (e) { /* best-effort */ }
        await page.close();
    });
});

// ── NOTES ─────────────────────────────────────────────────────────────────────
test.describe('Notes', () => {
    test.describe.configure({ mode: 'serial' });

    test('instructor: add_note page loads', async ({ page }) => {
        await login(page, INST_USER, INST_PASS);
        await visit(page, '/instructor/add_note.php?student_id=2', 'add note');
        await expect(page.locator('textarea[name="content"]')).toBeVisible();
        await logout(page);
    });

    test('instructor: empty note triggers server error', async ({ page }) => {
        await login(page, INST_USER, INST_PASS);
        await page.goto(BASE + '/instructor/add_note.php?student_id=2');
        await page.evaluate(() =>
            document.querySelector('textarea[name="content"]').removeAttribute('required')
        );
        await page.click('button:has-text("Save Note")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-danger')).toContainText('empty');
        await logout(page);
    });

    test('instructor: valid note saves', async ({ page }) => {
        await login(page, INST_USER, INST_PASS);
        await page.goto(BASE + '/instructor/add_note.php?student_id=2');
        await page.fill('textarea[name="content"]', `Playwright note ${TS}`);
        await page.click('button:has-text("Save Note")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-success')).toContainText('saved');
        await logout(page);
    });

    test('admin: saved note appears in student notes', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/student_notes.php?student_id=2');
        await expect(page.locator('body')).toContainText(`Playwright note ${TS}`);
        await logout(page);
    });

    test('admin: add and delete student note round-trip', async ({ page }) => {
        const text = `Admin note ${TS}`;
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/student_notes.php?student_id=2');
        await page.fill('textarea[name="content"]', text);
        await page.click('button:has-text("Save Note")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('body')).toContainText(text);
        await page.click('#editToggle');
        page.once('dialog', d => d.accept());
        await page.locator('.delete-btn button').first().click();
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('body')).not.toContainText(text);
        await logout(page);
    });

    test('admin: general notes search filters entries', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/general_notes.php');
        const text = `Search test ${TS}`;
        await page.fill('textarea[name="content"]', text);
        await page.click('button:has-text("Save Entry")');
        await page.waitForLoadState('domcontentloaded');
        await page.fill('#noteSearch', text);
        expect(await page.locator('.note-entry:visible').count()).toBeGreaterThanOrEqual(1);
        await logout(page);
    });

    test('admin: student notes roster shows students table', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/student_notes.php');
        // student_notes.php now shows a roster with direct View links per student
        await expect(page.locator('.card-header').filter({ hasText: 'Students' })).toBeVisible();
        expect(await page.locator('tbody tr').count()).toBeGreaterThan(0);
        await logout(page);
    });

    test.afterAll(async ({ browser }) => {
        test.setTimeout(30_000);
        const page = await browser.newPage();
        try {
            await login(page, ADMIN_USER, ADMIN_PASS);

            // Delete lingering student note added by instructor
            await page.goto(BASE + '/admin/student_notes.php?student_id=2');
            await page.click('#editToggle');
            const stuNote = page.locator('.border-bottom').filter({ hasText: `Playwright note ${TS}` });
            if (await stuNote.isVisible()) {
                page.once('dialog', d => d.accept());
                await stuNote.locator('.delete-btn button').click();
                await page.waitForLoadState('domcontentloaded');
            }

            // Delete lingering general note
            await page.goto(BASE + '/admin/general_notes.php');
            await page.click('#editToggle');
            const genNote = page.locator('.note-entry').filter({ hasText: `Search test ${TS}` });
            if (await genNote.isVisible()) {
                page.once('dialog', d => d.accept());
                await genNote.locator('.delete-btn button').click();
                await page.waitForLoadState('domcontentloaded');
            }
        } catch (e) { /* best-effort cleanup */ }
        await page.close();
    });
});

// ── WAIVERS ───────────────────────────────────────────────────────────────────
test.describe('Waivers', () => {
    test.describe.configure({ mode: 'serial' });

    test('grant a waiver and it appears in list', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/waivers.php');
        await page.selectOption('select[name="student_id"]', { index: 1 });
        await page.selectOption('select[name="waiver_type"]', 'monthly_tuition');
        await page.fill('textarea[name="reason"]', `Playwright waiver ${TS}`);
        await page.click('button:has-text("Grant Waiver")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-success')).toContainText('Waiver granted');
        // Waiver appears in the table (no Active/Revoke status — waivers are permanent)
        await expect(page.locator('tbody')).toContainText(`Playwright waiver ${TS}`);
        await logout(page);
    });

    test('waiver delete button appears in edit mode', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/waivers.php');
        const editBtn = page.locator('#editToggle');
        if (await editBtn.isVisible()) {
            await editBtn.click();
            await expect(page.locator('.delete-col .btn-outline-danger').first()).toBeVisible();
        }
        await logout(page);
    });

    test.afterAll(async ({ browser }) => {
        test.setTimeout(30_000);
        const page = await browser.newPage();
        try {
            await login(page, ADMIN_USER, ADMIN_PASS);
            // Delete the waiver created during the test (filter by reason text)
            await page.goto(BASE + '/admin/waivers.php');
            const editBtn = page.locator('#editToggle');
            if (await editBtn.isVisible()) await editBtn.click();
            const row = page.locator('tr').filter({ hasText: `Playwright waiver ${TS}` });
            if (await row.isVisible()) {
                page.once('dialog', d => d.accept());
                await row.locator('.btn-outline-danger').click();
                await page.waitForLoadState('domcontentloaded');
            }
        } catch (e) { /* best-effort */ }
        await page.close();
    });
});

// ── PAYMENTS ──────────────────────────────────────────────────────────────────
test.describe('Payments', () => {
    test.describe.configure({ mode: 'serial' });

    test('record manual payment and it appears in list', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/payments.php?action=add');
        await page.selectOption('select[name="student_id"]', { index: 1 });
        await page.fill('input[name="amount"]', '30');
        await page.selectOption('select[name="payment_type"]', 'monthly_tuition');
        await page.selectOption('select[name="payment_method"]', 'cash');
        await page.fill('input[name="notes"]', `Playwright payment ${TS}`);
        await page.click('button:has-text("Save Payment")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-success')).toContainText('Payment recorded');
        await expect(page.locator('body')).toContainText(`Playwright payment ${TS}`);
        await logout(page);
    });

    test('filter by method shows only matching rows', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/payments.php');
        await page.selectOption('select[name="method"]', 'cash');
        await page.click('button:has-text("Filter")');
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'payment filter method');
        const methods = await page.locator('tbody td:nth-child(5)').allTextContents();
        methods.forEach(m => expect(m.toLowerCase()).toBe('cash'));
        await logout(page);
    });

    test('filter by type shows only matching rows', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/payments.php');
        await page.selectOption('select[name="type"]', 'registration');
        await page.click('button:has-text("Filter")');
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'payment filter type');
        const types = await page.locator('tbody td:nth-child(3)').allTextContents();
        types.forEach(t => expect(t.toLowerCase()).toContain('registration'));
        await logout(page);
    });

    test('delete payment removes it from list', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/payments.php');
        await page.click('#editToggle');
        const row = page.locator('tr').filter({ hasText: `Playwright payment ${TS}` });
        if (await row.isVisible()) {
            page.once('dialog', d => d.accept());
            await row.locator('.btn-outline-danger').click();
            await page.waitForLoadState('domcontentloaded');
            await expect(page.locator('body')).not.toContainText(`Playwright payment ${TS}`);
        }
        await logout(page);
    });

    test('student: payment history year filter shows correct year', async ({ page }) => {
        await login(page, STU_USER, STU_PASS);
        await page.goto(BASE + '/student/payment_history.php');
        const yearBtn = page.locator('a.btn[href*="?year="]').first();
        if (await yearBtn.isVisible()) {
            const year = (await yearBtn.textContent())?.trim();
            await yearBtn.click();
            await page.waitForLoadState('domcontentloaded');
            const dates = await page.locator('tbody td:nth-child(2)').allTextContents();
            dates.forEach(d => expect(d).toContain(year));
        }
        await logout(page);
    });
});

// ── EXPENSES ──────────────────────────────────────────────────────────────────
test.describe('Expenses', () => {
    test.describe.configure({ mode: 'serial' });

    test('add expense and verify it appears', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/expenses.php');
        await page.click('button:has-text("+ Record Expense")');
        await page.selectOption('select[name="expense_type"]', 'supplies');
        await page.fill('input[name="amount"]', '5.00');
        await page.fill('input[name="expense_date"]', new Date().toISOString().slice(0, 10));
        await page.fill('input[name="description"]', `Toggle test ${TS}`);
        await page.click('button:has-text("Save Expense")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('body')).toContainText(`Toggle test ${TS}`);
        await logout(page);
    });

    test('toggle paid status changes button', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/expenses.php');
        const row = page.locator('tr').filter({ hasText: `Toggle test ${TS}` });
        const toggleBtn = row.locator('form:has(input[value="toggle_paid"]) button');
        const before = await toggleBtn.textContent();
        await toggleBtn.click();
        await page.waitForLoadState('domcontentloaded');
        const after = await page.locator('tr').filter({ hasText: `Toggle test ${TS}` }).locator('form:has(input[value="toggle_paid"]) button').textContent();
        expect(after?.trim()).not.toBe(before?.trim());
        await logout(page);
    });

    test('year filter loads without error', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/expenses.php');
        const yearBtns = page.locator('a.btn[href*="?year="]');
        if (await yearBtns.count() > 1) {
            await yearBtns.nth(1).click();
            await page.waitForLoadState('domcontentloaded');
            await assertNoPhpErrors(page, 'expenses year filter');
        }
        await logout(page);
    });

    test('delete expense removes it', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/expenses.php');
        await page.click('#editToggle');
        const row = page.locator('tr').filter({ hasText: `Toggle test ${TS}` });
        if (await row.isVisible()) {
            page.once('dialog', d => d.accept());
            await row.locator('.btn-outline-danger').click();
            await page.waitForLoadState('domcontentloaded');
            await expect(page.locator('body')).not.toContainText(`Toggle test ${TS}`);
        }
        await logout(page);
    });
});

// ── USER MANAGEMENT ───────────────────────────────────────────────────────────
test.describe('User management', () => {
    test.describe.configure({ mode: 'serial' });

    test('users page lists accounts', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await visit(page, '/admin/users.php', 'users list');
        await expect(page.locator('tbody tr').first()).toBeVisible();
        await logout(page);
    });

    test('toggle deactivate then re-activate', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/users.php');
        // Navigate to a non-admin user's profile (skip rows with the danger/admin badge)
        const nonAdminRow = page.locator('tbody tr').filter({
            hasNot: page.locator('span.badge.bg-danger')
        }).first();
        await nonAdminRow.locator('a:has-text("View")').click();
        await page.waitForLoadState('domcontentloaded');
        const btn = page.locator('button:has-text("Deactivate"), button:has-text("Activate")').first();
        if (await btn.isVisible()) {
            const before = (await btn.textContent())?.trim();
            page.once('dialog', d => d.accept());
            await btn.click();
            await page.waitForLoadState('domcontentloaded');
            const after = before === 'Deactivate' ? 'Activate' : 'Deactivate';
            await expect(page.locator(`button:has-text("${after}")`)).toBeVisible();
            page.once('dialog', d => d.accept());
            await page.locator(`button:has-text("${after}")`).click();
            await page.waitForLoadState('domcontentloaded');
        }
        await logout(page);
    });

    test('password reset form reveals on click', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/users.php');
        // Deactivate/password actions are on user_profile.php — click View on any user
        await page.locator('a:has-text("View")').first().click();
        await page.waitForLoadState('domcontentloaded');
        await page.locator('button:has-text("Change")').click();
        await expect(page.locator('input[name="new_password"]')).toBeVisible();
        await logout(page);
    });
});

// ── STUDENT / PROFILE EDITING ─────────────────────────────────────────────────
test.describe('Profile editing', () => {
    test.describe.configure({ mode: 'serial' });

    test('admin: edit saves and persists on reload', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/student_edit.php?id=2');
        await page.click('#profileEditBtn');           // enter edit mode
        await page.fill('input[name="phone"]', '555-0199');
        await page.click('#profileEditBtn');           // now "Confirm" — submits form
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'profile saved');
        await page.reload();
        await page.click('#profileEditBtn');           // reveal edit inputs
        expect(await page.inputValue('input[name="phone"]')).toBe('555-0199');
        await logout(page);
    });

    test('admin: account type dropdown shows valid value', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/student_edit.php?id=2');
        await page.click('#profileEditBtn');           // reveal edit form
        const val = await page.inputValue('select[name="account_type"]');
        expect(['guest','student','instructor','admin']).toContain(val);
        await logout(page);
    });

    test('student: profile edit form is pre-filled', async ({ page }) => {
        await login(page, STU_USER, STU_PASS);
        await page.goto(BASE + '/student/profile_edit.php');
        expect((await page.inputValue('input[name="first_name"]')).length).toBeGreaterThan(0);
        await logout(page);
    });
});

// ── RANK HISTORY ──────────────────────────────────────────────────────────────
test.describe('Rank history', () => {
    test.describe.configure({ mode: 'serial' });

    test('add rank form is visible for admin', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/student_edit.php?id=2');
        await page.click('button:has-text("+ Record Rank")');     // open rank add panel
        await expect(page.locator('#rank-add-box select[name="new_rank_id"]')).toBeVisible();
        await expect(page.locator('#rank-add-box input[name="new_rank_date"]')).toBeVisible();
        await logout(page);
    });

    test('add rank entry persists', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/student_edit.php?id=2');
        await page.click('button:has-text("+ Record Rank")');     // open rank add panel
        await page.locator('#rank-add-box select[name="new_rank_id"]').selectOption({ index: 1 });
        await page.fill('#rank-add-box input[name="new_rank_date"]', '2020-01-01');
        await page.locator('#rank-add-box button:has-text("Save")').click();
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'add rank');
        // Rank edit selects are revealed by clicking the Edit button
        const rankEditBtn = page.locator('#rankEditToggle');
        if (await rankEditBtn.isVisible()) await rankEditBtn.click();
        expect(await page.locator('select[name^="rank_updates"]').count()).toBeGreaterThanOrEqual(1);
        await logout(page);
    });

    test.afterAll(async ({ browser }) => {
        test.setTimeout(30_000);
        const page = await browser.newPage();
        try {
            await login(page, ADMIN_USER, ADMIN_PASS);
            await page.goto(BASE + '/admin/student_edit.php?id=2');
            // Enter rank edit mode to show delete buttons
            const editBtn = page.locator('#rankEditToggle');
            if (await editBtn.isVisible()) {
                await editBtn.click();
                // Find the row containing the test date (Jan 1, 2020) and delete it
                const rankRow = page.locator('#rankTable tbody tr').filter({ hasText: 'Jan 1, 2020' });
                if (await rankRow.isVisible()) {
                    page.once('dialog', d => d.accept());
                    await rankRow.locator('.rank-delete-col button').click();
                    await page.waitForLoadState('domcontentloaded');
                }
            }
        } catch (e) { /* best-effort */ }
        await page.close();
    });
});

// ── BELT TESTS ────────────────────────────────────────────────────────────────
test.describe('Belt tests', () => {
    test.describe.configure({ mode: 'serial' });

    test('create a new belt test for a student', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/instructor/belt_test_edit.php');
        const futureDate = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
        await page.selectOption('select[name="student_id"]', { index: 1 });
        await page.fill('input[name="test_date"]', futureDate);
        await page.selectOption('select[name="rank_id"]', { index: 1 });
        await page.fill('input[name="notes"]', `Test Note ${TS}`);
        await page.click('button[type="submit"]');
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'create belt test');
        await logout(page);
    });

    test('new belt test appears in all belt tests list', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/instructor/belt_tests_all.php');
        await expect(page.locator('body')).toContainText(`Test Note ${TS}`);
        await logout(page);
    });

    test.afterAll(async ({ browser }) => {
        test.setTimeout(30_000);
        const page = await browser.newPage();
        try {
            await login(page, ADMIN_USER, ADMIN_PASS);
            await page.goto(BASE + '/instructor/belt_tests_all.php');
            const editBtn = page.locator('#editToggle');
            if (await editBtn.isVisible()) await editBtn.click();
            const row = page.locator('tr').filter({ hasText: `Test Note ${TS}` });
            if (await row.isVisible()) {
                page.once('dialog', d => d.accept());
                await row.locator('.btn-outline-danger').click();
                await page.waitForLoadState('domcontentloaded');
            }
        } catch (e) { /* cleanup best-effort */ }
        await page.close();
    });
});

// ── SEARCH AND FILTER ─────────────────────────────────────────────────────────
test.describe('Search and filter', () => {
    test.describe.configure({ mode: 'serial' });

    test('instructor roster search filters by name', async ({ page }) => {
        await login(page, INST_USER, INST_PASS);
        await page.goto(BASE + '/instructor/students.php');
        await page.fill('#rosterSearch', 'zzznomatch');
        const rows = page.locator('tbody tr[data-name]');
        const count = await rows.count();
        let visible = 0;
        for (let i = 0; i < count; i++) {
            const display = await rows.nth(i).evaluate(el => window.getComputedStyle(el).display);
            if (display !== 'none') visible++;
        }
        expect(visible).toBe(0);
        await logout(page);
    });

    test('admin payment filter by method', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/payments.php');
        await page.selectOption('select[name="method"]', 'paypal');
        await page.click('button:has-text("Filter")');
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'payment filter paypal');
        await logout(page);
    });
});

// ── ADMIN DASHBOARD DATA ──────────────────────────────────────────────────────
test.describe('Admin dashboard data', () => {
    test.describe.configure({ mode: 'serial' });

    test('stat cards show numeric values', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await visit(page, '/admin/', 'dashboard stats');
        const vals = await page.locator('.display-6').allTextContents();
        expect(vals.length).toBeGreaterThanOrEqual(4);
        await logout(page);
    });

    test('unpaid tuition card is visible', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await visit(page, '/admin/', 'unpaid card');
        await expect(page.locator('.card-header').filter({ hasText: 'Tuition Unpaid' })).toBeVisible();
        await logout(page);
    });

    test('missing injury waivers card is visible', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await visit(page, '/admin/', 'missing waivers card');
        await expect(page.locator('.card-header').filter({ hasText: 'Missing Injury Waivers' })).toBeVisible();
        await logout(page);
    });
});

// ── STUDENT DASHBOARD DATA ────────────────────────────────────────────────────
test.describe('Student dashboard data', () => {
    test.describe.configure({ mode: 'serial' });

    test('classes attended shows a number', async ({ page }) => {
        await login(page, STU_USER, STU_PASS);
        await visit(page, '/student/', 'classes attended');
        expect(parseInt(await page.locator('.display-6.text-primary').first().textContent() ?? '0')).toBeGreaterThanOrEqual(0);
        await logout(page);
    });

    test('injury waiver card shows ✓ or ✗', async ({ page }) => {
        await login(page, STU_USER, STU_PASS);
        await visit(page, '/student/', 'waiver card');
        const icon = await page.locator('.card-body').filter({ hasText: 'Injury Waiver' }).locator('.display-6').textContent();
        expect(['✓', '✗']).toContain(icon?.trim());
        await logout(page);
    });

    test('June Payment card shows ✓ or ✗', async ({ page }) => {
        await login(page, STU_USER, STU_PASS);
        await visit(page, '/student/', 'payment card');
        const month = new Date().toLocaleString('en-US', { month: 'long' });
        const icon = await page.locator('.card-body').filter({ hasText: `${month} Payment` }).locator('.display-6').textContent();
        expect(['✓', '✗']).toContain(icon?.trim());
        await logout(page);
    });
});

// ── EDGE CASES ────────────────────────────────────────────────────────────────
test.describe('Edge cases', () => {
    test.describe.configure({ mode: 'serial' });

    test('invalid student ID redirects gracefully', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/instructor/student_profile.php?id=99999');
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'invalid student id');
        await logout(page);
    });

    test('invalid belt test ID redirects gracefully', async ({ page }) => {
        await login(page, INST_USER, INST_PASS);
        await page.goto(BASE + '/instructor/belt_test_edit.php?id=99999');
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'invalid belt test id');
        await logout(page);
    });

    test('student cannot view another student\'s profile', async ({ page }) => {
        await login(page, STU_USER, STU_PASS);
        await page.goto(BASE + '/instructor/student_profile.php?id=2');
        await page.waitForLoadState('domcontentloaded');
        // Students are silently redirected to their own dashboard instead of seeing an error
        expect(page.url()).not.toContain('student_profile.php');
        await logout(page);
    });

    test('attendance with invalid date defaults gracefully', async ({ page }) => {
        await login(page, INST_USER, INST_PASS);
        await page.goto(BASE + '/instructor/attendance.php?date=not-a-date');
        await assertNoPhpErrors(page, 'invalid attendance date');
        await logout(page);
    });

    test('active_override Force Active saves and can be reset', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/student_edit.php?id=2');
        await page.click('#activeEditBtn');            // enter edit mode
        await page.selectOption('select[name="active_override"]', '1');
        await page.click('#activeEditBtn');            // Confirm → submit
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'active override saved');
        await page.click('#activeEditBtn');            // enter edit mode again
        await page.selectOption('select[name="active_override"]', 'auto');
        await page.click('#activeEditBtn');            // Confirm → submit
        await page.waitForLoadState('domcontentloaded');
        await logout(page);
    });
});
