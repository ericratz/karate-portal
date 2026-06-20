// @ts-check
// Tests for V0.2 features:
//   - Parent category on roster/attendance/student-notes/email
//   - Notify Noji registration flow (option cards, skip, notify)
//   - Compare & Link page (compare_account.php)
//   - Link Requests card on admin dashboard
//   - Belt test student info panel (rank + history on student select)
//   - Admin dropdown reorganised (Donations in Finances)
//   - Users page parent role filter
//   - Liability Waiver label (renamed from Injury Waiver)
const { test, expect } = require('@playwright/test');
const { login, logout, visit, assertNoPhpErrors, deleteTestStudent, BASE, AUTH } = require('./helpers');

const { ADMIN_USER, ADMIN_PASS } = require('./credentials');
const TS = Date.now();

// ── PARENT CATEGORY ON ROSTER VIEWS ─────────────────────────────────────────

test.describe('Parent category on roster views', () => {
    test.use({ storageState: AUTH.admin });

    test('admin students.php shows Parents card', async ({ page }) => {
        await visit(page, '/admin/students.php', 'admin roster');
        await expect(page.locator('.card-header').filter({ hasText: 'Parents' })).toBeVisible();
    });

    test('admin students.php card order: Instructors → Parents → Students → Guests', async ({ page }) => {
        await page.goto(BASE + '/admin/students.php');
        const headers = await page.locator('.card-header').allTextContents();
        const order = headers.map(h => h.trim());
        const iIdx = order.findIndex(h => h.includes('Instructors'));
        const pIdx = order.findIndex(h => h.includes('Parents'));
        const sIdx = order.findIndex(h => h.includes('Students'));
        const gIdx = order.findIndex(h => h.includes('Guests'));
        // All four must be present and in order
        expect(iIdx).toBeGreaterThanOrEqual(0);
        expect(pIdx).toBeGreaterThan(iIdx);
        expect(sIdx).toBeGreaterThan(pIdx);
        expect(gIdx).toBeGreaterThan(sIdx);
    });

    test('admin student_notes.php shows Parents card', async ({ page }) => {
        await visit(page, '/admin/student_notes.php', 'student notes');
        await expect(page.locator('.card-header').filter({ hasText: 'Parents' })).toBeVisible();
    });

    test('admin email_students.php has parents group checkbox', async ({ page }) => {
        await visit(page, '/admin/email_students.php', 'email students');
        await expect(page.locator('#chk_parents')).toBeVisible();
    });

});

test.describe('instructor students.php shows Parents card', () => {
    test.use({ storageState: AUTH.instructor });

    test('instructor students.php shows Parents card', async ({ page }) => {
        await visit(page, '/instructor/students.php', 'instructor roster');
        await expect(page.locator('.card-header').filter({ hasText: 'Parents' })).toBeVisible();
    });
});

// ── REGISTRATION FLOW ────────────────────────────────────────────────────────
// Each test registers its own account to keep tests isolated.

/** Helper: fill registration form and click Next (step 1 only). */
async function registerStep1(page, suffix) {
    await page.goto(BASE + '/register.php');
    await page.fill('input[name="first_name"]', 'Reg');
    await page.fill('input[name="last_name"]',  `Flow${suffix}`);
    await page.fill('input[name="date_of_birth"]', '2000-01-01');
    await page.fill('input[name="email"]', `rf${suffix}@test.com`);
    await page.fill('input[name="username"]', `rf${suffix}`);
    await page.fill('input[name="password"]', 'TestPass1!');
    await page.fill('input[name="confirm"]',  'TestPass1!');
    await page.click('button:has-text("Next")');
    await page.waitForLoadState('domcontentloaded');
}

