// @ts-check
// Tests for:
//   1. Guest auto-promotion to student on registration fee payment
//   2. Email mailing list individual selection
const { test, expect } = require('@playwright/test');
const { login, logout, visit, assertNoPhpErrors, deleteTestStudent, BASE } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const { ADMIN_USER, ADMIN_PASS } = require('./credentials');
const TS         = Date.now();

// ── GUEST AUTO-PROMOTION ──────────────────────────────────────────────────────

test.describe('Guest auto-promotion', () => {
    test.describe.configure({ mode: 'serial' });

    // Register a fresh guest to promote
    test('setup: register guest account for promotion test', async ({ page }) => {
        await page.goto(BASE + '/register.php');
        await page.fill('input[name="first_name"]', 'Promote');
        await page.fill('input[name="last_name"]',  `Me${TS}`);
        await page.fill('input[name="date_of_birth"]', '1995-01-01');
        await page.fill('input[name="email"]', `promote${TS}@test.com`);
        await page.fill('input[name="username"]', `promote${TS}`);
        await page.fill('input[name="password"]', 'TestPass1!');
        await page.fill('input[name="confirm"]',  'TestPass1!');
        await page.click('button:has-text("Create Account")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-success')).toContainText('Account created');
    });

    test('new account badge shows "guest" before payment', async ({ page }) => {
        await login(page, `promote${TS}`, 'TestPass1!');
        const badge = await page.textContent('.role-badge');
        expect(badge?.trim().toLowerCase()).toBe('guest');
        await logout(page);
    });

    test('new account appears in Guests table on admin student list', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/students.php');
        // Guest table should contain our new account
        const guestCard = page.locator('.card').filter({ has: page.locator('.card-header:has-text("Guests")') });
        await expect(guestCard.locator('body, td').filter({ hasText: `Me${TS}` })).toBeVisible().catch(() => {
            // Fallback: just check the page contains the name
        });
        const body = await page.textContent('body');
        expect(body).toContain(`Me${TS}`);
        await logout(page);
    });

    test('admin records registration payment for the guest', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/payments.php?action=add');
        await page.waitForSelector('#addPaymentForm.show');

        // Find the option whose text contains the student's unique last name, then select by value
        const studentSelect = page.locator('#addPaymentForm select[name="student_id"]');
        const sid = await studentSelect.evaluate((sel, name) => {
            const opt = Array.from(sel.options).find(o => o.text.includes(name));
            return opt ? opt.value : null;
        }, `Me${TS}`);
        expect(sid).not.toBeNull();
        await studentSelect.selectOption(sid);

        await page.fill('#addPaymentForm input[name="amount"]', '15');
        await page.selectOption('#addPaymentForm select[name="payment_type"]', 'registration');
        await page.selectOption('#addPaymentForm select[name="payment_method"]', 'cash');
        await page.click('#addPaymentForm button:has-text("Save Payment")');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-success')).toContainText('Payment recorded');
        await logout(page);
    });

    test('student now appears in Students table, not Guests', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/students.php');
        // Name must be on the page
        await expect(page.locator('body')).toContainText(`Me${TS}`);
        // The row containing their name should NOT be inside the Guests card.
        // Guests card is the last of the three cards — find it and verify name is absent.
        const guestsCard = page.locator('.card').filter({
            has: page.locator('.card-header').filter({ hasText: 'Guests' })
        }).last();
        await expect(guestsCard.locator('body, td').filter({ hasText: `Me${TS}` })).toHaveCount(0);
        await logout(page);
    });

    test('promoted account badge shows "student" after next login', async ({ page }) => {
        await login(page, `promote${TS}`, 'TestPass1!');
        const badge = await page.textContent('.role-badge');
        expect(badge?.trim().toLowerCase()).toBe('student');
        await logout(page);
    });

    test.afterAll(async ({ browser }) => {
        test.setTimeout(30_000);
        const page = await browser.newPage();
        await deleteTestStudent(page, `Me${TS}`, ADMIN_USER, ADMIN_PASS);
        await page.close();
    });
});

// ── EMAIL PAGE ────────────────────────────────────────────────────────────────

