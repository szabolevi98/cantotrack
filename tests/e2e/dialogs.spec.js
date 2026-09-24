// @ts-check
/*
 * The two dialogs that open from anywhere: Ctrl+K, which jumps to a ticket
 * by its key, and "l", which logs time on one without leaving the page.
 */
const { test, expect, makeProject, makeTicket, removeProject, post } = require('./helpers');

let project;
let ticket;

test.beforeAll(async ({ request }) => {
    project = await makeProject(request, 'Dialogs');
    ticket = await makeTicket(request, project, 'Somewhere to jump to');
});

test.afterAll(async ({ request }) => {
    // The hours go first: a project with hours in it is archived, not deleted.
    const page = await (await request.get(`tickets/${ticket.id}`)).text();
    for (const [, id] of page.matchAll(/\/worklogs\/(\d+)\/delete/g)) {
        await post(request, `worklogs/${id}/delete`);
    }
    await removeProject(request, project);
});

test('Ctrl+K jumps to a ticket by its key, and Escape closes it', async ({ page }) => {
    await page.goto('');
    const palette = page.locator('dialog#palette');

    await page.keyboard.press('Control+k');
    await expect(palette).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(palette).toBeHidden();

    await page.keyboard.press('Control+k');
    await page.locator('[data-palette-input]').fill(ticket.key);
    await expect(palette.locator('[data-palette-list]')).toContainText(ticket.title);
    await Promise.all([page.waitForURL(new RegExp(`/tickets/${ticket.id}$`)), page.keyboard.press('Enter')]);
    await expect(page.locator('h1')).toContainText(ticket.title);
});

test('“l” logs time on a ticket found by its name, and the page stays where it was', async ({ page }) => {
    await page.goto(`projects/${project.id}`);
    const dialog = page.locator('dialog#quick-log');

    await page.keyboard.press('l');
    await expect(dialog).toBeVisible();
    await expect(page.locator('#quick-ticket')).toBeFocused();

    await page.locator('#quick-ticket').pressSequentially('jump to');
    await expect(page.locator('#quick-suggestions')).toContainText(ticket.title);
    await page.locator('#quick-ticket').press('ArrowDown');
    await page.locator('#quick-ticket').press('Enter');
    await expect(page.locator('#quick-ticket')).toHaveValue(new RegExp(ticket.key));

    await page.locator('#quick-time').fill('45m');
    await page.locator('#quick-note').fill('Logged by the browser tests');
    await Promise.all([page.waitForLoadState('load'), dialog.locator('button[type="submit"]').first().click()]);

    await expect(page).toHaveURL(new RegExp(`projects/${project.id}`));
    await expect(page.locator(`.ticket-card[data-ticket-id="${ticket.id}"]`)).toContainText('45m');

    await page.goto(`tickets/${ticket.id}`);
    await expect(page.locator('.logs')).toContainText('Logged by the browser tests');
});