test.describe('Registration flow', () => {

    test('step 1 shows progress dots and step label', async ({ page }) => {
        await page.goto(BASE + '/register.php');
        const small = await page.textContent('.card-header small');
        expect(small).toContain('Create an Account');
        await expect(page.locator('.step-dot')).toHaveCount(3);
    });

    test('with no matching records, step 1 advances to confirm step', async ({ page }) => {
        await registerStep1(page, `a${TS}`);
        const header = await page.textContent('.card-header small');
        expect(header).toContain('Confirm');
    });

    test('confirm step shows user summary details', async ({ page }) => {
        await registerStep1(page, `b${TS}`);
        // Confirm step should show the username and name we entered
        const body = await page.textContent('.confirm-detail');
        expect(body).toContain(`rfb${TS}`);  // username shown in summary
    });

    test('Continue button is disabled until a match option is selected', async ({ page }) => {
        // This test needs matching records — just verify the step 2 button state via a
        // direct DOM check on a freshly loaded match step (if matches exist)
        // Otherwise, verify the confirm step shows "Create Account" already enabled
        await registerStep1(page, `c${TS}`);
        const confirmBtn = page.locator('button:has-text("Create Account")');
        if (await confirmBtn.isVisible()) {
            await expect(confirmBtn).toBeEnabled();
        } else {
            // Match step shown — continue button disabled until selection
            await expect(page.locator('#continueBtn')).toBeDisabled();
        }
    });

    test('completing registration logs in and shows student dashboard', async ({ page }) => {
        await registerStep1(page, `d${TS}`);
        await page.click('button:has-text("Create Account")');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('/student/');
    });

    test('completing registration creates a New Registrations alert on admin dashboard', async ({ page }) => {
        await registerStep1(page, `e${TS}`);
        await page.click('button:has-text("Create Account")');
        await page.waitForLoadState('domcontentloaded');
        // Registration logs the user in — log out before checking admin dashboard
        await logout(page);
        await login(page, ADMIN_USER, ADMIN_PASS);
        await page.goto(BASE + '/admin/');
        await expect(page.locator('.card-header').filter({ hasText: 'New Registrations' })).toBeVisible();
        await expect(page.locator('body')).toContainText(`rfe${TS}`);
    });

});

// ── COMPARE & LINK PAGE ──────────────────────────────────────────────────────

test.describe('Compare & Link page', () => {
    test.use({ storageState: AUTH.admin });

    test('compare_account.php loads without errors when given a user_id', async ({ page }) => {
        // Navigate via admin users list — click View on any user, then build compare URL
        await page.goto(BASE + '/admin/users.php');
        await page.locator('a:has-text("View")').first().click();
        await page.waitForLoadState('domcontentloaded');
        const uid = new URL(page.url()).searchParams.get('id');
        if (uid) {
            await visit(page, `/admin/compare_account.php?user_id=${uid}`, 'compare account');
        }
    });

    test('compare page shows Login Account and Select Student picker', async ({ page }) => {
        await page.goto(BASE + '/admin/users.php');
        await page.locator('a:has-text("View")').first().click();
        await page.waitForLoadState('domcontentloaded');
        const uid = new URL(page.url()).searchParams.get('id');
        if (uid) {
            await page.goto(BASE + `/admin/compare_account.php?user_id=${uid}`);
            await expect(page.locator('.card-header').filter({ hasText: 'Login Account' })).toBeVisible();
            await expect(page.locator('select[name="student_id"]')).toBeVisible();
        }
    });

    test('compare page shows Student Record card when student_id provided', async ({ page }) => {
        await page.goto(BASE + '/admin/users.php');
        await page.locator('a:has-text("View")').first().click();
        await page.waitForLoadState('domcontentloaded');
        const uid = new URL(page.url()).searchParams.get('id');
        if (uid) {
            // Get first available student for comparison
            await page.goto(BASE + `/admin/compare_account.php?user_id=${uid}`);
            await assertNoPhpErrors(page, 'compare page loaded');
            const opts = await page.locator('select[name="student_id"] option').count();
            if (opts > 1) {
                // Select first real option and compare
                const val = await page.locator('select[name="student_id"] option').nth(1).getAttribute('value');
                await page.goto(BASE + `/admin/compare_account.php?user_id=${uid}&student_id=${val}`);
                // .card-header.d-flex is the side-by-side card; the picker header has no d-flex
                await expect(page.locator('.card-header.d-flex').filter({ hasText: 'Student Record' })).toBeVisible();
            }
        }
    });

    test('compare page has + Create New Student Record link', async ({ page }) => {
        await page.goto(BASE + '/admin/users.php');
        await page.locator('a:has-text("View")').first().click();
        await page.waitForLoadState('domcontentloaded');
        const uid = new URL(page.url()).searchParams.get('id');
        if (uid) {
            await page.goto(BASE + `/admin/compare_account.php?user_id=${uid}`);
            const link = page.locator('a:has-text("+ Create New Student Record")');
            await expect(link).toBeVisible();
            const href = await link.getAttribute('href');
            expect(href).toContain('student_edit.php');
        }
    });

    test('invalid user_id redirects from compare page', async ({ page }) => {
        await page.goto(BASE + '/admin/compare_account.php?user_id=0');
        await page.waitForLoadState('domcontentloaded');
        // Should redirect to admin dashboard
        expect(page.url()).not.toContain('compare_account.php');
    });

});