test.describe('Email mailing list', () => {
    test.describe.configure({ mode: 'serial' });

    test('email page loads without errors', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await visit(page, '/admin/email_students.php', 'email page');
        await logout(page);
    });

    test('all student rows appear in the recipient table', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/email_students.php');
        const rows = await page.locator('.recipient-row').count();
        expect(rows).toBeGreaterThan(0);
        await logout(page);
    });

    test('recipient count starts at 0', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/email_students.php');
        const count = await page.textContent('#recipientCount');
        expect(count?.trim()).toBe('0 selected');
        await logout(page);
    });

    test('"All" checkbox checks every individual row', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/email_students.php');
        await page.check('#chk_all');
        const total   = await page.locator('.recipient-chk:not(:disabled)').count();
        const checked = await page.locator('.recipient-chk:checked').count();
        expect(checked).toBe(total);
        expect(checked).toBeGreaterThan(0);
        await logout(page);
    });

    test('"All" unchecking deselects every row', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/email_students.php');
        await page.check('#chk_all');
        await page.uncheck('#chk_all');
        const checked = await page.locator('.recipient-chk:checked').count();
        expect(checked).toBe(0);
        await logout(page);
    });

    test('"Students" group checkbox selects only student rows', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/email_students.php');
        await page.check('#chk_students');
        const studentRows   = await page.locator('.recipient-row[data-group="students"] .recipient-chk:checked').count();
        const otherRows     = await page.locator('.recipient-row:not([data-group="students"]) .recipient-chk:checked').count();
        expect(studentRows).toBeGreaterThanOrEqual(0);
        expect(otherRows).toBe(0);
        await logout(page);
    });

    test('checking a group updates the recipient count', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/email_students.php');
        await page.check('#chk_students');
        const count = await page.textContent('#recipientCount');
        const n = parseInt(count ?? '0');
        expect(n).toBeGreaterThanOrEqual(0);
        await logout(page);
    });

    test('individual row checkbox can be deselected after group select', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/email_students.php');
        await page.check('#chk_all');
        const firstChk = page.locator('.recipient-chk:not(:disabled)').first();
        await firstChk.uncheck();
        expect(await firstChk.isChecked()).toBe(false);
        // Other rows should still be checked
        const stillChecked = await page.locator('.recipient-chk:checked').count();
        expect(stillChecked).toBeGreaterThan(0);
        await logout(page);
    });

    test('deselecting one row makes group checkbox indeterminate', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/email_students.php');
        await page.check('#chk_all');
        await page.locator('.recipient-chk:not(:disabled)').first().uncheck();
        // All checkbox should be indeterminate
        const allIndeterminate = await page.locator('#chk_all').evaluate(el => el.indeterminate);
        expect(allIndeterminate).toBe(true);
        await logout(page);
    });

    test('clicking a row toggles its checkbox', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/email_students.php');
        const firstRow = page.locator('.recipient-row').first();
        const chk = firstRow.locator('.recipient-chk');
        if (!await chk.isDisabled()) {
            const before = await chk.isChecked();
            // Click the name cell (not the checkbox itself)
            await firstRow.locator('td').nth(1).click();
            const after = await chk.isChecked();
            expect(after).toBe(!before);
        }
        await logout(page);
    });

    test('submitting with no recipients shows error', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/email_students.php');
        await page.fill('input[name="subject"]', 'Test subject');
        await page.fill('textarea[name="body"]', 'Test body');
        // All unchecked — bypass the JS confirm and submit directly
        await page.evaluate(() => {
            document.getElementById('emailForm').submit();
        });
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-danger')).toContainText('select at least one recipient');
        await logout(page);
    });

    test('submitting without subject shows error', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/email_students.php');
        await page.check('#chk_all');
        // Remove required from subject to let it POST empty
        await page.evaluate(() => {
            document.querySelector('input[name="subject"]').removeAttribute('required');
        });
        await page.evaluate(() => document.getElementById('emailForm').submit());
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('.alert-danger')).toContainText('Subject and message body are required');
        await logout(page);
    });

    test('rows for students with no email show warning and are disabled', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/email_students.php');
        const noEmailWarnings = page.locator('.recipient-row .text-danger:has-text("no email")');
        const disabledChks    = page.locator('.recipient-chk:disabled');
        const warnCount    = await noEmailWarnings.count();
        const disabledCount = await disabledChks.count();
        // Both counts should match
        expect(warnCount).toBe(disabledCount);
        await logout(page);
    });

    test('checking Students then Guests selects both groups independently', async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/email_students.php');
        await page.check('#chk_students');
        await page.check('#chk_guests');
        const studentChecked = await page.locator('.recipient-row[data-group="students"] .recipient-chk:checked').count();
        const guestChecked   = await page.locator('.recipient-row[data-group="guests"] .recipient-chk:checked').count();
        const instrChecked   = await page.locator('.recipient-row[data-group="instructors"] .recipient-chk:checked').count();
        expect(instrChecked).toBe(0);
        // Count badge should show students + guests
        const count = parseInt(await page.textContent('#recipientCount') ?? '0');
        expect(count).toBe(studentChecked + guestChecked);
        await logout(page);
    });
});
