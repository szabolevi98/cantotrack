// @ts-check
/*
 * What the browser tests share: a test that fails on any script error on the
 * page, and the work they need made before they start — a project of their
 * own, its tickets and epics — made over HTTP, the way the forms send it,
 * and removed again afterwards.
 *
 * Addresses are relative ("projects/4", not "/projects/4"), so that an
 * application living under a path (http://localhost/cantotrack/web/) is
 * reached under it.
 */
const base = require('@playwright/test');

/** The same test, but a script error on any page it opened fails it. */
const test = base.test.extend({
    page: async ({ page }, use) => {
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('dialog', (dialog) => dialog.accept());
        await use(page);
        base.expect(errors, 'script errors on the page').toEqual([]);
    },
});

/** @param {import('@playwright/test').APIRequestContext} request */
async function csrf(request) {
    const html = await (await request.get('profile')).text();
    const match = html.match(/<meta name="csrf-token" content="([^"]+)"/);
    if (!match) {
        throw new Error('No CSRF token on the profile page — is the session signed in?');
    }
    return match[1];
}

/**
 * A form sent the way the page sends it, with the session's token.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {string} path
 * @param {Record<string, string | number>} form
 */
async function post(request, path, form = {}) {
    return request.post(path, { form: { _token: await csrf(request), ...form }, maxRedirects: 0 });
}

/** The id in the address a create redirected to. */
function idFrom(response, kind) {
    const location = response.headers()['location'] || '';
    const match = location.match(new RegExp('/' + kind + '/(\\d+)'));
    if (!match) {
        throw new Error(`Expected a redirect to /${kind}/…, got ${response.status()} ${location}`);
    }
    return Number(match[1]);
}

/** A project of the test's own, with the default columns. */
async function makeProject(request, name = 'Browser test') {
    const code = 'E' + Math.floor(1000 + Math.random() * 9000);
    const response = await post(request, 'projects/create', { code, name: `${name} ${code}`, description: 'Made by the browser tests.' });
    return { id: idFrom(response, 'projects'), code };
}

/** A ticket in it; returns its id and its key. */
async function makeTicket(request, project, title, extra = {}) {
    const response = await post(request, 'tickets/create', { project_id: project.id, title, status: 'todo', priority: 'normal', ...extra });
    const id = idFrom(response, 'tickets');
    const page = await (await request.get(`tickets/${id}`)).text();
    const key = (page.match(new RegExp(`\\b(${project.code}-\\d+)\\b`)) || [])[1];
    return { id, key, title };
}

async function makeEpic(request, project, title, extra = {}) {
    const response = await post(request, `projects/${project.id}/epics/create`, { title, ...extra });
    return idFrom(response, 'epics');
}

/** The project and everything in it. It has to have no hours left in it. */
async function removeProject(request, project) {
    if (project) {
        await post(request, `projects/${project.id}/delete`);
    }
}

/** A day as the forms write it, some days from today. */
function day(offset = 0) {
    const date = new Date();
    date.setDate(date.getDate() + offset);
    return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
}

/** A one-pixel PNG, for pasting and dropping. */
const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

module.exports = { test, expect: base.expect, csrf, post, makeProject, makeTicket, makeEpic, removeProject, day, PIXEL };
