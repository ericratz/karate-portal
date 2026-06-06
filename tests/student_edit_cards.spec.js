// @ts-check
// Tests for admin/student_edit.php card-level features:
//   - Attendance date links navigate to attendance.php
//   - Belt Test History card: add form toggle, form fields
//   - Payment Waivers card: add form toggle, form fields
//   - Injury Waiver card: edit toggle reveals checkbox + date input
//   - Student Notes card: add form toggle
//   - Payment History card: add form toggle
//   - Add note round-trip on student_edit.php
const { test, expect } = require('@playwright/test');
const { login, logout, assertNoPhpErrors, BASE } = require('./helpers');

const { ADMIN_USER, ADMIN_PASS } = require('./credentials');
const STUDENT_ID = 2; // Sarah Johnson — a stable student in the test DB
const TS = Date.now();

test.describe('Student Edit Cards', () => {

    test.beforeEach(async ({ page }) => {
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + `/admin/student_edit.php?id=${STUDENT_ID}`);
        await assertNoPhpErrors(page, 'student edit loads');
    });

    // ── ATTENDANCE DATE LINKS ────────────────────────────────────────────────────

    test('attendance date links contain attendance.php?date=', async ({ page }) => {
        const link = page.locator('a[href*="attendance.php?date="]').first();
        if (await link.count() === 0) return; // no sessions in DB
        const href = await link.getAttribute('href');
        expect(href).toContain('attendance.php?date=');
    });

    test('clicking an attendance date link navigates to attendance.php', async ({ page }) => {
        const link = page.locator('a[href*="attendance.php?date="]').first();
        if (await link.count() === 0) return;
        await link.click();
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('attendance.php');
        expect(page.url()).toContain('date=');
    });

    // ── BELT TEST HISTORY CARD ───────────────────────────────────────────────────

    test('Belt Test History card is visible', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Belt Test History' })).toBeVisible();
    });

    test('+ Record Test button is visible', async ({ page }) => {
        await expect(page.locator('button:has-text("+ Record Test")')).toBeVisible();
    });

    test('+ Record Test reveals add form with required fields', async ({ page }) => {
        await page.click('button:has-text("+ Record Test")');
        await expect(page.locator('#bt-add-box')).toBeVisible();
        await expect(page.locator('#bt-add-box input[name="test_date"]')).toBeVisible();
        await expect(page.locator('#bt-add-box select[name="rank_testing_for"]')).toBeVisible();
        await expect(page.locator('#bt-add-box select[name="result"]')).toBeVisible();
    });

    test('bt-add-box has Fee Paid and Belt Awarded checkboxes', async ({ page }) => {
        await page.click('button:has-text("+ Record Test")');
        await expect(page.locator('#bt-add-box input[name="fee_paid"]')).toBeVisible();
        await expect(page.locator('#bt-add-box input[name="belt_awarded"]')).toBeVisible();
    });

    test('Cancel button in bt-add-box hides the form', async ({ page }) => {
        await page.click('button:has-text("+ Record Test")');
        await expect(page.locator('#bt-add-box')).toBeVisible();
        await page.locator('#bt-add-box button:has-text("Cancel")').click();
        await expect(page.locator('#bt-add-box')).toBeHidden();
    });

    test('result select in bt-add-box has pending/pass/fail options', async ({ page }) => {
        await page.click('button:has-text("+ Record Test")');
        const opts = await page.locator('#bt-add-box select[name="result"] option').allTextContents();
        const vals = opts.map(o => o.trim().toLowerCase());
        expect(vals).toContain('pending');
        expect(vals).toContain('pass');
        expect(vals).toContain('fail');
    });

    // ── PAYMENT WAIVERS CARD ─────────────────────────────────────────────────────

    test('Payment Waivers card is visible', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Payment Waivers' })).toBeVisible();
    });

    test('+ Add Waiver button is visible', async ({ page }) => {
        await expect(page.locator('button:has-text("+ Add Waiver")')).toBeVisible();
    });

    test('+ Add Waiver reveals add form with type and date fields', async ({ page }) => {
        await page.click('button:has-text("+ Add Waiver")');
        await expect(page.locator('#pw-add-box')).toBeVisible();
        await expect(page.locator('#pw-add-box select[name="waiver_type"]')).toBeVisible();
        await expect(page.locator('#pw-add-box input[name="granted_date"]')).toBeVisible();
    });

    test('waiver_type select has monthly_tuition option', async ({ page }) => {
        await page.click('button:has-text("+ Add Waiver")');
        const opts = await page.locator('#pw-add-box select[name="waiver_type"] option').allTextContents();
        const vals = opts.map(o => o.trim().toLowerCase());
        expect(vals.some(v => v.includes('tuition'))).toBe(true);
    });

    test('Cancel in pw-add-box hides the form', async ({ page }) => {
        await page.click('button:has-text("+ Add Waiver")');
        await page.locator('#pw-add-box button:has-text("Cancel")').click();
        await expect(page.locator('#pw-add-box')).toBeHidden();
    });

    // ── INJURY WAIVER CARD ───────────────────────────────────────────────────────

    test('Injury Waiver card is visible', async ({ page }) => {
        // Use exact match to avoid matching "Payment Waivers" header
        await expect(page.locator('.card-header').filter({ hasText: 'Injury Waiver' }).first()).toBeVisible();
    });

    test('#injuryEditBtn is visible', async ({ page }) => {
        await expect(page.locator('#injuryEditBtn')).toBeVisible();
    });

    test('clicking Edit on Injury Waiver reveals checkbox and date input', async ({ page }) => {
        await expect(page.locator('#injury-edit')).toBeHidden();
        await page.locator('#injuryEditBtn').click();
        await expect(page.locator('#injury-edit')).toBeVisible();
        await expect(page.locator('input[name="injury_waiver"]')).toBeVisible();
        await expect(page.locator('input[name="injury_waiver_date"]')).toBeVisible();
    });

    test('Edit button becomes Confirm after click', async ({ page }) => {
        await page.locator('#injuryEditBtn').click();
        await expect(page.locator('#injuryEditBtn')).toHaveText('Confirm');
    });

    test('Cancel button on Injury Waiver returns to view mode', async ({ page }) => {
        await page.locator('#injuryEditBtn').click();
        await page.locator('#injuryCancelBtn').click();
        await expect(page.locator('#injury-view')).toBeVisible();
        await expect(page.locator('#injury-edit')).toBeHidden();
        await expect(page.locator('#injuryEditBtn')).toHaveText('Edit');
    });

    // ── PAYMENT HISTORY CARD ─────────────────────────────────────────────────────

    test('Payment History card is visible', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Payment History' })).toBeVisible();
    });

    test('+ Add Payment button reveals payment form', async ({ page }) => {
        await page.click('button:has-text("+ Add Payment")');
        await expect(page.locator('#pay-add-box')).toBeVisible();
        await expect(page.locator('#pay-add-box input[name="payment_date"]')).toBeVisible();
        await expect(page.locator('#pay-add-box input[name="amount"]')).toBeVisible();
        await expect(page.locator('#pay-add-box select[name="payment_type"]')).toBeVisible();
        await expect(page.locator('#pay-add-box select[name="payment_method"]')).toBeVisible();
    });

    // ── NOTES CARD ───────────────────────────────────────────────────────────────

    test('Student Notes card is visible at bottom of page', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Student Notes' })).toBeVisible();
    });

    test('+ Add Note button reveals textarea', async ({ page }) => {
        await page.click('button:has-text("+ Add Note")');
        await expect(page.locator('textarea[name="note_content"]')).toBeVisible();
    });

    test('Cancel in note add form hides the form', async ({ page }) => {
        await page.click('button:has-text("+ Add Note")');
        await page.locator('button:has-text("Cancel")').last().click();
        await expect(page.locator('textarea[name="note_content"]')).toBeHidden();
    });

    // ── NOTE ADD ROUND-TRIP ──────────────────────────────────────────────────────

    test('adding a note on student_edit.php saves and displays it', async ({ page }) => {
        const noteText = `StudentEdit note ${TS}`;
        await page.click('button:has-text("+ Add Note")');
        await page.fill('textarea[name="note_content"]', noteText);
        await page.locator('form:has(textarea[name="note_content"]) button[type="submit"]').click();
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'note saved');
        await expect(page.locator('body')).toContainText(noteText);
    });

    test.afterAll(async ({ browser }) => {
        // Clean up the note added in the round-trip test
        test.setTimeout(30_000);
        const page = await browser.newPage();
        try {
            await login(page, ADMIN_USER, ADMIN_PASS);
            await page.goto(BASE + `/admin/student_notes.php?student_id=${STUDENT_ID}`);
            // Enter edit mode and delete the note
            await page.click('#editToggle');
            const entry = page.locator('.border-bottom, .note-entry, li').filter({ hasText: `StudentEdit note ${TS}` });
            if (await entry.isVisible()) {
                page.once('dialog', d => d.accept());
                await entry.locator('button.delete-btn, .delete-btn button, button:has-text("✕")').first().click();
                await page.waitForLoadState('domcontentloaded');
            }
        } catch (e) { /* best-effort */ }
        await page.close();
    });
});
