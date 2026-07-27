// @ts-check
// Web-server hardening — the rules in the project's root .htaccess.
//
// These exist because the .htaccess was silently doing nothing in Docker: it was
// never copied into the app image, and Apache ran with AllowOverride None, so
// every rule in it was inert in dev and CI while looking present in the tracked
// config. Live (StackCP) honoured it, so dev and CI were strictly less hardened
// than production and nothing failed to say so.
//
// Nothing here asserted anything before, which is exactly why that survived.

const { test, expect } = require('@playwright/test');
const { BASE } = require('../helpers');

// BASE is .../karate/portal; the .htaccess and these directories sit one level
// up, at the /karate/ document root.
const ROOT = BASE.replace(/\/portal\/?$/, '');

test.describe('security headers', () => {
    // Set by .htaccess for every response. Content-Security-Policy is
    // deliberately excluded — it is emitted from PHP (auth.php / checkin.php) so
    // it can carry a per-request nonce, and is covered by the CSP specs.
    const EXPECTED = {
        'x-frame-options': 'SAMEORIGIN',
        'x-content-type-options': 'nosniff',
        'referrer-policy': 'strict-origin-when-cross-origin',
        'permissions-policy': 'geolocation=(), microphone=(), camera=()',
        'strict-transport-security': 'max-age=31536000; includeSubDomains',
    };

    for (const [header, value] of Object.entries(EXPECTED)) {
        test(`${header} is set on a page response`, async ({ request }) => {
            const res = await request.get(BASE + '/login.php');
            expect(res.status()).toBe(200);
            expect(res.headers()[header]).toBe(value);
        });
    }
});

test.describe('non-web paths are not reachable over HTTP', () => {
    // portal/cron/*.php run unattended with no auth check; includes/ and vendor/
    // are library code. All were fetchable in the container before V5.0.
    const FORBIDDEN = [
        '/portal/cron/backup.php',
        '/portal/cron/attendance_alert.php',
        '/portal/includes/config.php',
        '/portal/includes/db.php',
        '/portal/includes/paypal.php',
        '/portal/vendor/autoload.php',
        '/tests/helpers.js',
    ];

    for (const path of FORBIDDEN) {
        test(`${path} is denied`, async ({ request }) => {
            const res = await request.get(ROOT + path, { maxRedirects: 0 });
            // 403 when the file exists and is blocked; 404 is equally acceptable
            // for paths excluded from the deployed tree. What must never happen
            // is a 200 serving the content.
            expect([403, 404]).toContain(res.status());
        });
    }
});

test.describe('cron scripts refuse to run over HTTP', () => {
    // Defence that does not depend on webserver configuration being right: even
    // if the .htaccess rule is lost again, the script itself must refuse.
    // Asserted by reading the file, since the HTTP request is (correctly)
    // blocked upstream by Apache before PHP is ever reached.
    //
    // portal/cron/ is gitignored, so a clean checkout — which is exactly what CI
    // does — has no such directory and these can only be checked where the files
    // actually live. Skipping is honest about that; failing on a file the
    // repository does not contain is not a real signal. The HTTP-level checks
    // above still run everywhere.
    const fs = require('fs');
    const path = require('path');
    const CRON = path.join(__dirname, '..', '..', 'portal', 'cron');
    const present = fs.existsSync(CRON);

    for (const f of ['backup.php', 'attendance_alert.php', 'rent_reminder.php', 'waiver_alert.php']) {
        test(`${f} has a CLI-only guard`, () => {
            test.skip(!present, 'portal/cron is gitignored — not present in a clean checkout');
            const src = fs.readFileSync(path.join(CRON, f), 'utf8');
            expect(src).toContain("PHP_SAPI !== 'cli'");
        });
    }
});

test('the .env file is never served', async ({ request }) => {
    const res = await request.get(ROOT + '/.env', { maxRedirects: 0 });
    expect([403, 404]).toContain(res.status());
});
