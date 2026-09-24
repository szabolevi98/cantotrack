// @ts-check
/*
 * A ticket's page: its facts changed where they are shown, without the page
 * reloading; the suggestions while writing a comment; a picture pasted into
 * one, a file dropped on the attachments; and a deleted comment taken back.
 */
const { test, expect, makeProject, makeTicket, removeProject, PIXEL } = require('./helpers');

let project;
let ticket;
let other;

test.beforeAll(async ({ request }) => {
    project = await makeProject(request, 'Ticket page');
    ticket = await makeTicket(request, project, 'Change me in place');
    other = await makeTicket(request, project, 'The one to point at');
});

test.afterAll(async ({ request }) => {
    await removeProject(request, project);
});

/** The page is the same page: nothing reloaded it since this was called. */
async function stay(page) {
    await page.evaluate(() => { window.__stayed = true; });
    return async () => expect(await page.evaluate(() => window.__stayed === true), 'the page did not reload').toBe(true);
}

const fact = (page, field) => page.locator('[data-inline]', { has: page.locator(`input[name="field"][value="${field}"]`) });

test('the title changes where it is shown, and Escape leaves it as it was', async ({ page }) => {
    await page.goto(`tickets/${ticket.id}`);
    const same = await stay(page);
    const heading = page.locator('h1');

    await page.locator('button[data-inline-for="title-form"]').click();
    await page.locator('#title-form input[name="value"]').fill('Not this');
    await page.keyboard.press('Escape');
    await expect(page.locator('#title-form')).toBeHidden();
    await expect(heading).toContainText('Change me in place');

    await page.locator('button[data-inline-for="title-form"]').click();
    await page.locator('#title-form input[name="value"]').fill('Changed in place');
    await page.keyboard.press('Enter');
    await expect(heading).toContainText('Changed in place');
    await expect(page.locator('#activity')).toContainText('Changed in place');
    await same();
});

test('a fact that cannot be read says so beside it, and is kept when it can', async ({ page }) => {
    await page.goto(`tickets/${ticket.id}`);
    const same = await stay(page);
    const estimate = fact(page, 'estimate');

    await estimate.locator('.inline__show').click();
    await estimate.locator('input[name="value"]').fill('sometime soon');
    await estimate.locator('input[name="value"]').press('Enter');
    await expect(estimate.locator('[data-inline-error]')).toBeVisible();
    await expect(estimate.locator('[data-inline-error]')).not.toBeEmpty();

    await estimate.locator('input[name="value"]').fill('2h 30m');
    await estimate.locator('input[name="value"]').press('Enter');
    await expect(fact(page, 'estimate').locator('.inline__show')).toContainText('2h 30m');
    await same();
});

test('the description is written over where it is read, and Ctrl+Enter keeps it', async ({ page }) => {
    await page.goto(`tickets/${ticket.id}`);
    const description = fact(page, 'description');

    await description.locator('.inline__show').click();
    await description.locator('textarea').fill('Some **bold** words.');
    await description.locator('textarea').press('Control+Enter');
    await expect(page.locator('[data-refresh="description"] strong')).toHaveText('bold');
});

test('“@” offers people and a key offers tickets, and what is taken is plain text', async ({ page, request }) => {
    const people = await (await request.get('suggest?kind=people&q=')).json();
    const handle = people.items[0].value;

    await page.goto(`tickets/${ticket.id}`);
    const body = page.locator('#body');
    const list = page.locator('ul.suggest');

    await body.click();
    await body.pressSequentially('Look, ' + handle.slice(0, 3));
    await expect(list).toBeVisible();
    await expect(list).toContainText(handle);
    await body.press('Enter');
    await expect(list).toBeHidden();
    await expect(body).toHaveValue(new RegExp('Look, ' + handle));

    await body.pressSequentially(' at ' + other.key.replace(/\d+$/, ''));
    await expect(list).toContainText(other.title);
    await list.locator('li', { hasText: other.title }).click();
    await expect(body).toHaveValue(new RegExp(other.key));

    await Promise.all([page.waitForURL(/#comment-\d+$/), page.locator('#comment-form button[type="submit"]').click()]);
    const comment = page.locator('.timeline__comment').last();
    await expect(comment.locator(`a[href$="/tickets/${other.id}"], a[href*="/t/${other.key}"]`).first()).toBeVisible();
});

test('a picture pasted into a comment is uploaded and put in as Markdown', async ({ page }) => {
    await page.goto(`tickets/${ticket.id}`);
    await page.locator('#body').click();

    await page.evaluate((pixel) => {
        const bytes = Uint8Array.from(atob(pixel), (c) => c.charCodeAt(0));
        const data = new DataTransfer();
        data.items.add(new File([bytes], 'pasted.png', { type: 'image/png' }));
        document.querySelector('#body').dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    }, PIXEL);

    await expect(page.locator('#body')).toHaveValue(/!\[pasted\.png\]\(\S+\/attachments\/\d+\)/);

    await page.reload();
    await expect(page.locator('#attachments img[alt="pasted.png"]')).toBeVisible();
});

test('a file dropped on the attachments is attached', async ({ page }) => {
    await page.goto(`tickets/${ticket.id}`);

    const loaded = page.waitForEvent('load');
    await page.evaluate(() => {
        const data = new DataTransfer();
        data.items.add(new File(['The brief.\n'], 'dropped-brief.txt', { type: 'text/plain' }));
        const area = document.querySelector('[data-drop-upload]');
        area.dispatchEvent(new DragEvent('dragover', { dataTransfer: data, bubbles: true, cancelable: true }));
        area.dispatchEvent(new DragEvent('drop', { dataTransfer: data, bubbles: true, cancelable: true }));
    });
    await loaded;

    await expect(page.locator('#attachments')).toContainText('dropped-brief.txt');
});

test('a comment deleted by mistake can be taken back', async ({ page }) => {
    await page.goto(`tickets/${ticket.id}`);
    await page.locator('#body').fill('Said and taken back.');
    await Promise.all([page.waitForURL(/#comment-\d+$/), page.locator('#comment-form button[type="submit"]').click()]);

    const comment = page.locator('.timeline__comment', { hasText: 'Said and taken back.' });
    await Promise.all([page.waitForLoadState('load'), comment.locator('form[action$="/delete"] button').click()]);
    await expect(page.locator('.timeline__comment', { hasText: 'Said and taken back.' })).toHaveCount(0);

    await Promise.all([page.waitForLoadState('load'), page.locator('form.undo button[type="submit"]').click()]);
    await expect(page.locator('.timeline__comment', { hasText: 'Said and taken back.' })).toBeVisible();
});
