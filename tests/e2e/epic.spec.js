// @ts-check
/*
 * An epic: its days changed where they are shown, a comment, following it,
 * finishing it — and its bar dragged across the roadmap.
 */
const { test, expect, makeProject, makeEpic, removeProject, day } = require('./helpers');

let project;
let epic;

test.beforeAll(async ({ request }) => {
    project = await makeProject(request, 'Epic');
    epic = await makeEpic(request, project, 'Checkout, end to end', { starts_on: day(3), ends_on: day(24) });
});

test.afterAll(async ({ request }) => {
    await removeProject(request, project);
});

const fact = (page, field) => page.locator('[data-inline]', { has: page.locator(`input[name="field"][value="${field}"]`) });

test('its end day changes in place, and one before its start is refused beside it', async ({ page }) => {
    await page.goto(`epics/${epic}`);
    const ends = fact(page, 'ends_on');

    await ends.locator('.inline__show').click();
    await ends.locator('input[name="value"]').fill(day(1));
    await ends.locator('input[name="value"]').press('Enter');
    await expect(ends.locator('[data-inline-error]')).toBeVisible();

    await ends.locator('input[name="value"]').fill('2031-03-14');
    await ends.locator('input[name="value"]').press('Enter');
    await expect(fact(page, 'ends_on').locator('.inline__show')).toContainText('2031');

    await page.goto(`epics/${epic}?activity=history`);
    await expect(page.locator('#activity')).toContainText('end day');
});

test('a comment on it, and following it or not', async ({ page }) => {
    await page.goto(`epics/${epic}`);
    await page.locator('#body').fill('What is left is the bank transfer.');
    await Promise.all([page.waitForURL(/#comment-\d+$/), page.locator('#comment-form button[type="submit"]').click()]);
    await expect(page.locator('.timeline__comment')).toContainText('What is left is the bank transfer.');

    const follow = page.locator(`form[action$="/epics/${epic}/watch"] button`);
    await expect(follow).toHaveAttribute('aria-pressed', 'true');
    await Promise.all([page.waitForLoadState('load'), follow.click()]);
    await expect(follow).toHaveAttribute('aria-pressed', 'false');
    await Promise.all([page.waitForLoadState('load'), follow.click()]);
    await expect(follow).toHaveAttribute('aria-pressed', 'true');
});

test('its bar dragged along the roadmap moves both its days', async ({ page }) => {
    await page.goto(`epics/${epic}`);
    const ends = fact(page, 'ends_on');
    await ends.locator('.inline__show').click();
    await ends.locator('input[name="value"]').fill(day(24));
    await ends.locator('input[name="value"]').press('Enter');
    await expect(ends.locator('[data-inline-error]')).toBeHidden();

    await page.goto(`projects/${project.id}/roadmap`);
    const bar = page.locator(`[data-epic="${epic}"]`);
    await expect(bar).toBeVisible();
    const start = await bar.getAttribute('data-start');
    const end = await bar.getAttribute('data-end');
    const box = await bar.boundingBox();
    if (!box) {
        throw new Error('The bar is not drawn.');
    }

    const saved = page.waitForResponse((r) => r.url().includes(`/epics/${epic}/days`));
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2 + 120, box.y + box.height / 2, { steps: 12 });
    await page.mouse.up();
    // The page reloads at once after the answer, so its status is what is left to read.
    expect((await saved).status()).toBe(200);

    await page.waitForLoadState('load');
    const moved = page.locator(`[data-epic="${epic}"]`);
    await expect(moved).not.toHaveAttribute('data-start', String(start));
    const newStart = String(await moved.getAttribute('data-start'));
    const newEnd = String(await moved.getAttribute('data-end'));
    expect(newStart > String(start)).toBe(true);

    // The whole bar moved: as many days between them as before.
    const length = (a, b) => (new Date(b).getTime() - new Date(a).getTime()) / 86400000;
    expect(length(newStart, newEnd)).toBe(length(String(start), String(end)));
});

test('finished, and opened again', async ({ page }) => {
    await page.goto(`epics/${epic}`);
    const toggle = page.locator(`form[action$="/epics/${epic}/done"] button`);

    await Promise.all([page.waitForLoadState('load'), toggle.click()]);
    await expect(page.locator('.content__sub .badge--done')).toBeVisible();
    await expect(page.locator('a[href*="tickets/create"]')).toHaveCount(0);

    await Promise.all([page.waitForLoadState('load'), toggle.click()]);
    await expect(page.locator('.content__sub .badge--done')).toHaveCount(0);
});
