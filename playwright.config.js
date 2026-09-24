// @ts-check
/*
 * The browser tests (tests/e2e): the parts of CantoTrack that only a real
 * browser can check — cards dragged across the board, facts changed where
 * they are shown, the dialogs, the suggestions, pasted pictures. The smoke
 * test checks the same routes over plain HTTP; these check that the scripts
 * on top of them do what they say.
 *
 *   CT_URL       where the application is (default http://127.0.0.1:8080/)
 *   CT_EMAIL     an administrator to sign in as
 *   CT_PASSWORD  their password
 *   CT_CHANNEL   "chrome" to use the Chrome on the machine rather than the
 *                Chromium Playwright downloads
 *
 * One test at a time: they share one database and one signed-in person, and
 * a dashboard dragged in one must not be what another is looking at.
 */
const { defineConfig } = require('@playwright/test');

const base = (process.env.CT_URL || 'http://127.0.0.1:8080/').replace(/\/?$/, '/');

module.exports = defineConfig({
    testDir: 'tests/e2e',
    globalSetup: require.resolve('./tests/e2e/sign-in.js'),
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    timeout: 30_000,
    expect: { timeout: 7_000 },
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: base,
        storageState: 'test-results/.signed-in.json',
        channel: process.env.CT_CHANNEL || undefined,
        locale: 'en-GB',
        viewport: { width: 1440, height: 900 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
});
