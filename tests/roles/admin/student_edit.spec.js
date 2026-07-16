// @ts-check
// Tests for admin/student_edit.php card-level features:
//   - Attendance date links navigate to attendance.php
//   - Belt Test History card: add form toggle, form fields
//   - Exempt card (formerly Payment Waivers): add form toggle, form fields
//   - Waiver card: links to waiver_view.php
//   - Student Notes card: add form toggle
//   - Payment History card: add form toggle
//   - Add note round-trip on student_edit.php
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../../helpers');
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
        await expect(link).toHaveCount(1); // student 2 has attendance history in the test DB
        const href = await link.getAttribute('href');
        expect(href).toContain('attendance.php?date=');
    });

    test('clicking an attendance date link navigates to the attendance page', async ({ page }) => {
        const link = page.locator('a[href*="attendance.php?date="]').first();
        await expect(link).toHaveCount(1);
        await link.click();
        await page.waitForLoadState('domcontentloaded');
        // attendance.php is a redirect stub into the SPA route now
        expect(page.url()).toContain('attendance');
        expect(page.url()).toContain('date=');
    });

    // ── BELT TEST HISTORY CARD ───────────────────────────────────────────────────

    test('Belt Test History card is visible', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Belt Test History' })).toBeVisible();
    });

    test('+ New Test link is visible and points to belt_test_edit.php', async ({ page }) => {
        const link = page.locator('a:has-text("+ New Test")');
        await expect(link).toBeVisible();
        const href = await link.getAttribute('href');
        expect(href).toContain('belt_test_edit.php');
        expect(href).toContain(`student_id=${STUDENT_ID}`);
    });

    test('existing belt test row links to belt_test_edit.php for editing', async ({ page }) => {
        const link = page.locator('#btList a[href*="belt_test_edit.php"]').first();
        await expect(link).toHaveCount(1); // student 2 has belt test history in the test DB
        const href = await link.getAttribute('href');
        expect(href).toContain('belt_test_edit.php?id=');
    });

    // ── EXEMPT CARD (formerly Payment Waivers) ───────────────────────────────────

    test('Exempt card is visible', async ({ page }) => {
        await expect(page.locator('.card-header').filter({ hasText: 'Exempt' })).toBeVisible();
    });

    test('+ Add Exemption button is visible', async ({ page }) => {
        await expect(page.locator('button:has-text("+ Add Exemption")')).toBeVisible();
    });

    test('+ Add Exemption reveals add form with type and date fields', async ({ page }) => {
        await page.click('button:has-text("+ Add Exemption")');
        await expect(page.locator('#pw-add-box')).toBeVisible();
        await expect(page.locator('#pw-add-box select[name="waiver_type"]')).toBeVisible();
        await expect(page.locator('#pw-add-box input[name="granted_date"]')).toBeVisible();
    });

    test('waiver_type select has monthly_tuition option', async ({ page }) => {
        await page.click('button:has-text("+ Add Exemption")');
        const opts = await page.locator('#pw-add-box select[name="waiver_type"] option').allTextContents();
        const vals = opts.map(o => o.trim().toLowerCase());
        expect(vals.some(v => v.includes('tuition'))).toBe(true);
    });

    test('Cancel in pw-add-box hides the form', async ({ page }) => {
        await page.click('button:has-text("+ Add Exemption")');
        await page.locator('#pw-add-box button:has-text("Cancel")').click();
        await expect(page.locator('#pw-add-box')).toBeHidden();
    });

    // ── WAIVER CARD (redesigned — now links to waiver_view.php) ──────────────────

    test('Waiver row is visible in Profile Info card', async ({ page }) => {
        // Waiver was merged into the Profile Info card (no longer its own card)
        await expect(page.locator('#profile-view').filter({ hasText: 'Waiver' })).toBeVisible();
    });

    test('Waiver row links to waiver_view.php', async ({ page }) => {
        const link = page.locator('a[href*="waiver_view.php"]').first();
        await expect(link).toBeVisible();
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

    // ── UNIFORM & BELT SIZE ──────────────────────────────────────────────────────
    // Note: these selects live inside #profile-edit (display:none until Edit is
    // clicked). We use page.evaluate() to query the DOM directly — Playwright's
    // locator API sometimes returns 0 for elements inside display:none containers.

    test('Uniform Size and Belt Size selects are present in profile form', async ({ page }) => {
        const counts = await page.evaluate(() => {
            const all = Array.from(document.querySelectorAll('select'));
            return {
                uniform: all.filter(s => s.name === 'uniform_size').length,
                belt:    all.filter(s => s.name === 'belt_size').length,
            };
        });
        expect(counts.uniform).toBe(1);
        expect(counts.belt).toBe(1);
    });

    test('Uniform Size select has full range 000–8', async ({ page }) => {
        const vals = await page.evaluate(() => {
            const sel = Array.from(document.querySelectorAll('select')).find(s => s.name === 'uniform_size');
            return sel ? Array.from(sel.options).map(o => o.textContent.trim()) : [];
        });
        for (const sz of ['000','00','0','1','2','3','4','5','6','7','8']) {
            expect(vals).toContain(sz);
        }
    });

    test('Belt Size select has sizes 2–8', async ({ page }) => {
        const vals = await page.evaluate(() => {
            const sel = Array.from(document.querySelectorAll('select')).find(s => s.name === 'belt_size');
            return sel ? Array.from(sel.options).map(o => o.textContent.trim()) : [];
        });
        for (const sz of ['2','3','4','5','6','7','8']) {
            expect(vals).toContain(sz);
        }
    });

    test('saving uniform and belt size persists in view mode', async ({ page }) => {
        // Force-show the edit form and select values via JS, then submit.
        await page.evaluate(() => {
            const all = Array.from(document.querySelectorAll('select'));
            const edit = document.getElementById('profile-edit');
            const view = document.getElementById('profile-view');
            const btn  = document.getElementById('profileEditBtn');
            if (edit) edit.style.display = '';
            if (view) view.style.display = 'none';
            if (btn)  btn.dataset.editing = 'true';
            const u = all.find(s => s.name === 'uniform_size');
            const b = all.find(s => s.name === 'belt_size');
            if (u) u.value = '4';
            if (b) b.value = '3';
        });
        await page.evaluate(() => document.getElementById('profile-form').submit());
        await page.waitForLoadState('domcontentloaded');
        await assertNoPhpErrors(page, 'save uniform/belt size');
        await expect(page.locator('#profile-view')).toContainText('4');
        await expect(page.locator('#profile-view')).toContainText('3');
    });

    // No afterAll needed — global-teardown restores the DB after every run.
});
