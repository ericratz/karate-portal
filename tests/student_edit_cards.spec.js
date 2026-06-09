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
const { assertNoPhpErrors, BASE, AUTH } = require('./helpers');
const STUDENT_ID = 2; // Sarah Johnson — a stable student in the test DB
const TS = Date.now();

test.describe('Student Edit Cards', () => {
    test.use({ storageState: AUTH.admin });

    test.beforeEach(async ({ page }) => {
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

    test('bt-add-box score input controls result (no result select)', async ({ page }) => {
        await page.click('button:has-text("+ Record Test")');
        // bt-add-box uses a score number input (name="score"), not a result select
        await expect(page.locator('#bt-add-box input[name="score"]')).toBeVisible();
        expect(await page.locator('#bt-add-box select[name="result"]').count()).toBe(0);
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

    test('Liability Waiver card is visible', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Liability Waiver' }).first()).toBeVisible();
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

    test('note textarea is always visible in add-note box', async ({ page }) => {
        await expect(page.locator('#addNoteText')).toBeVisible();
    });

    test('Save Note button is present in add-note box', async ({ page }) => {
        await expect(page.locator('button:has-text("Save Note")')).toBeVisible();
    });

    // ── NOTE ADD ROUND-TRIP ──────────────────────────────────────────────────────

    test('adding a note on student_edit.php saves and displays it', async ({ page }) => {
        const noteText = `StudentEdit note ${TS}`;
        await page.fill('#addNoteText', noteText);
        await page.locator('form:has(#addNoteText) button[type="submit"]').click();
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'note saved');
        await expect(page.locator('body')).toContainText(noteText);
    });

    // No afterAll needed — global-teardown restores the DB after every run.
});
