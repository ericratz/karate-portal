// Shared helpers for all test files
const path = require('path');

const BASE = 'http://localhost/karate/portal';

const AUTH = {
    admin:      path.join(__dirname, '.auth', 'admin.json'),
    instructor: path.join(__dirname, '.auth', 'instructor.json'),
    student:    path.join(__dirname, '.auth', 'student.json'),
};

/**
 * Log in as a given role. Returns the page after landing on the dashboard.
 * Credentials must exist in your local DB.
 */
async function login(page, username, password) {
    await page.goto(BASE + '/login.php', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');
}

/**
 * Assert the page contains no PHP error/warning strings.
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
 */
async function visit(page, urlPath, label) {
    const res = await page.goto(BASE + urlPath, { waitUntil: 'domcontentloaded' });
    if (res.status() >= 400) {
        throw new Error(`HTTP ${res.status()} on ${urlPath} [${label}]`);
    }
    await assertNoPhpErrors(page, label || urlPath);
}

async function logout(page) {
    await page.goto(BASE + '/logout.php');
    await page.waitForLoadState('domcontentloaded');
}

/**
 * Delete a test student by finding their name in the admin student list,
 * navigating to their edit page, and clicking Delete Profile.
 * nameFragment should be unique enough to identify one row (e.g. a timestamp suffix).
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

/** Get the CSRF token from the current page's first hidden csrf_token input. */
async function getCsrfToken(page) {
    return page.$eval('input[name="csrf_token"]', el => el.value).catch(() => '');
}

/**
 * POST to a portal URL using the page's session cookie (includes CSRF token).
 * page must already be logged in and on a page that has a csrf_token input.
 * Returns { status, body }.
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
