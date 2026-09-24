// @ts-check
/*
 * Signs in once, through the form, and keeps the session for every test —
 * in English, whatever the person had chosen, so that what the tests read is
 * what they expect.
 */
const { chromium } = require('@playwright/test');
const fs = require('fs');

module.exports = async function signIn(config) {
    const { baseURL, storageState, channel } = config.projects[0].use;
    const email = process.env.CT_EMAIL;
    const password = process.env.CT_PASSWORD;

    if (!email || !password) {
        throw new Error('Set CT_EMAIL and CT_PASSWORD to an administrator of the application at ' + baseURL);
    }

    const browser = await chromium.launch({ channel });
    const page = await browser.newPage({ baseURL });

    await page.goto('login');
    await page.locator('input[name="email"]').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/login')), page.locator('form button[type="submit"]').first().click()]);

    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    await page.request.post('locale', { form: { _token: token || '', locale: 'en', back: '/' }, maxRedirects: 0 });

    fs.mkdirSync('test-results', { recursive: true });
    await page.context().storageState({ path: String(storageState) });
    await browser.close();
};
