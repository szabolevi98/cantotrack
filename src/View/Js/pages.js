/*
 * The page form: a preview of the text as it will be drawn — Markdown, page
 * links and ticket lists — beside the plain text it is written in. Asked of
 * the server, so it is drawn exactly as the page will be.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-page-form]');

    if (!form) {
        return;
    }

    var text = form.querySelector('[data-page-text]');
    var preview = form.querySelector('[data-page-preview]');
    var token = document.querySelector('meta[name="csrf-token"]');

    form.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-page-tab]');

        if (!tab) {
            return;
        }

        form.querySelectorAll('[data-page-tab]').forEach(function (button) {
            button.classList.toggle('is-active', button === tab);
        });

        if (tab.dataset.pageTab === 'write') {
            preview.hidden = true;
            text.hidden = false;
            text.focus();
            return;
        }

        var body = new FormData();
        body.set('body', text.value);
        preview.innerHTML = '';
        preview.hidden = false;
        text.hidden = true;

        fetch(form.dataset.previewUrl, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-CSRF-Token': token ? token.content : ''}
        }).then(function (response) {
            return response.json();
        }).then(function (result) {
            preview.innerHTML = result.html || '';
        }).catch(function () {
            preview.textContent = '…';
        });
    });
})();
