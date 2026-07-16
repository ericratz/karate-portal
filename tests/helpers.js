// @ts-check
// Shared helpers for all test files
const path = require('path');

// Env-driven so Playwright can run either natively (default localhost) or
// inside the ci container against the app service (TEST_BASE_URL=http://app/...).
const BASE = process.env.TEST_BASE_URL || 'http://localhost/karate/portal';

const AUTH = {
    admin:      path.join(__dirname, '.auth', 'admin.json'),
    instructor: path.join(__dirname, '.auth', 'instructor.json'),
    student:    path.join(__dirname, '.auth', 'student.json'),
    parent:     path.join(__dirname, '.auth', 'parent.json'),
};

/**
 * Log in as a given role. Returns the page after landing on the dashboard.
 * Credentials must exist in your local DB.
 * @param {import('@playwright/test').Page} page
 * @param {string} username
 * @param {string} password
 * @returns {Promise<void>}
 */
async function login(page, username, password) {
    await page.goto(BASE + '/login.php', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    // Race the click against the navigation it triggers — awaiting them
    // sequentially can occasionally miss a fast navigation that completes
    // before the second await starts listening, hanging until timeout.
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.click('button[type="submit"]'),
    ]);
}

/**
 * Assert the page contains no PHP error/warning strings.
 * @param {import('@playwright/test').Page} page
 * @param {string} context
 * @returns {Promise<void>}
 */
async function assertNoPhpErrors(page, context) {
    const body = await page.textContent('body');
    const markers = [
        'Fatal error',
        'Parse error',
        'Uncaught',
        'Warning:',
        'Deprecated:',
        'Call to undefined',
        'SQLSTATE',
        // Note: PHP Notices are intentionally excluded — they are non-fatal
        // informational messages and do not indicate broken functionality.
    ];
    for (const m of markers) {
        if (body.includes(m)) {
            throw new Error(`PHP error on ${page.url()} [${context}]: "${m}" found in page`);
        }
    }
}

/**
 * Visit a URL, assert HTTP 200 and no PHP errors.
 * @param {import('@playwright/test').Page} page
 * @param {string} urlPath
 * @param {string} [label]
 * @returns {Promise<void>}
 */
async function visit(page, urlPath, label) {
    const res = await page.goto(BASE + urlPath, { waitUntil: 'domcontentloaded' });
    if (res.status() >= 400) {
        throw new Error(`HTTP ${res.status()} on ${urlPath} [${label}]`);
    }
    await assertNoPhpErrors(page, label || urlPath);
}

/**
 * Log out and land back on the login page.
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<void>}
 */
async function logout(page) {
    // Leaving an SPA page can race: an in-flight api/v1 fetch 401s once the
    // session dies and the client redirects to login.php itself, aborting our
    // navigation (net::ERR_ABORTED) even though both roads end at login.
    // Tolerate the loser, then make sure the session is actually gone.
    await page.goto(BASE + '/logout.php').catch(() => {});
    await page.waitForLoadState('domcontentloaded');
    if (!page.url().includes('login.php')) {
        await page.goto(BASE + '/logout.php');
        await page.waitForLoadState('domcontentloaded');
    }
}

/**
 * Delete a test student by finding their name in the admin student list,
 * navigating to their edit page, and clicking Delete Profile.
 * nameFragment should be unique enough to identify one row (e.g. a timestamp suffix).
 * @param {import('@playwright/test').Page} page
 * @param {string} nameFragment
 * @param {string} adminUser
 * @param {string} adminPass
 * @returns {Promise<void>}
 */
async function deleteTestStudent(page, nameFragment, adminUser, adminPass) {
    await login(page, adminUser, adminPass);
    await page.goto(BASE + '/admin/students.php');
    // students.php has no Edit link in rows — only a student_profile.php link.
    // Extract the student ID from the profile link href, then go to student_edit.php.
    const row = page.locator('tr').filter({ hasText: nameFragment });
    if (await row.count() === 0) return; // already gone
    const profileHref = await row.locator('a[href*="student_profile.php"]').first().getAttribute('href');
    if (!profileHref) return;
    const match = profileHref.match(/[?&]id=(\d+)/);
    if (!match) return;
    await page.goto(BASE + '/admin/student_edit.php?id=' + match[1]);
    await page.waitForLoadState('domcontentloaded');
    const deleteBtn = page.locator('button:has-text("Delete Profile")');
    if (!await deleteBtn.isVisible()) return;
    page.once('dialog', d => d.accept());
    await deleteBtn.click();
    await page.waitForLoadState('domcontentloaded');
}

/**
 * Get the CSRF token from the current page's first hidden csrf_token input.
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string>}
 */
async function getCsrfToken(page) {
    return page.$eval('input[name="csrf_token"]', el => /** @type {HTMLInputElement} */ (el).value).catch(() => '');
}

/**
 * POST to a portal URL using the page's session cookie (includes CSRF token).
 * page must already be logged in and on a page that has a csrf_token input.
 * Returns { status, body }.
 * @param {import('@playwright/test').Page} page
 * @param {string} path
 * @param {Record<string, string>} params
 * @returns {Promise<{status: number, body: string}>}
 */
async function apiPost(page, path, params) {
    const token = await getCsrfToken(page);
    const form = new URLSearchParams({ ...params, csrf_token: token }).toString();
    const result = await page.evaluate(async ({ url, body }) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        return { status: res.status, body: await res.text() };
    }, { url: BASE + path, body: form });
    return result;
}

module.exports = { login, logout, assertNoPhpErrors, visit, getCsrfToken, apiPost, deleteTestStudent, BASE, AUTH };
