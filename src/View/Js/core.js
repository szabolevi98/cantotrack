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
     * data-select-on-focus — a value to be copied (a new token) is selected
     * whole the moment it is clicked into.
     */
    document.addEventListener('focusin', function (event) {
        if (event.target.matches && event.target.matches('[data-select-on-focus]')) {
            event.target.select();
        }
    });

    /*
     * data-theme-switch — the light/dark switch in the sidebar. The page
     * knows which theme it is showing only here: with "the system's" chosen,
     * that is the browser's call. The switch is told, so it shows the right
     * icon and flips the right way; and the page flips at once, before the
     * choice is saved and the page comes back.
     */
    var themeSwitch = document.querySelector('[data-theme-switch]');

    if (themeSwitch) {
        var root = document.documentElement;
        var shown = function () {
            var chosen = root.getAttribute('data-theme');
            if (chosen === 'dark' || chosen === 'light') {
                return chosen;
            }
            return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        };
        var label = function () {
            var now = shown();
            var button = themeSwitch.querySelector('button');
            themeSwitch.setAttribute('data-shown', now);
            themeSwitch.elements.shown.value = now;
            button.title = now === 'dark' ? themeSwitch.dataset.toLight : themeSwitch.dataset.toDark;
            button.setAttribute('aria-label', button.title);
        };

        label();
        themeSwitch.addEventListener('submit', function () {
            root.setAttribute('data-theme', shown() === 'dark' ? 'light' : 'dark');
        });
    }

    /*
     * data-recaptcha — a form that asks reCAPTCHA for a token when it is
     * sent, and goes once it has one. The token is fetched at the last
     * moment because it is only good for two minutes. If Google's script did
     * not load (blocked, offline), the form goes without, and the server says
     * why it was refused.
     */
    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!form.dataset || !form.dataset.recaptcha || form.dataset.recaptchaDone || !window.grecaptcha) {
            return;
        }

        event.preventDefault();

        window.grecaptcha.ready(function () {
            window.grecaptcha.execute(form.dataset.recaptchaKey, {action: form.dataset.recaptcha}).then(function (token) {
                form.elements.recaptcha_token.value = token;
                form.dataset.recaptchaDone = '1';
                form.submit();
            });
        });
    });

    /*
     * data-menu-toggle — the sidebar's menu on a phone, folded away until
     * the button opens it.
     */
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest && event.target.closest('[data-menu-toggle]');
        if (!toggle) {
            return;
        }
        var sidebar = toggle.closest('.sidebar');
        var open = !sidebar.classList.contains('is-open');
        sidebar.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    /*
     * data-menu — a <details> dropdown (the account menu) that closes when
     * somebody clicks anywhere else, or presses Escape, the way a menu does.
     */
    document.addEventListener('click', function (event) {
        document.querySelectorAll('details[data-menu][open]').forEach(function (menu) {
            if (!menu.contains(event.target)) {
                menu.removeAttribute('open');
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('details[data-menu][open]').forEach(function (menu) {
            menu.removeAttribute('open');
            menu.querySelector('summary').focus();
        });
    });

    /*
     * data-week-date — the week picker's date field: a day picked from its
     * calendar is its week shown at once. A date typed in changes with every
     * digit (the day 2 on the way to 23, the year 0202 on the way to 2025),
     * so typing only brings up the button beside it, and Enter sends it —
     * the same as a select changed from the keyboard, below.
     */
    document.addEventListener('change', function (event) {
        var field = event.target;

        if (!field.matches || !field.matches('input[data-week-date]') || !field.value) {
            return;
        }

        if (keyboardInput) {
            field.form.classList.add('is-pending');
            return;
        }

        send(field);
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

        // The project's own fields: its are shown and sent, the others'
        // hidden and left out of the form.
        source.form.querySelectorAll('[data-field-project]').forEach(function (item) {
            var mine = item.dataset.fieldProject === source.value;
            item.hidden = !mine;
            item.querySelectorAll('input, select, textarea').forEach(function (control) {
                control.disabled = !mine;
            });
        });

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
