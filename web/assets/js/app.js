/* Built by bin/build_assets.php — do not edit.
 * Edit the sources under src/View/Js/ and run the script again.
 */

/*
 * The small things every page does with JavaScript — and only the small things.
 *
 * Every page works without this file: a confirmation is a nicety in front of a
 * post that would happen anyway, and a select that submits itself has a button
 * next to it for when there is no script. Nothing here is the only way to do
 * something.
 *
 * There are no inline handlers anywhere in the markup (no onclick, no
 * onsubmit). That is what lets the Content-Security-Policy say
 * `script-src 'self'`: a page that runs only its own files cannot be made to
 * run somebody's pasted <script> or an event attribute smuggled into a name.
 * The behaviour is attached here, by attribute, instead.
 */
(function () {
    'use strict';

    document.documentElement.classList.add('js');

    /*
     * data-confirm — asks before a form is sent.
     *
     * The message lives in an HTML attribute and is read back through the DOM,
     * so a name with an apostrophe in it is just a name. It used to be written
     * straight into an inline `confirm('…')`, where an apostrophe ended the
     * string and whatever followed ran as code.
     */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = (event.submitter && event.submitter.dataset.confirm) || form.dataset.confirm;

        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    /*
     * data-autosubmit — a select that sends its form when it changes.
     *
     * With a mouse or a finger that is immediate: somebody opened the list and
     * picked something. With a keyboard it is not, because on Windows a closed
     * select changes its value on every arrow key — and "submit on change" then
     * moved a ticket one column for each press while somebody was only looking
     * at the options. So a keyboard change waits: Enter sends it, and the
     * form's own button (hidden until then) appears beside the select.
     */
    var keyboardInput = false;

    document.addEventListener('keydown', function (event) {
        keyboardInput = true;

        var select = event.target;
        if (event.key === 'Enter' && select.matches && select.matches('select[data-autosubmit]')) {
            event.preventDefault();
            send(select);
        }
    }, true);

    document.addEventListener('pointerdown', function () {
        keyboardInput = false;
    }, true);

    document.addEventListener('change', function (event) {
        var select = event.target;
        if (!select.matches || !select.matches('select[data-autosubmit]')) {
            return;
        }

        if (keyboardInput) {
            select.form.classList.add('is-pending');
            return;
        }

        send(select);
    });

    function send(select) {
        var form = select.form;
        form.classList.remove('is-pending');

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    /*
     * data-options-url — a select whose choice decides what another select
     * offers. On the ticket form the project decides which epics (and which
     * columns) there are. Fetched rather than reloaded, so whatever has been
     * typed into the title and the description stays where it is.
     */
    document.addEventListener('change', function (event) {
        var source = event.target;
        if (!source.matches || !source.matches('select[data-options-url]')) {
            return;
        }

        var url = source.dataset.optionsUrl.replace('{id}', encodeURIComponent(source.value));
        if (!source.value) {
            return;
        }

        fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (options) {
                if (!options) {
                    return;
                }

                Object.keys(options).forEach(function (name) {
                    var target = source.form.querySelector('select[data-options="' + name + '"]');
                    if (target) {
                        fill(target, options[name]);
                    }
                });
            });
    });

    function fill(select, items) {
        var keep = select.querySelector('option[value=""]');
        select.innerHTML = '';

        if (keep) {
            select.appendChild(keep);
        }

        items.forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.value;
            option.textContent = item.label;
            if (item.selected) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    }
})();
