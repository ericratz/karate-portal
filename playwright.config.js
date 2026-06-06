// @ts-check
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests',
    testIgnore: '**/cleanup.spec.js',   // run manually: npx playwright test tests/cleanup.spec.js
    timeout: 12000,
    retries: 0,
    failFast: false,
    workers: 4,                // run up to 4 test files in parallel
    globalSetup:    './tests/global-setup.js',
    globalTeardown: './tests/global-teardown.js',
    reporter: [
        ['list'],
        ['html', { outputFolder: 'playwright-report', open: 'never' }],
        ['./tests/fail-reporter.js'],
    ],
    use: {
        baseURL: 'http://localhost/karate/portal',
        headless: true,
        screenshot: 'only-on-failure',
        video: 'off',
        channel: 'chrome',
    },
    projects: [
        { name: 'chrome', use: { channel: 'chrome' } },
    ],
});
