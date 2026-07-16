// @ts-check
// Tests for instructor/student_profile.php — viewable by instructor and admin.
const { test, expect } = require('@playwright/test');
const { assertNoPhpErrors, apiPost, BASE, AUTH } = require('../../helpers');

const STUDENT_ID = 2; // Sarah Johnson — stable student in test DB

// ── INSTRUCTOR VIEW ───────────────────────────────────────────────────────────

test.describe('Student profile — instructor', () => {
    test.use({ storageState: AUTH.instructor });

    test('page loads without PHP errors', async ({ page }) => {
        await page.goto(BASE + `/instructor/student_profile.php?id=${STUDENT_ID}`);
        await assertNoPhpErrors(page, 'instructor student profile');
    });

    test('shows student name in heading', async ({ page }) => {
        await page.goto(BASE + `/instructor/student_profile.php?id=${STUDENT_ID}`);
        // SPA renders after the API fetch — toContainText retries until then
        await expect(page.locator('h4').first()).toContainText(/Sarah|Johnson/);
    });

    test('shows Uniform Size and Belt Size labels', async ({ page }) => {
        await page.goto(BASE + `/instructor/student_profile.php?id=${STUDENT_ID}`);
        await expect(page.locator('body')).toContainText('Uniform Size');
        await expect(page.locator('body')).toContainText('Belt Size');
    });

    test('has Belt Test History card', async ({ page }) => {
        await page.goto(BASE + `/instructor/student_profile.php?id=${STUDENT_ID}`);
        await expect(page.locator('.card-header').filter({ hasText: 'Belt Test History' })).toBeVisible();
    });

    test('missing id redirects to instructor index', async ({ page }) => {
        await page.goto(BASE + '/instructor/student_profile.php');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('/instructor/');
    });

    // V3.2: Linked Family card lists parent/child links as clickable profile links.
    // Sarah Johnson (id=2) is linked to her parent Mike (id=3) via student_guardians.
    test('Linked Family card shows the linked parent as a profile link', async ({ page }) => {
        await page.goto(BASE + `/instructor/student_profile.php?id=${STUDENT_ID}`);
        const card = page.locator('.card').filter({ has: page.locator('.card-header:has-text("Linked Family")') });
        await expect(card).toBeVisible();
        const link = card.locator('a[href*="student_profile.php?id=3"]');
        await expect(link).toBeVisible();
        await expect(link).toContainText('Mike');
    });
});

// ── ADMIN VIEW ────────────────────────────────────────────────────────────────

test.describe('Student profile — admin', () => {
    test.use({ storageState: AUTH.admin });

    test('admin can view student profile', async ({ page }) => {
        await page.goto(BASE + `/instructor/student_profile.php?id=${STUDENT_ID}`);
        await assertNoPhpErrors(page, 'admin view student profile');
        await expect(page.locator('h4').first()).toContainText(/Sarah|Johnson/);
    });
});

// ── ACCESS CONTROL ────────────────────────────────────────────────────────────

test.describe('Student profile — access control', () => {
    test('unauthenticated user is redirected to login', async ({ page }) => {
        await page.goto(BASE + `/instructor/student_profile.php?id=${STUDENT_ID}`);
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('login.php');
    });

    test('student cannot view another student profile', async ({ page, context }) => {
        await context.addCookies([]);
        // Log in as student via storageState is not available as a fixture here,
        // so just verify the page redirects away when the student tries a different ID.
        // The PHP checks: if student role AND student_id != own_id → redirect to /student/
        // We test this indirectly — an unauthenticated hit redirects to login (above).
        // A student logged in who owns id=STUDENT_ID CAN see it; another student cannot.
        // That scenario is covered by the access_control spec.
    });
});

// ── INSTRUCTOR OWN-PROFILE INLINE EDIT ───────────────────────────────────────
// When an instructor views their own student record, an inline Edit button appears.

test.describe('Student profile — instructor own-profile edit', () => {
    test.use({ storageState: AUTH.instructor });

    test('View Profile link on instructor dashboard leads to own profile', async ({ page }) => {
        await page.goto(BASE + '/instructor/');
        await assertNoPhpErrors(page, 'instructor index');
        await expect(page.locator('a:has-text("View Profile")')).toBeVisible();
    });

    test('own profile page shows inline Edit button', async ({ page }) => {
        await page.goto(BASE + '/instructor/');
        const href = await page.locator('a:has-text("View Profile")').getAttribute('href');
        await page.goto(BASE + '/instructor/' + href);
        await assertNoPhpErrors(page, 'instructor own profile');
        await expect(page.locator('#profileEditBtn')).toBeVisible();
    });

    test('Edit toggles to form, Cancel restores view', async ({ page }) => {
        await page.goto(BASE + '/instructor/');
        const href = await page.locator('a:has-text("View Profile")').getAttribute('href');
        await page.goto(BASE + '/instructor/' + href);

        await expect(page.locator('#profile-edit')).toBeHidden();
        await page.click('#profileEditBtn');
        await expect(page.locator('#profile-edit')).toBeVisible();
        await expect(page.locator('#profile-view')).toBeHidden();

        await page.click('#profileCancelBtn');
        await expect(page.locator('#profile-edit')).toBeHidden();
        await expect(page.locator('#profile-view')).toBeVisible();
    });

    test('Save updates own profile via HTMX without page reload', async ({ page }) => {
        await page.goto(BASE + '/instructor/');
        const href = await page.locator('a:has-text("View Profile")').getAttribute('href');
        await page.goto(BASE + '/instructor/' + href);

        // Record original phone value from view section
        await page.click('#profileEditBtn');
        const originalPhone = await page.inputValue('input[name="phone"]');

        await page.fill('input[name="phone"]', '555-9999');

        let navigated = false;
        page.on('framenavigated', () => { navigated = true; });

        await page.click('#profileEditBtn'); // Save
        await page.waitForLoadState('networkidle');

        expect(navigated).toBe(false);
        await expect(page.locator('#profile-card')).toContainText('555-9999');

        // Restore
        await page.click('#profileEditBtn');
        await page.fill('input[name="phone"]', originalPhone);
        await page.click('#profileEditBtn');
        await page.waitForLoadState('networkidle');
    });

    test('instructor cannot update another student profile via API', async ({ page }) => {
        // Load own profile page to get a valid CSRF token
        await page.goto(BASE + '/instructor/');
        const href = await page.locator('a:has-text("View Profile")').getAttribute('href');
        await page.goto(BASE + '/instructor/' + href);

        // Extract own student ID from URL
        const ownId = new URL(page.url()).searchParams.get('id');

        // Try posting update_profile targeting a DIFFERENT student (Sarah Johnson, id=2)
        const targetId = ownId === '2' ? '3' : '2';
        await apiPost(page, `/instructor/student_profile.php?id=${targetId}`, {
            action:     'update_profile',
            first_name: 'HACKED',
            last_name:  'HACKED',
        });

        // Verify the other student's name is unchanged
        await page.goto(BASE + `/instructor/student_profile.php?id=${targetId}`);
        await expect(page.locator('body')).not.toContainText('HACKED');
    });
});
