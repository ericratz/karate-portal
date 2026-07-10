// @ts-check
// API-level tests â€” hit PHP endpoints directly via fetch/page.request.
// These test server-side validation that HTML form constraints prevent in the browser.
const { test, expect } = require('@playwright/test');
const { login, logout, getCsrfToken, BASE } = require('../helpers');
const { ADMIN_USER, ADMIN_PASS, INST_USER, INST_PASS, STU_USER, STU_PASS } = require('../credentials');

// â”€â”€ AUTH / ACCESS CONTROL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('GET to protected admin page without session redirects to login', async ({ request }) => {
    const res = await request.get(BASE + '/admin/payments.php', { maxRedirects: 0 });
    expect([302, 301]).toContain(res.status());
});

test('POST to protected endpoint without session redirects to login', async ({ request }) => {
    const res = await request.post(BASE + '/admin/payments.php', {
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        data: 'action=add_payment&amount=30',
        maxRedirects: 0,
    });
    expect([302, 301, 403]).toContain(res.status());
});

test('POST without CSRF token returns 403', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    const status = await page.evaluate(async (url) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=delete_payment&payment_id=99999',
        });
        return res.status;
    }, BASE + '/admin/payments.php');
    expect(status).toBe(403);
    await logout(page);
});

test('POST with wrong CSRF token returns 403', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    const status = await page.evaluate(async (url) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=delete_payment&payment_id=99999&csrf_token=badtoken',
        });
        return res.status;
    }, BASE + '/admin/payments.php');
    expect(status).toBe(403);
    await logout(page);
});

// â”€â”€ REGISTRATION SERVER-SIDE VALIDATION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('registration rejects password shorter than 8 chars', async ({ page }) => {
    await page.goto(BASE + '/register.php');
    const token = await getCsrfToken(page);
    const res = await page.evaluate(async ({ url, token }) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'step1',
                csrf_token: token, first_name: 'Api', last_name: 'Test',
                date_of_birth: '2000-01-01', email: 'api@test.com',
                username: 'apitestuser1', password: 'abc123!', confirm: 'abc123!',
            }).toString(),
        });
        return { status: res.status, body: await res.text() };
    }, { url: BASE + '/register.php', token });
    expect(res.body).toContain('8 characters');
});

test('registration rejects duplicate username', async ({ page }) => {
    await page.goto(BASE + '/register.php');
    const token = await getCsrfToken(page);
    const res = await page.evaluate(async ({ url, token, adminUser }) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'step1',
                csrf_token: token, first_name: 'Api', last_name: 'Test',
                date_of_birth: '2000-01-01', email: 'api2@test.com',
                username: adminUser, password: 'ValidPass1!', confirm: 'ValidPass1!',
            }).toString(),
        });
        return { status: res.status, body: await res.text() };
    }, { url: BASE + '/register.php', token, adminUser: ADMIN_USER });
    expect(res.body).toContain('already taken');
});

// â”€â”€ PAYMENT HANDLER VALIDATION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('add payment with amount=0 does not save a payment', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/payments.php?action=add');
    const token = await getCsrfToken(page);
    await page.evaluate(async ({ url, token }) => {
        await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: token, action: 'add_payment',
                student_id: '2', amount: '0',
                payment_type: 'monthly_tuition', payment_method: 'cash',
            }).toString(),
        });
    }, { url: BASE + '/admin/payments.php', token });
    // Verify zero-amount payment was not recorded (check page still shows no $0.00 payment today)
    await page.goto(BASE + '/admin/payments.php');
    const rows = await page.locator('tbody td').allTextContents();
    const hasZero = rows.some(t => t.trim() === '$0.00');
    expect(hasZero).toBe(false);
    await logout(page);
});

