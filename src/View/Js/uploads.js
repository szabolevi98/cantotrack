/*
 * Files without the file picker: a screenshot pasted into a comment, or files
 * dropped onto a ticket's attachments.
 *
 * The form on the page does the same without any of this; these are the two
 * ways people actually attach things, and the reason a tracker without them
 * gets its screenshots through a chat instead.
 */
(function () {
    'use strict';

    var token = document.querySelector('meta[name="csrf-token"]');

    function upload(url, files) {
        var body = new FormData();
        Array.prototype.forEach.call(files, function (file, index) {
            // A pasted screenshot has no name of its own; it gets one that
            // says what it is and when.
            var name = file.name && file.name !== 'image.png' ? file.name : 'screenshot-' + stamp() + '-' + index + '.png';
            body.append('files[]', file, name);
        });

        return fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-CSRF-Token': token ? token.content : ''}
        }).then(function (response) {
            return response.json().catch(function () {
                return {files: [], errors: ['The upload did not work (' + response.status + ').']};
            });
        });
    }

    function stamp() {
        var d = new Date();
        function two(n) { return (n < 10 ? '0' : '') + n; }

        return d.getFullYear() + two(d.getMonth() + 1) + two(d.getDate()) + '-' + two(d.getHours()) + two(d.getMinutes()) + two(d.getSeconds());
    }

    /* A picture pasted into a comment is uploaded, and its Markdown takes
       the place of a placeholder at the cursor. */
    document.addEventListener('paste', function (event) {
        var field = event.target;
        if (!field.matches || !field.matches('textarea[data-paste-upload]') || !event.clipboardData) {
            return;
        }

        var files = Array.prototype.filter.call(event.clipboardData.files || [], function (file) {
            return file.type.indexOf('image/') === 0;
        });

        if (files.length === 0) {
            return;
        }

        event.preventDefault();

        var placeholder = '![Uploading…]()';
        insert(field, placeholder);

        upload(field.dataset.pasteUpload, files).then(function (result) {
            var markdown = (result.files || []).map(function (file) {
                return (file.image ? '!' : '') + '[' + file.name + '](' + file.url + ')';
            }).join('\n');

            field.value = field.value.replace(placeholder, markdown);

            if (result.errors && result.errors.length) {
                window.alert(result.errors.join('\n'));
            }
        });
    });

    function insert(field, text) {
        var start = field.selectionStart || 0;
        var end = field.selectionEnd || 0;
        field.value = field.value.slice(0, start) + text + field.value.slice(end);
        field.selectionStart = field.selectionEnd = start + text.length;
    }

    /* Files dropped onto the attachments card are uploaded, and the page
       comes back with them on it. */
    document.querySelectorAll('[data-drop-upload]').forEach(function (area) {
        ['dragenter', 'dragover'].forEach(function (name) {
            area.addEventListener(name, function (event) {
                if (event.dataTransfer && Array.prototype.indexOf.call(event.dataTransfer.types, 'Files') !== -1) {
                    event.preventDefault();
                    area.classList.add('is-dropping');
                }
            });
        });

        ['dragleave', 'drop'].forEach(function (name) {
            area.addEventListener(name, function () {
                area.classList.remove('is-dropping');
            });
        });

        area.addEventListener('drop', function (event) {
            if (!event.dataTransfer || event.dataTransfer.files.length === 0) {
                return;
            }

            event.preventDefault();
            area.classList.add('is-busy');

            upload(area.dataset.dropUpload, event.dataTransfer.files).then(function (result) {
                if (result.errors && result.errors.length) {
                    window.alert(result.errors.join('\n'));
                }

                window.location.hash = 'attachments';
                window.location.reload();
            });
        });
    });
})();
