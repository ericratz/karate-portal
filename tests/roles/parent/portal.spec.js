// @ts-check
// Tests for the parent portal (portal/parent/).
// Covers access control — non-parent roles must all receive "Access denied".
// Integration tests (portal loads, child tabs, per-child pages) require a seeded
// parent account; see the NOTE comment at the bottom of this file.
const { test, expect } = require('@playwright/test');
const { BASE, AUTH } = require('../../helpers');

const PARENT_PAGES = [
    '/parent/',
    '/parent/belt_tests.php',
    '/parent/attendance.php',
    '/parent/pay.php',
    '/parent/payment_history.php',
    '/parent/profile_edit.php',
];

// ── UNAUTHENTICATED ───────────────────────────────────────────────────────────

test.describe('Parent pages — unauthenticated', () => {
    test('parent/index.php redirects unauthenticated users to login', async ({ page }) => {
        await page.goto(BASE + '/parent/');
        await page.waitForLoadState('domcontentloaded');
        expect(page.url()).toContain('login.php');
    });
});

// ── STUDENT ROLE DENIED ───────────────────────────────────────────────────────

test.describe('Parent pages - student role denied', () => {
    test.use({ storageState: AUTH.student });

    for (const path of PARENT_PAGES) {
        test(`${path} shows Access denied for student role`, async ({ page }) => {
            await page.goto(BASE + path);
            await page.waitForLoadState('domcontentloaded');
            const body = await page.textContent('body');
            expect(body).toContain('Access denied');
        });
    }
});

// ── INSTRUCTOR ROLE DENIED (except pay.php) ───────────────────────────────────
// pay.php is intentionally open to instructors so they can pay for linked children.

test.describe('Parent pages — instructor role denied', () => {
    test.use({ storageState: AUTH.instructor });

    test('parent/index.php is accessible to instructor role (shows own student data)', async ({ page }) => {
        await page.goto(BASE + '/parent/');
        await page.waitForLoadState('domcontentloaded');
        const body = await page.textContent('body');
        expect(body).not.toContain('Access denied');
    });

    test('parent/pay.php is accessible to instructor role', async ({ page }) => {
        await page.goto(BASE + '/parent/pay.php');
        await page.waitForLoadState('domcontentloaded');
        const body = await page.textContent('body');
        expect(body).not.toContain('Access denied');
    });
});

// ── ADMIN ROLE DENIED ─────────────────────────────────────────────────────────

test.describe('Parent pages - admin role denied', () => {
    test.use({ storageState: AUTH.admin });

    test('parent/index.php is accessible to admin role (shows own student data or no-profile message)', async ({ page }) => {
        await page.goto(BASE + '/parent/');
        await page.waitForLoadState('domcontentloaded');
        const body = await page.textContent('body');
        expect(body).not.toContain('Access denied');
    });
});

// ── NOTE: integration tests (portal loads, child tabs, per-child pages) ──────
// These require a parent account seeded in the DB snapshot:
//   1. users row: is_admin=0
//   2. students row linked via user_id, student_type='parent'
//   3. At least one child row in student_guardians
//   4. PARENT_USER / PARENT_PASS added to credentials.js
//   5. 'parent' auth state added to global-setup.js and helpers.js AUTH object
// Add those and write the integration tests at that point.
