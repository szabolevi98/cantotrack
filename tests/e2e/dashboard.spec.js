// @ts-check
/*
 * Customizing the dashboard: a piece dragged into the other column stays
 * there. Whatever the dashboard looked like before is put back afterwards —
 * it is the signed-in person's own.
 */
const { test, expect, csrf } = require('./helpers');

let before = null;

async function layoutOf(page) {
    return page.evaluate(() => {
        const layout = { main: [], side: [] };
        document.querySelectorAll('.dash__column').forEach((column) => {
            column.querySelectorAll('.piece[data-gadget-id]').forEach((piece) => {
                layout[column.dataset.area].push(Number(piece.dataset.gadgetId));
            });
        });
        return layout;
    });
}

test.afterAll(async ({ request }) => {
    if (before) {
        await request.post('dashboard/layout', {
            data: before,
            headers: { Accept: 'application/json', 'X-CSRF-Token': await csrf(request) },
        });
    }
});

test('a piece dragged into the other column is saved there', async ({ page }) => {
    await page.goto('?edit=1');
    await expect(page.locator('[data-dashboard][data-arrange-url]')).toBeVisible();
    before = await layoutOf(page);
    expect(before.main.length, 'the dashboard has pieces to drag').toBeGreaterThan(0);

    const moved = before.main[0];
    const saved = page.waitForResponse((r) => r.url().includes('/dashboard/layout') && r.request().method() === 'POST');
    await page.locator(`.piece[data-gadget-id="${moved}"]`).dragTo(page.locator('.dash__column[data-area="side"]'), { targetPosition: { x: 40, y: 10 } });
    expect((await saved).ok()).toBeTruthy();

    await expect(page.locator(`.dash__column[data-area="side"] .piece[data-gadget-id="${moved}"]`)).toBeVisible();

    await page.reload();
    const after = await layoutOf(page);
    expect(after.side[0]).toBe(moved);
    expect(after.main).not.toContain(moved);
});

test('outside the customizing mode nothing is draggable', async ({ page }) => {
    await page.goto('');
    await expect(page.locator('.piece[draggable="true"]')).toHaveCount(0);
});