test('add note with empty content is rejected by server', async ({ page }) => {
    // add_note.php was removed — note-adding is now an inline form on
    // instructor/student_profile.php.
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/student_profile.php?id=2');
    const token = await getCsrfToken(page);
    const res = await page.evaluate(async ({ url, token }) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: token, action: 'add_note', note_content: '',
            }).toString(),
        });
        return { status: res.status, body: await res.text() };
    }, { url: BASE + '/instructor/student_profile.php?id=2', token });
    expect(res.body).toContain('empty');
    await logout(page);
});

test('student edit handler rejects invalid student ID', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/student_edit.php?id=99999');
    await page.waitForLoadState('domcontentloaded');
    // Should redirect or show an empty form, not crash
    const body = await page.textContent('body');
    expect(body).not.toContain('Fatal error');
    expect(body).not.toContain('SQLSTATE');
    await logout(page);
});

// â”€â”€ .env NOT ACCESSIBLE VIA HTTP â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('.env is blocked (not 200) and contains no credentials', async ({ request }) => {
    const res = await request.get('http://localhost/karate/.env');
    expect(res.status()).not.toBe(200);
    expect(await res.text()).not.toContain('DB_PASS=');
});

// ── CSP REPORT ENDPOINT ───────────────────────────────────────────────────────

test('csp_report.php rejects GET with 405', async ({ request }) => {
    const res = await request.get(BASE + '/api/csp_report.php', { maxRedirects: 0 });
    expect(res.status()).toBe(405);
});

test('csp_report.php rejects POST with no body with 400', async ({ request }) => {
    const res = await request.post(BASE + '/api/csp_report.php', {
        headers: { 'Content-Type': 'application/csp-report' },
        data: '',
    });
    expect(res.status()).toBe(400);
});

test('csp_report.php accepts valid CSP report and returns 204', async ({ request }) => {
    const res = await request.post(BASE + '/api/csp_report.php', {
        headers: { 'Content-Type': 'application/csp-report' },
        data: JSON.stringify({
            'csp-report': {
                'document-uri': 'http://localhost/karate/portal/student/',
                'violated-directive': 'script-src',
                'blocked-uri': 'https://evil.example.com/script.js',
            }
        }),
    });
    expect(res.status()).toBe(204);
});

// ── PAYPAL API ENDPOINTS ──────────────────────────────────────────────────────

test('paypal_subscription_create.php rejects unauthenticated request', async ({ request }) => {
    const res = await request.get(BASE + '/api/paypal_subscription_create.php', { maxRedirects: 0 });
    // require_login() redirects to login.php
    expect([301, 302]).toContain(res.status());
});

test('paypal_subscription_return.php rejects unauthenticated request', async ({ request }) => {
    const res = await request.get(BASE + '/api/paypal_subscription_return.php', { maxRedirects: 0 });
    expect([301, 302]).toContain(res.status());
});

test('paypal_subscription_return.php with no subscription_id redirects to error page', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/api/paypal_subscription_return.php');
    await page.waitForLoadState('domcontentloaded');
    // Missing ?subscription_id= → redirect to pay.php?autopay=error
    expect(page.url()).toContain('autopay=error');
    await logout(page);
});

// ── ADMIN DB BACKUP ───────────────────────────────────────────────────────────

test('db_backup.php returns SQL content for admin', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    // Use fetch so we get the response body (Content-Disposition:attachment triggers download in real browsers)
    const result = await page.evaluate(async (url) => {
        const res = await fetch(url, { credentials: 'include' });
        const body = await res.text();
        return { status: res.status, ct: res.headers.get('content-type') || '', snippet: body.slice(0, 120) };
    }, BASE + '/admin/db_backup.php');
    expect(result.status).toBe(200);
    expect(result.snippet).toContain('Database backup');
    await logout(page);
});

test('db_backup.php denies student role', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/admin/db_backup.php');
    await page.waitForLoadState('domcontentloaded');
    const body = await page.textContent('body');
    const denied = body.includes('Access denied') || page.url().includes('login.php');
    expect(denied).toBe(true);
    await logout(page);
});
