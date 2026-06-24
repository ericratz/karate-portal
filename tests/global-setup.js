// Playwright globalSetup: snapshot the database before any tests run.
// The snapshot is restored by global-teardown.js if tests fail.
const { chromium } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const fs   = require('fs');
const http = require('http');
const { getDbConfig, findMysqlBin } = require('./db-config');
const { ADMIN_USER, ADMIN_PASS, INST_USER, INST_PASS, STU_USER, STU_PASS, PARENT_USER, PARENT_PASS } = require('./credentials');

const SNAPSHOT    = path.join(__dirname, '.db-snapshot.sql');
const STATUS_FILE = path.join(__dirname, '.test-status.json');

/** Clear rate-limit table so localhost tests can always log in. */
function clearRateLimits() {
    return new Promise((resolve) => {
        const req = http.get('http://localhost/karate/tests/clear_rate_limit.php', (res) => {
            res.resume();
            res.on('end', () => resolve());
        });
        req.on('error', () => resolve()); // ignore errors (server may be off)
        req.end();
    });
}

async function createAuthStates(authDir) {
    const browser = await chromium.launch({ channel: 'chrome' });
    const BASE = 'http://localhost/karate/portal';
    const roles = [
        ['admin',      ADMIN_USER,   ADMIN_PASS],
        ['instructor', INST_USER,    INST_PASS],
        ['student',    STU_USER,     STU_PASS],
        ['parent',     PARENT_USER,  PARENT_PASS],
    ];
    for (const [role, user, pass] of roles) {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        await page.goto(`${BASE}/login.php`, { waitUntil: 'domcontentloaded' });
        await page.fill('input[name="username"]', user);
        await page.fill('input[name="password"]', pass);
        await page.click('button[type="submit"]');
        await page.waitForLoadState('domcontentloaded');
        await ctx.storageState({ path: path.join(authDir, `${role}.json`) });
        await ctx.close();
    }
    await browser.close();
    console.log('[global-setup] Auth states saved for admin, instructor, student, parent');
}

module.exports = async function globalSetup() {
    await clearRateLimits();
    console.log('[global-setup] Login rate-limit table cleared.');

    const AUTH_DIR = path.join(__dirname, '.auth');
    if (!fs.existsSync(AUTH_DIR)) fs.mkdirSync(AUTH_DIR);

    const { host, name, user, pass } = getDbConfig();
    const mysqldump = findMysqlBin('mysqldump');

    const args = ['-h', host, '-u', user];
    if (pass) args.push(`-p${pass}`);
    args.push('--single-transaction', '--skip-lock-tables', '--routines', name);

    async function runDump() {
        let dump;
        try {
            dump = execFileSync(mysqldump, args, { maxBuffer: 256 * 1024 * 1024 });
        } catch (err) {
            console.error('[global-setup] mysqldump failed:', err.message);
            console.error('  DB will NOT be restored on failure. Check that mysqldump is in PATH or XAMPP is installed.');
            // Write a status file so teardown knows the snapshot is missing
            fs.writeFileSync(STATUS_FILE, JSON.stringify({ failed: false, snapshotOk: false }));
            return;
        }

        fs.writeFileSync(SNAPSHOT, dump);
        fs.writeFileSync(STATUS_FILE, JSON.stringify({ failed: false, snapshotOk: true }));
        console.log(`[global-setup] DB snapshot saved (${(dump.length / 1024).toFixed(0)} KB)`);
    }

    await Promise.all([
        runDump(),
        createAuthStates(AUTH_DIR),
    ]);
};