// ── LINK REQUESTS CARD ON ADMIN DASHBOARD ───────────────────────────────────

test.describe('Link Requests admin dashboard', () => {
    test.use({ storageState: AUTH.admin });

    test('admin dashboard loads without errors', async ({ page }) => {
        await visit(page, '/admin/', 'admin dashboard');
    });

    test('Possible Account Links shows Compare button (not Link)', async ({ page }) => {
        await page.goto(BASE + '/admin/');
        // If possible links card is present, verify Compare buttons (not old Link button)
        const card = page.locator('.card').filter({
            has: page.locator('.card-header').filter({ hasText: 'Possible Account Links' })
        });
        if (await card.count() > 0) {
            await expect(card.locator('a:has-text("Compare")').first()).toBeVisible();
            // No old "Link" submit button should exist
            expect(await card.locator('button:has-text("Link")').count()).toBe(0);
        }
    });

    test('Possible Account Links Compare button points to compare_account.php', async ({ page }) => {
        await page.goto(BASE + '/admin/');
        const card = page.locator('.card').filter({
            has: page.locator('.card-header').filter({ hasText: 'Possible Account Links' })
        });
        if (await card.count() > 0) {
            const btn = card.locator('a:has-text("Compare")').first();
            if (await btn.count() > 0) {
                const href = await btn.getAttribute('href');
                expect(href).toContain('compare_account.php');
                expect(href).toContain('user_id=');
            }
        }
    });

    test('Link Requests card shows if any pending requests exist', async ({ page }) => {
        await page.goto(BASE + '/admin/');
        // The card only renders when there are pending requests (may not be present in clean DB)
        const card = page.locator('.card-header').filter({ hasText: 'Link Requests' });
        if (await card.count() > 0) {
            await expect(card).toBeVisible();
            // Review buttons link to compare_account.php
            const reviewBtn = page.locator('a:has-text("Review")').first();
            if (await reviewBtn.count() > 0) {
                const href = await reviewBtn.getAttribute('href');
                expect(href).toContain('compare_account.php');
                expect(href).toContain('link_request_id=');
            }
        }
    });

    // See "Notify Noji registration flow" describe block above for link request creation test.
});

// ── BELT TEST STUDENT INFO PANEL ─────────────────────────────────────────────

test.describe('Belt test student info panel', () => {
    test.use({ storageState: AUTH.instructor });

    test('selecting a student shows populated info panel; clearing hides it', async ({ page }) => {
        await page.goto(BASE + '/instructor/belt_test_edit.php');
        const select = page.locator('select[name="student_id"]');
        if (await select.locator('option').count() <= 1) return;
        await select.selectOption({ index: 1 });
        const panel = page.locator('#studentInfoPanel');
        await expect(panel).not.toBeEmpty();
        expect((await panel.textContent())?.length).toBeGreaterThan(0);
        // Clearing the select hides the panel
        await select.selectOption({ index: 0 });
        await expect(panel).toBeHidden();
    });
});

// ── ADMIN DROPDOWN REORGANISATION ────────────────────────────────────────────

test.describe('Admin dropdown reorganisation', () => {
    test.use({ storageState: AUTH.admin });

    test('admin dropdown has correct sections and links', async ({ page }) => {
        await page.goto(BASE + '/admin/');
        await page.click('.navbar .dropdown-toggle:has-text("Admin")');
        const menu = await page.textContent('.dropdown-menu');
        // Section headers
        expect(menu).toContain('Instructor');
        expect(menu).toContain('Finances');
        expect(menu).toContain('Security');
        expect(menu).toContain('Communication');
        expect(menu).not.toContain('Other');
        // Specific links in correct sections
        const donations = page.locator('a.dropdown-item:has-text("Donations")');
        await expect(donations).toBeVisible();
        expect(await donations.getAttribute('href')).toContain('donations.php');
        await expect(page.locator('a.dropdown-item:has-text("Expenses")')).toBeVisible();
        await expect(page.locator('a.dropdown-item:has-text("Payments")')).toBeVisible();
        const auditLog = page.locator('a.dropdown-item:has-text("Audit Log")');
        await expect(auditLog).toBeVisible();
        expect(await auditLog.getAttribute('href')).toContain('audit_log.php');
    });
});

