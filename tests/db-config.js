// @ts-check
// Shared DB config + mysql binary resolution for global-setup/teardown
const path = require('path');
const fs   = require('fs');

function getDbConfig() {
    const envPath = path.join(__dirname, '..', '.env');
    const env = {};
    if (fs.existsSync(envPath)) {
        for (const line of fs.readFileSync(envPath, 'utf8').split('\n')) {
            const trimmed = line.trim();
            if (!trimmed || trimmed.startsWith('#') || !trimmed.includes('=')) continue;
            const [k, ...rest] = trimmed.split('=');
            env[k.trim()] = rest.join('=').trim();
        }
    }
    // Real env vars (set by docker-compose for the ci container) win over .env,
    // mirroring db.php — so DB_HOST=db is used in-container, localhost natively.
    return {
        host: process.env.DB_HOST || env.DB_HOST || 'localhost',
        name: process.env.DB_NAME || env.DB_NAME || 'karate_portal',
        user: process.env.DB_USER || env.DB_USER || 'root',
        pass: process.env.DB_PASS || env.DB_PASS || '',
    };
}

// Resolve the mysql/mysqldump binary. In Docker/CI the client is on PATH
// (default-mysql-client in the ci image), so the plain name resolves. The
// Windows fallbacks keep a native, non-container dev run working too.
function findMysqlBin(exe) {
    const candidates = [
        `C:\\Users\\ericratz\\XAMPP\\mysql\\bin\\${exe}.exe`,
        `C:\\xampp\\mysql\\bin\\${exe}.exe`,
    ];
    for (const c of candidates) {
        if (fs.existsSync(c)) return c;
    }
    return exe; // rely on PATH (Docker, Linux, or mysql already on PATH)
}

module.exports = { getDbConfig, findMysqlBin };
