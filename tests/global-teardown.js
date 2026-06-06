// Playwright globalTeardown: restore the DB snapshot if any tests failed.
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

    if (!failed) {
        console.log('[global-teardown] Tests passed — skipping DB restore');
        cleanup();
        return;
    }

    if (!snapshotOk || !fs.existsSync(SNAPSHOT)) {
        console.warn('[global-teardown] Tests FAILED but no snapshot is available — DB not restored');
        cleanup();
        return;
    }

    console.log('[global-teardown] Tests FAILED — restoring DB snapshot...');
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
