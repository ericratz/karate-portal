// @ts-check
// Playwright globalTeardown: always restore the DB snapshot after every run.
// This ensures tests never leave behind junk (fake accounts, stale records, etc.).
const { spawnSync } = require('child_process');
const path = require('path');
const fs   = require('fs');
const { getDbConfig, findMysqlBin } = require('./db-config');

const SNAPSHOT    = path.join(__dirname, '.db-snapshot.sql');
const STATUS_FILE = path.join(__dirname, '.test-status.json');

module.exports = async function globalTeardown() {
    let failed = false;
    let snapshotOk = false;

    try {
        const status = JSON.parse(fs.readFileSync(STATUS_FILE, 'utf8'));
        failed     = status.failed     ?? false;
        snapshotOk = status.snapshotOk ?? false;
    } catch {
        console.warn('[global-teardown] Could not read test-status file — skipping restore');
        return;
    }

    if (!snapshotOk || !fs.existsSync(SNAPSHOT)) {
        console.warn('[global-teardown] No snapshot available — DB not restored');
        cleanup();
        return;
    }

    const reason = failed ? 'Tests FAILED' : 'Tests passed';
    console.log(`[global-teardown] ${reason} — restoring DB snapshot...`);
    const { host, name, user, pass } = getDbConfig();
    const mysql = findMysqlBin('mysql');
    const args  = ['-h', host, '-u', user];
    if (pass) args.push(`-p${pass}`);
    args.push(name);

    const sql    = fs.readFileSync(SNAPSHOT);
    const result = spawnSync(mysql, args, {
        input:     sql,
        maxBuffer: 256 * 1024 * 1024,
    });

    if (result.status !== 0) {
        console.error('[global-teardown] Restore FAILED:');
        console.error(result.stderr?.toString() || '(no stderr)');
    } else {
        console.log('[global-teardown] DB restored successfully');
    }

    cleanup();
};

function cleanup() {
    try { fs.unlinkSync(SNAPSHOT);    } catch {}
    try { fs.unlinkSync(STATUS_FILE); } catch {}
}
