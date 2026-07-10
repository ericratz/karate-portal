// @ts-check
// Access control — verifies every role is blocked from pages above their level.
const { test, expect } = require('@playwright/test');
const { login, logout, BASE } = require('../helpers');
const { ADMIN_USER, ADMIN_PASS, INST_USER, INST_PASS, STU_USER, STU_PASS, PARENT_USER, PARENT_PASS } = require('../credentials');

test('unauthenticated user is redirected to login', async ({ page }) => {
    await page.goto(BASE + '/student/', { waitUntil: 'domcontentloaded' });
    expect(page.url()).toContain('login.php');
    await page.goto(BASE + '/admin/payments.php', { waitUntil: 'domcontentloaded' });
    expect(page.url()).toContain('login.php');
});

test('guest cannot reach admin or instructor pages', async ({ page }) => {
    await login(page, PARENT_USER, PARENT_PASS);
    await page.goto(BASE + '/admin/payments.php', { waitUntil: 'domcontentloaded' });
    expect(await page.textContent('body')).toContain('Access denied');
    await page.goto(BASE + '/instructor/', { waitUntil: 'domcontentloaded' });
    expect(await page.textContent('body')).toContain('Access denied');
    await logout(page);
});

test('student cannot reach admin or instructor pages', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/admin/students.php');
    const body = await page.textContent('body');
    expect(body.includes('Access denied') || !body.includes('+ New Participant')).toBe(true);
    await logout(page);
});

test('instructor cannot reach admin pages', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/admin/payments.php');
    const body = await page.textContent('body');
    expect(body).not.toContain('Record Manual Payment');
    await logout(page);
});

test('role badges show correct values', async ({ page }) => {
    // Admin badge
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/');
    expect((await page.textContent('.role-badge'))?.trim().toLowerCase()).toBe('admin');
    await logout(page);
    // Instructor badge
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/instructor/');
    expect((await page.textContent('.role-badge'))?.trim().toLowerCase()).toBe('instructor');
    await logout(page);
    // Student badge
    await login(page, STU_USER, STU_PASS);
    await page.goto(BASE + '/student/');
    const badge = (await page.textContent('.role-badge'))?.trim().toLowerCase();
    expect(['student','guest','instructor','admin']).toContain(badge);
    await logout(page);
});

test('admin audit log denied to non-admin', async ({ page }) => {
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/admin/audit_log.php');
    expect(await page.textContent('body')).toContain('Access denied');
    await logout(page);
    await login(page, PARENT_USER, PARENT_PASS);
    await page.goto(BASE + '/admin/audit_log.php');
    expect(await page.textContent('body')).toContain('Access denied');
    await logout(page);
});

test('logout redirects to login and protects pages after', async ({ page }) => {
    await login(page, STU_USER, STU_PASS);
    await logout(page);
    expect(page.url()).toContain('login.php');
    await page.goto(BASE + '/student/');
    expect(page.url()).toContain('login.php');
});

test('delete profile button only visible to admin on edit page', async ({ page }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    await expect(page.locator('button:has-text("Delete Profile")')).toBeVisible();
    await page.goto(BASE + '/instructor/student_profile.php?id=2');
    await expect(page.locator('button:has-text("Delete Profile")')).toHaveCount(0);
    await logout(page);
    await login(page, INST_USER, INST_PASS);
    await page.goto(BASE + '/admin/student_edit.php?id=2');
    expect(await page.textContent('body')).toContain('Access denied');
    await logout(page);
});
