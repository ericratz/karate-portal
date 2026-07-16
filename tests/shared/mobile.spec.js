// @ts-check
// Mobile-viewport smoke tests: key pages must not overflow horizontally and
// critical controls must be reachable on a phone-sized screen.
// Functional behavior is covered by the desktop suites — this only guards
// layout regressions (the kind DevTools device mode catches by eye).
const { test, expect, devices } = require('@playwright/test');
const { assertNoPhpErrors, BASE, AUTH } = require('../helpers');

const iphone = devices['iPhone 13'];

/** Fail if the page itself scrolls sideways (tables may scroll inside cards). */
async function assertNoPageOverflow(page, label) {
    const overflow = await page.evaluate(() => {
        const el = document.documentElement;
        return el.scrollWidth - el.clientWidth;
    });
    expect(overflow, `${label}: page scrolls ${overflow}px sideways`).toBeLessThanOrEqual(0);
}

test.describe('Mobile layout — admin pages', () => {
    test.use({
        storageState: AUTH.admin,
        viewport: iphone.viewport,
        userAgent: iphone.userAgent,
        hasTouch: true,
        isMobile: true,
    });

    const pages = [
        ['/admin/',                            'admin dashboard'],
        ['/admin/students.php',                'roster'],
        ['/admin/payments.php',                'payments'],
        ['/instructor/attendance_sessions.php','classes'],
        ['/instructor/belt_tests_all.php',     'belt tests'],
    ];

    for (const [url, label] of pages) {
        test(`${label} has no horizontal page overflow`, async ({ page }) => {
            await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
            await assertNoPhpErrors(page, `mobile ${label}`);
            await assertNoPageOverflow(page, label);
        });
    }

    test('dashboard revenue chart is hidden on mobile', async ({ page }) => {
        await page.goto(BASE + '/admin/', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('#revenueChart')).toBeHidden();
    });

    test('navbar toggler opens the menu', async ({ page }) => {
        await page.goto(BASE + '/admin/', { waitUntil: 'domcontentloaded' });
        await page.click('.navbar-toggler');
        await expect(page.locator('#navMenu')).toBeVisible();
    });

    test('footer is collapsed to chevron; expands and collapses', async ({ page }) => {
        await page.goto(BASE + '/admin/', { waitUntil: 'domcontentloaded' });
        const grid = page.locator('#site-footer .footer-grid');
        await expect(grid).toBeHidden();
        await page.click('#footerCollapseBtn');
        await expect(grid).toBeVisible();
        await expect(page.locator('#site-footer')).toContainText('© 2026 Ratzlaff Family');
        await page.click('#footerCollapseBtn');
        await expect(grid).toBeHidden();
    });
});

test.describe('Mobile layout — student pages', () => {
    test.use({
        storageState: AUTH.student,
        viewport: iphone.viewport,
        userAgent: iphone.userAgent,
        hasTouch: true,
        isMobile: true,
    });

    const pages = [
        ['/student/',                    'student dashboard'],
        ['/student/payment_history.php', 'payment history'],
        ['/student/attendance.php',      'attendance'],
        ['/student/pay.php',             'pay page'],
    ];

    for (const [url, label] of pages) {
        test(`${label} has no horizontal page overflow`, async ({ page }) => {
            await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
            await assertNoPhpErrors(page, `mobile ${label}`);
            // Student pages are SPA routes now — wait for content before measuring
            await expect(page.locator('.card').first()).toBeVisible();
            await assertNoPageOverflow(page, label);
        });
    }

    test('student dashboard attendance chart is hidden on mobile', async ({ page }) => {
        await page.goto(BASE + '/student/', { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.card').first()).toBeVisible();
        await expect(page.locator('#attChart')).toBeHidden();
    });
});

test.describe('Mobile layout — parent pages', () => {
    test.use({
        storageState: AUTH.parent,
        viewport: iphone.viewport,
        userAgent: iphone.userAgent,
        hasTouch: true,
        isMobile: true,
    });

    test('parent dashboard: no overflow, attendance chart hidden', async ({ page }) => {
        await page.goto(BASE + '/parent/', { waitUntil: 'domcontentloaded' });
        await assertNoPhpErrors(page, 'mobile parent dashboard');
        await assertNoPageOverflow(page, 'parent dashboard');
        await expect(page.locator('#attChart')).toBeHidden();
    });

    test('parent pay page: no overflow', async ({ page }) => {
        await page.goto(BASE + '/parent/pay.php', { waitUntil: 'domcontentloaded' });
        await assertNoPhpErrors(page, 'mobile parent pay');
        // SPA route — wait for the fetched form before measuring
        await expect(page.locator('#studentSelect')).toBeVisible();
        await assertNoPageOverflow(page, 'parent pay page');
    });
});
