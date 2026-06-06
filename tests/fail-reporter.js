// Minimal Playwright reporter that records whether the suite passed or failed.
// global-teardown.js reads this to decide whether to restore the DB snapshot.
const path = require('path');
const fs   = require('fs');

const STATUS_FILE = path.join(__dirname, '.test-status.json');

class FailReporter {
    onEnd(result) {
        try {
            const existing = JSON.parse(fs.readFileSync(STATUS_FILE, 'utf8'));
            existing.failed = result.status !== 'passed';
            existing.status = result.status;
            fs.writeFileSync(STATUS_FILE, JSON.stringify(existing));
        } catch {
            // If status file is missing (setup failed) just write fresh
            fs.writeFileSync(STATUS_FILE, JSON.stringify({
                failed: result.status !== 'passed',
                status: result.status,
                snapshotOk: false,
            }));
        }
    }
}

module.exports = FailReporter;
