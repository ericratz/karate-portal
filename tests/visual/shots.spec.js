// @ts-check
// Visual-review harness — NOT an assertion suite.
//
// Walks every significant page as each role and writes a full-page PNG per
// page per viewport into tests/visual/shots/. Nothing here fails on appearance;
// the output is meant to be looked at, so design problems (spacing, alignment,
// truncation, contrast, inconsistent controls) can be reviewed away from a
// browser and re-checked identically after a change.
//
// Gated behind VISUAL=1 rather than playwright.config.js's testIgnore, for two
// reasons: testIgnore excludes a file even when it is named explicitly on the
// command line (so the capture could never be run), and the config is baked
// into the ci image while tests/ is bind-mounted — an env guard needs no
// rebuild. The normal suite therefore skips these in milliseconds.
//
//   docker compose run --rm -e VISUAL=1 ci npx playwright test tests/visual/shots.spec.js
//
// Conventions from the rest of the suite apply: domcontentloaded (never
// networkidle), and SPA routes get an explicit render wait — screenshotting a
// React route without one reliably captures the loading spinner.

const { test, expect } = require('@playwright/test');
const path = require('path');
const { login, BASE } = require('../helpers');
const creds = require('../credentials');

const SHOTS = path.join(__dirname, 'shots');

const VIEWPORTS = [
    { tag: 'desktop', width: 1280, height: 800 },
    { tag: 'mobile', width: 390, height: 844 },
];

// Each entry: [label, url-path]. SPA routes are hash routes under a PHP shell.
const PAGES = {
    'logged-out': [
        ['login', '/login.php'],
        ['register', '/register.php'],
        ['forgot-password', '/forgot_password.php'],
        // Public, PIN-gated at submit — the page itself renders to anyone.
        ['checkin', '/checkin.php'],
    ],
    admin: [
        ['dashboard', '/admin/app.php#/admin'],
        ['roster', '/admin/app.php#/admin/roster'],
        ['payments', '/admin/app.php#/admin/payments'],
        ['users', '/admin/app.php#/admin/users'],
        ['donations', '/admin/app.php#/admin/donations'],
        ['expenses', '/admin/app.php#/admin/expenses'],
        ['exemptions', '/admin/app.php#/admin/waivers'],
        ['class-notes', '/admin/app.php#/admin/notes'],
        ['email-students', '/admin/app.php#/admin/email'],
        ['logs', '/admin/app.php#/admin/logs'],
        // Server-rendered, not an SPA route.
        ['security', '/admin/security.php'],
    ],
    instructor: [
        ['dashboard', '/instructor/app.php#/instructor'],
        ['roster', '/instructor/app.php#/instructor/roster'],
        ['classes', '/instructor/app.php#/instructor/classes'],
        ['take-attendance', '/instructor/app.php#/instructor/attendance'],
        ['belt-tests', '/instructor/app.php#/instructor/belt-tests'],
    ],
    parent: [
        ['dashboard', '/parent/app.php#/'],
        ['pay', '/parent/app.php#/pay'],
        ['profile-edit', '/parent/profile_edit.php'],
    ],
    student: [
        ['dashboard', '/student/app.php#/'],
        ['pay', '/student/app.php#/pay'],
        ['profile-edit', '/student/profile_edit.php'],
    ],
};

const LOGINS = {
    admin: [creds.ADMIN_USER, creds.ADMIN_PASS],
    instructor: [creds.INST_USER, creds.INST_PASS],
    parent: [creds.PARENT_USER, creds.PARENT_PASS],
    student: [creds.STU_USER, creds.STU_PASS],
};

/**
 * Load a page and wait until it has actually rendered.
 *
 * The two page kinds need different treatment, and treating them alike is
 * expensive: a server-rendered page is already complete at domcontentloaded and
 * has no #site-footer on some routes (login.php has none), so waiting for SPA
 * markers there burns the full timeout per shot for nothing.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} urlPath
 */
async function settle(page, urlPath) {
    const isSpaRoute = urlPath.includes('#');

    await page.goto(BASE + urlPath, { waitUntil: 'domcontentloaded' });

    if (isSpaRoute) {
        // A hash-only URL change does not reload the document, so React Router
        // would still be showing the previous route when we screenshot it.
        await page.reload({ waitUntil: 'domcontentloaded' });

        // The spinner is what SessionProvider shows until /me.php and
        // /family.php land; the footer proves the shared layout mounted.
        await page.locator('.spinner-border').first()
            .waitFor({ state: 'detached', timeout: 10000 }).catch(() => {});
        await page.locator('#site-footer')
            .waitFor({ state: 'attached', timeout: 10000 }).catch(() => {});
    }

    // Fail loudly when an authenticated page has bounced to the login screen.
    // Without this the capture still "works" — it just waits out the SPA
    // selectors on a login page it will never find them on, ~10s per shot, and
    // surfaces as a test timeout tens of captures later with nothing pointing at
    // the real cause. (It happened: a 2FA enrolment left on the admin account
    // from manual testing turned every admin page into a redirect.)
    if (urlPath !== '/login.php' && page.url().includes('/login.php')) {
        throw new Error(
            `Redirected to login while capturing ${urlPath} — the role is not authenticated. ` +
            `If an admin has 2FA enrolled in the dev database, clear it first.`
        );
    }

    // Chart.js entry animations and table paint — brief, and worth it for a
    // screenshot that is going to be judged on appearance.
    await page.waitForTimeout(600);
}

for (const [role, pages] of Object.entries(PAGES)) {
    test.describe(`visual — ${role}`, () => {
        test(`capture ${role}`, async ({ page }) => {
            test.skip(!process.env.VISUAL, 'visual capture — run with VISUAL=1');
            test.setTimeout(180000);

            if (role !== 'logged-out') {
                const [user, pass] = LOGINS[role];
                await login(page, user, pass);
            }

            for (const [label, urlPath] of pages) {
                for (const vp of VIEWPORTS) {
                    await page.setViewportSize({ width: vp.width, height: vp.height });
                    await settle(page, urlPath);
                    await page.screenshot({
                        path: path.join(SHOTS, `${role}--${label}--${vp.tag}.png`),
                        fullPage: true,
                    });
                }
            }

            // The capture is the deliverable; this only proves the walk ran.
            expect(pages.length).toBeGreaterThan(0);
        });
    });
}
