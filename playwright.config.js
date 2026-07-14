// @ts-check
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests',
    outputDir: 'tests/results',
    testIgnore: '**/cleanup.spec.js',   // run manually: npx playwright test tests/cleanup.spec.js
    timeout: 12000,
    // Env-driven so the containerized CI run (shared host resources) can use
    // fewer workers + retries for stability, while native keeps 6 workers / 0
    // retries. Set via PW_WORKERS / PW_RETRIES (docker-compose ci service).
    retries: process.env.PW_RETRIES ? Number(process.env.PW_RETRIES) : 0,
    workers: process.env.PW_WORKERS ? Number(process.env.PW_WORKERS) : 6,
    globalSetup:    './tests/global-setup.js',
    globalTeardown: './tests/global-teardown.js',
    reporter: [
        ['list'],
        ['html', { outputFolder: 'tests/report', open: 'never' }],
        ['./tests/fail-reporter.js'],
    ],
    use: {
        baseURL: process.env.TEST_BASE_URL || 'http://localhost/karate/portal',
        headless: true,
        screenshot: 'only-on-failure',
        video: 'off',
    },
    projects: [
        { name: 'chromium',},
    ],
});