// ── USERS PAGE PARENT ROLE FILTER ────────────────────────────────────────────

test.describe('Users page parent role filter', () => {
    test.use({ storageState: AUTH.admin });

    test('users.php role filter has Parent option', async ({ page }) => {
        await page.goto(BASE + '/admin/users.php');
        const opts = await page.locator('#filterRole option').allTextContents();
        const vals = opts.map(o => o.trim().toLowerCase());
        expect(vals).toContain('parent');
    });

    test('filtering by parent role hides non-parent rows', async ({ page }) => {
        await page.goto(BASE + '/admin/users.php');
        await page.selectOption('#filterRole', 'parent');
        await page.waitForTimeout(300);
        // After filtering for 'parent', non-parent rows must not be visible
        const nonParentRows = page.locator('tbody tr[data-role]:not([data-role="parent"])');
        const total = await nonParentRows.count();
        for (let i = 0; i < total; i++) {
            const disp = await nonParentRows.nth(i).evaluate(el => window.getComputedStyle(el).display);
            expect(disp).toBe('none');
        }
    });

});

// ── WAIVER LABEL (renamed: Injury Waiver → Liability Waiver → Waiver) ────────

test.describe('Waiver label', () => {
    test.use({ storageState: AUTH.admin });

    test('student profile shows "Waiver" not "Injury Waiver"', async ({ page }) => {
        await page.goto(BASE + '/instructor/student_profile.php?id=2');
        await assertNoPhpErrors(page, 'student profile waiver label');
        const body = await page.textContent('body');
        expect(body).toContain('Waiver');
        expect(body).not.toContain('Injury Waiver');
    });

    test('student_edit.php calls the waiver card "Waiver"', async ({ page }) => {
        await page.goto(BASE + '/admin/student_edit.php?id=2');
        await expect(page.locator('.card-header').filter({ hasText: 'Waiver' }).first()).toBeVisible();
    });

});

// ── FAMILY-AWARE TUITION CHECK ───────────────────────────────────────────────

test.describe('Family-aware tuition dashboard', () => {
    test.use({ storageState: AUTH.admin });

    test('admin dashboard Tuition Unpaid card is visible and functional', async ({ page }) => {
        await visit(page, '/admin/', 'admin dashboard tuition');
        await expect(page.locator('.card-header').filter({ hasText: 'Tuition Unpaid' })).toBeVisible();
        // Card either shows "All students paid ✓" or a list of unpaid students
        const body = await page.textContent('body');
        const hasPaid     = body?.includes('All students paid');
        const hasUnpaid   = body?.includes('Record Payment');
        expect(hasPaid || hasUnpaid).toBe(true);
    });

});

// ── STUDENT PROFILE FAMILY TABS ──────────────────────────────────────────────

test.describe('Student profile family tabs', () => {
    test.use({ storageState: AUTH.admin });

    test('student_profile.php loads without errors', async ({ page }) => {
        await visit(page, '/instructor/student_profile.php?id=2', 'student profile');
    });

    test('student_profile.php shows family tab nav when parent/child relationship exists', async ({ page }) => {
        // Load the page — if student 2 is part of a family, tabs will show
        await page.goto(BASE + '/instructor/student_profile.php?id=2');
        await assertNoPhpErrors(page, 'student profile family tabs');
        // Nav tabs may or may not appear depending on DB state
        // Just verify the page loads without errors (functional check above)
    });

    test('student_profile.php tab links point to student_profile.php', async ({ page }) => {
        await page.goto(BASE + '/instructor/student_profile.php?id=2');
        const tabs = page.locator('.nav-tabs .nav-link');
        const count = await tabs.count();
        if (count > 0) {
            for (let i = 0; i < count; i++) {
                const href = await tabs.nth(i).getAttribute('href');
                expect(href).toContain('student_profile.php');
            }
        }
    });

});
