// @ts-check
/*
 * The board: a card dragged into another column, the same done from the
 * card's own select with a keyboard, and a card opened in the panel beside
 * the board and changed there.
 */
const { test, expect, makeProject, makeTicket, removeProject } = require('./helpers');

let project;
let ticket;

test.beforeAll(async ({ request }) => {
    project = await makeProject(request, 'Board');
    ticket = await makeTicket(request, project, 'Drag me across');
});

test.afterAll(async ({ request }) => {
    await removeProject(request, project);
});

const columns = (page) => page.locator('.board__row:not(.board__row--heads) .board__column');
const card = (page) => page.locator(`.ticket-card[data-ticket-id="${ticket.id}"]`);

test('a card dragged into another column is saved there, and the counts follow', async ({ page }) => {
    await page.goto(`projects/${project.id}`);

    const from = await card(page).locator('xpath=ancestor::section[contains(@class, "board__column")]').getAttribute('data-status-id');
    const target = columns(page).nth(2);
    const to = await target.getAttribute('data-status-id');
    expect(to).not.toBe(from);

    const saved = page.waitForResponse((r) => r.url().includes(`/tickets/${ticket.id}/move`) && r.request().method() === 'POST');
    await card(page).dragTo(target);
    const response = await saved;
    expect(response.ok()).toBeTruthy();
    expect(await response.json()).toMatchObject({ ok: true });

    await expect(target.locator(`[data-ticket-id="${ticket.id}"]`)).toBeVisible();
    await expect(page.locator(`[data-head-for="${to}"] [data-count]`)).toHaveText('1');
    await expect(page.locator(`[data-head-for="${from}"] [data-count]`)).toHaveText('0');
    await expect(card(page).locator('select[name="status"]')).toHaveValue(String(to));

    await page.reload();
    await expect(page.locator(`.board__column[data-status-id="${to}"] [data-ticket-id="${ticket.id}"]`)).toBeVisible();
});

test('with a keyboard, the card’s select waits for Enter before it moves the card', async ({ page }) => {
    await page.goto(`projects/${project.id}`);

    const first = await columns(page).first().getAttribute('data-status-id');
    const select = card(page).locator('select[name="status"]');

    // An arrow key on a closed select changes it on Windows: that alone
    // must not send the card anywhere.
    await select.focus();
    await page.keyboard.press('Shift');
    await select.selectOption(String(first));
    await expect(card(page).locator('form.move')).toHaveClass(/is-pending/);
    await expect(page).toHaveURL(new RegExp(`projects/${project.id}$`));

    await Promise.all([page.waitForLoadState('load'), select.press('Enter')]);
    await expect(page.locator(`.board__column[data-status-id="${first}"] [data-ticket-id="${ticket.id}"]`)).toBeVisible();
});

test('a card opens beside the board, and what changes there shows on the board', async ({ page }) => {
    await page.goto(`projects/${project.id}`);
    const address = page.url();

    await card(page).locator('a.ticket-card__title').click();
    const panel = page.locator('[data-panel-root]');
    await expect(panel).toBeVisible();
    await expect(panel).toContainText(ticket.title);
    expect(page.url()).toBe(address);

    const priority = panel.locator('[data-inline]', { has: page.locator('input[name="field"][value="priority"]') });
    await priority.locator('.inline__show').click();
    const saved = page.waitForResponse((r) => r.url().includes(`/tickets/${ticket.id}/field`));
    await priority.locator('select[name="value"]').selectOption('urgent');
    expect((await saved).ok()).toBeTruthy();

    await expect(card(page).locator('.badge--urgent')).toBeVisible();
    await expect(panel.locator('.badge--urgent')).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
});
