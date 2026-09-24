// @ts-check
/*
 * Billing a month: a client's hours become a draft in one click, an entry
 * comes off it, the wording is changed, and the draft is thrown away again
 * — which frees its hours. (Issuing is for good, so it is left to the
 * integration tests, where the database is thrown away afterwards.)
 */
const { test, expect, post, makeTicket, removeProject } = require('./helpers');

const CLIENT = 'Browser test client';

let project;
let ticket;

const lastMonth = () => {
    const first = new Date();
    first.setDate(1);
    first.setMonth(first.getMonth() - 1);
    const month = first.getFullYear() + '-' + String(first.getMonth() + 1).padStart(2, '0');
    return { month, day: (d) => `${month}-${String(d).padStart(2, '0')}` };
};

test.beforeAll(async ({ request }) => {
    const code = 'E' + Math.floor(1000 + Math.random() * 9000);
    const made = await post(request, 'projects/create', { code, name: `Billing ${code}`, description: 'Made by the browser tests.', client: CLIENT, billable_default: 1 });
    project = { id: Number((made.headers()['location'] || '').match(/\/projects\/(\d+)/)?.[1]), code };
    ticket = await makeTicket(request, project, 'Billed work');

    const { day } = lastMonth();
    for (const [date, time] of [[day(3), '2h'], [day(4), '1h 30m']]) {
        await post(request, `tickets/${ticket.id}/log`, { time, work_date: date, billable_sent: 1, billable: 1, note: 'For the browser tests' });
    }
});

test.afterAll(async ({ request }) => {
    const page = await (await request.get(`tickets/${ticket.id}`)).text();
    for (const [, id] of page.matchAll(/\/worklogs\/(\d+)\/delete/g)) {
        await post(request, `worklogs/${id}/delete`);
    }
    await removeProject(request, project);

    // And the client, which nothing names any more.
    const clients = await (await request.get('clients')).text();
    const match = clients.match(new RegExp('/clients/(\\d+)">\\s*<strong>' + CLIENT));
    if (match) {
        await post(request, `clients/${match[1]}/delete`);
    }
});

test('a client’s month becomes a draft, is corrected, and is thrown away again', async ({ page }) => {
    const { month } = lastMonth();
    await page.goto(`billing?month=${month}`);

    const row = page.locator('#unbilled tr', { hasText: CLIENT });
    await expect(row).toContainText('3.5');
    await Promise.all([page.waitForURL(/billing\/statements\/\d+$/), row.locator('button[type="submit"]').click()]);

    const entries = page.locator('.statement__entries tbody tr');
    await expect(entries).toHaveCount(2);
    await expect(page.locator('.statement__total')).toContainText('3.5');

    // One entry off: it is not billed, now or later.
    await Promise.all([page.waitForLoadState('load'), entries.first().locator('form button').click()]);
    await expect(entries).toHaveCount(1);
    await expect(page.locator('.statement__total')).toContainText('1.5');

    await page.locator('#bill_to').fill('Browser Test Client Ltd.\n1 Test Street');
    await Promise.all([page.waitForLoadState('load'), page.locator('form[action$="/wording"] button').click()]);
    await expect(page.locator('.statement__to')).toContainText('1 Test Street');

    await expect(page.locator('[data-print]')).toBeVisible();

    // And as a PDF, named for it.
    const [download] = await Promise.all([page.waitForEvent('download'), page.locator('a[href$="/pdf"]').click()]);
    expect(download.suggestedFilename()).toMatch(/^statement-draft-\d+-Browser-test-client\.pdf$/);

    await Promise.all([page.waitForURL(/billing\?month=/), page.locator('form[action$="/delete"] button').click()]);
    await expect(page.locator('#unbilled tr', { hasText: CLIENT })).toContainText('1.5');
});
