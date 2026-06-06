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
    return {
        host: env.DB_HOST || 'localhost',
        name: env.DB_NAME || 'karate_portal',
        user: env.DB_USER || 'root',
        pass: env.DB_PASS || '',
    };
}

// Look for mysql/mysqldump in XAMPP's default Windows location, then fall back to PATH.
function findMysqlBin(exe) {
    const candidates = [
        // Non-standard XAMPP install path (this machine)
        `C:\\Users\\ericratz\\XAMPP\\mysql\\bin\\${exe}.exe`,
        // Standard XAMPP install path
        `C:\\xampp\\mysql\\bin\\${exe}.exe`,
        `C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\${exe}.exe`,
        `C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\${exe}.exe`,
        exe, // rely on PATH
    ];
    for (const c of candidates) {
        if (c === exe) return exe; // PATH fallback
        if (fs.existsSync(c))    return c;
    }
    return exe;
}

module.exports = { getDbConfig, findMysqlBin };
