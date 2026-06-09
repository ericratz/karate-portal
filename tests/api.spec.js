// @ts-check
// API-level tests — hit PHP endpoints directly via fetch/page.request.
// These test server-side validation that HTML form constraints prevent in the browser.
const { test, expect } = require('@playwright/test');
const { login, logout, getCsrfToken, BASE } = require('./helpers');
const { ADMIN_USER, ADMIN_PASS, INST_USER, INST_PASS, STU_USER, STU_PASS } = require('./credentials');

// ── AUTH / ACCESS CONTROL ─────────────────────────────────────────────────────

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

// ── REGISTRATION SERVER-SIDE VALIDATION ──────────────────────────────────────

test('registration rejects password shorter than 8 chars', async ({ page }) => {
    await page.goto(BASE + '/register.php');
    const token = await getCsrfToken(page);
    const res = await page.evaluate(async ({ url, token }) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
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
                csrf_token: token, first_name: 'Api', last_name: 'Test',
                date_of_birth: '2000-01-01', email: 'api2@test.com',
                username: adminUser, password: 'ValidPass1!', confirm: 'ValidPass1!',
            }).toString(),
        });
        return { status: res.status, body: await res.text() };
    }, { url: BASE + '/register.php', token, adminUser: ADMIN_USER });
    expect(res.body).toContain('already taken');
});

// ── PAYMENT HANDLER VALIDATION ────────────────────────────────────────────────

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
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/add_note.php?student_id=2');
    const token = await getCsrfToken(page);
    const res = await page.evaluate(async ({ url, token }) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: token, student_id: '2', content: '',
            }).toString(),
        });
        return { status: res.status, body: await res.text() };
    }, { url: BASE + '/instructor/add_note.php', token });
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

// ── .env NOT ACCESSIBLE VIA HTTP ─────────────────────────────────────────────

test('.env is blocked (not 200) and contains no credentials', async ({ request }) => {
    const res = await request.get('http://localhost/karate/.env');
    expect(res.status()).not.toBe(200);
    expect(await res.text()).not.toContain('DB_PASS=');
});
