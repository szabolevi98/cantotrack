// @ts-check
/*
 * Templates: one written on the project's templates page, a new ticket
 * started from it — filled in, and made with its subtasks — and the same
 * template made to repeat, with one made at once to see what it makes.
 */
const { test, expect, makeProject, removeProject } = require('./helpers');

let project;

test.beforeAll(async ({ request }) => {
    project = await makeProject(request, 'Templates');
});

test.afterAll(async ({ request }) => {
    await removeProject(request, project);
});

test('a ticket started from a template is filled in from it, and made with its subtasks', async ({ page }) => {
    await page.goto(`projects/${project.id}/templates`);

    const form = page.locator('#new-template form');
    await form.locator('input[name="name"]').fill('Release checklist');
    await form.locator('select[name="priority"]').selectOption('high');
    await form.locator('input[name="title"]').fill('Release {date}');
    await form.locator('textarea[name="description"]').fill('Everything a release goes through.');
    await form.locator('textarea[name="subtasks"]').fill('Write the notes\nRun the checks\nDeploy');
    await Promise.all([page.waitForLoadState('load'), form.locator('button[type="submit"]').click()]);
    await expect(page.locator('#templates')).toContainText('Release checklist');

    await page.goto(`tickets/create?project=${project.id}`);
    await Promise.all([page.waitForURL(/template=\d+/), page.locator('select[name="template"]').selectOption({ label: 'Release checklist' })]);

    const today = new Date();
    const date = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    await expect(page.locator('#title')).toHaveValue(`Release ${date}`);
    await expect(page.locator('select[name="priority"]')).toHaveValue('high');
    await expect(page.locator('form[method="post"][action$="/tickets/create"]')).toContainText('Write the notes · Run the checks · Deploy');

    await Promise.all([page.waitForURL(/\/tickets\/\d+$/), page.locator('form[method="post"][action$="/tickets/create"] button[type="submit"]').last().click()]);
    await expect(page.locator('h1')).toContainText(`Release ${date}`);
    await expect(page.locator('#subtasks .subtasks__item')).toHaveCount(3);
});

test('a template made to repeat, and one made at once', async ({ page }) => {
    await page.goto(`projects/${project.id}/templates`);

    const form = page.locator('#repeating form.repeat-form');
    await form.locator('select[name="frequency"]').selectOption('monthly');
    await form.locator('input[name="month_day"]').fill('1');
    await form.locator('input[name="due_days"]').fill('5');
    await Promise.all([page.waitForLoadState('load'), form.locator('button[type="submit"]').click()]);

    const row = page.locator('#repeating tbody tr').first();
    await expect(row).toContainText('Release checklist');
    const next = await row.locator('td').nth(3).innerText();

    await Promise.all([page.waitForURL(/\/tickets\/\d+$/), row.locator('form[action$="/now"] button').click()]);
    await expect(page.locator('h1')).toContainText('Release ');
    await expect(page.locator('#subtasks .subtasks__item')).toHaveCount(3);

    // Made by hand, its next day is where it was.
    await page.goto(`projects/${project.id}/templates`);
    await expect(page.locator('#repeating tbody tr').first().locator('td').nth(3)).toHaveText(next);
    await expect(page.locator('#repeating tbody tr').first().locator('td').nth(4)).toContainText(project.code + '-');
});
