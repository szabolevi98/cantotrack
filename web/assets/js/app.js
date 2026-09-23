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

/*
 * Dragging cards on the board.
 *
 * The card moves in the page at once, and the server is told where it went:
 * which column, between which two cards, and — dropped into another swimlane
 * — whose it is now or which epic it belongs to. If the server refuses, the
 * page reloads and shows where the card really is.
 *
 * This is the mouse's way of doing what the select on every card does. That
 * select stays: it is how a keyboard, a phone and a page without scripts move
 * a ticket, and dragging is not something all of those can do.
 */
(function () {
    'use strict';

    var board = document.querySelector('[data-board]');
    if (!board) {
        return;
    }

    var token = document.querySelector('meta[name="csrf-token"]');
    var dragged = null;
    var origin = null;
    var placeholder = document.createElement('div');
    placeholder.className = 'ticket-card ticket-card--placeholder';

    board.addEventListener('dragstart', function (event) {
        var card = event.target.closest && event.target.closest('.ticket-card[data-ticket-id]');
        if (!card) {
            return;
        }

        dragged = card;
        origin = {column: card.parentNode, next: card.nextSibling};
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', card.dataset.ticketId);

        // The placeholder takes the card's height, so the column does not jump.
        placeholder.style.height = card.offsetHeight + 'px';

        window.requestAnimationFrame(function () {
            card.classList.add('is-dragging');
        });
    });

    board.addEventListener('dragover', function (event) {
        if (!dragged) {
            return;
        }

        var column = event.target.closest && event.target.closest('.board__column');
        if (!column) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        board.querySelectorAll('.board__column.is-target').forEach(function (other) {
            if (other !== column) {
                other.classList.remove('is-target');
            }
        });
        column.classList.add('is-target');

        var after = cardAfter(column, event.clientY);
        if (after) {
            column.insertBefore(placeholder, after);
        } else {
            column.insertBefore(placeholder, column.querySelector('.board__more'));
        }
    });

    board.addEventListener('drop', function (event) {
        if (!dragged || !placeholder.parentNode) {
            return;
        }

        event.preventDefault();

        var column = placeholder.parentNode;
        column.insertBefore(dragged, placeholder);
        finish();

        send(dragged, column);
    });

    board.addEventListener('dragend', function () {
        // Dropped somewhere that is not a column: back where it came from.
        if (dragged && dragged.classList.contains('is-dragging') && placeholder.parentNode) {
            origin.column.insertBefore(dragged, origin.next);
        }

        finish();
    });

    function finish() {
        if (placeholder.parentNode) {
            placeholder.parentNode.removeChild(placeholder);
        }

        board.querySelectorAll('.is-target').forEach(function (column) {
            column.classList.remove('is-target');
        });

        if (dragged) {
            dragged.classList.remove('is-dragging');
        }
    }

    /* The first card in the column whose middle is below the pointer. */
    function cardAfter(column, y) {
        var cards = column.querySelectorAll('.ticket-card[data-ticket-id]:not(.is-dragging)');

        for (var i = 0; i < cards.length; i++) {
            var box = cards[i].getBoundingClientRect();
            if (y < box.top + box.height / 2) {
                return cards[i];
            }
        }

        return null;
    }

    function neighbour(card, direction) {
        var sibling = direction < 0 ? card.previousElementSibling : card.nextElementSibling;

        while (sibling && !sibling.matches('.ticket-card[data-ticket-id]')) {
            sibling = direction < 0 ? sibling.previousElementSibling : sibling.nextElementSibling;
        }

        return sibling ? sibling.dataset.ticketId : '';
    }

    function send(card, column) {
        var fromColumn = origin.column;
        var row = column.closest('.board__row');
        var body = new URLSearchParams();

        body.set('status', column.dataset.statusId);
        body.set('above', neighbour(card, -1));
        body.set('below', neighbour(card, 1));

        // Across lanes, the lane's person or epic comes with the card.
        if (row && row.dataset.laneField && fromColumn.closest('.board__row') !== row) {
            body.set('lane_field', row.dataset.laneField);
            body.set('lane_value', row.dataset.laneValue || '');
        }

        // The select on the card follows, so a keyboard user after a drag
        // sees the column the card is in.
        var select = card.querySelector('select[name="status"]');
        if (select) {
            select.value = column.dataset.statusId;
        }

        if (fromColumn.dataset.statusId !== column.dataset.statusId) {
            recount(fromColumn.dataset.statusId, -1);
            recount(column.dataset.statusId, 1);
        }

        fetch(board.dataset.moveUrl.replace('{id}', card.dataset.ticketId), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-CSRF-Token': token ? token.content : ''}
        }).then(function (response) {
            return response.json().then(function (result) {
                if (!response.ok || !result.ok) {
                    window.alert(result.error || board.dataset.moveFailed);
                    window.location.reload();
                }
            });
        }).catch(function () {
            window.location.reload();
        });
    }

    /* The count in a column's head, and whether it is now over its limit. */
    function recount(statusId, change) {
        var head = board.querySelector('[data-head-for="' + statusId + '"]');
        if (!head) {
            return;
        }

        var count = head.querySelector('[data-count]');
        var limit = head.querySelector('[data-limit]');
        var value = Math.max(0, parseInt(count.textContent, 10) + change);
        count.textContent = value;

        if (limit) {
            head.classList.toggle('board__head--over', value > parseInt(limit.textContent, 10));
        }
    }
})();

/*
 * Keyboard shortcuts, and the ticking of rows on the ticket list.
 *
 * The shortcuts are single keys, and only when nothing is being typed into:
 * a "c" in a comment is a letter. "g" starts a two-key jump ("g t" for the
 * tickets), and "?" lists them all.
 */
(function () {
    'use strict';

    var base = document.querySelector('meta[name="base-url"]');
    var root = base ? base.content : '';
    var waitingForG = false;
    var timer = null;

    function typing(element) {
        return element && (element.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(element.tagName));
    }

    function go(path) {
        window.location.href = root + path;
    }

    document.addEventListener('keydown', function (event) {
        if (event.ctrlKey || event.metaKey || event.altKey || typing(event.target)) {
            return;
        }

        var project = document.body.dataset.projectId;
        var key = event.key;

        if (waitingForG) {
            waitingForG = false;
            window.clearTimeout(timer);

            var jumps = {d: '/', p: '/projects', t: '/tickets', s: '/timesheet'};
            if (key === 'b' && project) {
                event.preventDefault();
                go('/projects/' + project);
            } else if (jumps[key]) {
                event.preventDefault();
                go(jumps[key]);
            }

            return;
        }

        if (key === '/') {
            var search = document.querySelector('[data-shortcut-search]');
            if (search) {
                event.preventDefault();
                search.focus();
                search.select();
            }
        } else if (key === 'c') {
            event.preventDefault();
            go('/tickets/create' + (project ? '?project=' + project : ''));
        } else if (key === 'g') {
            waitingForG = true;
            timer = window.setTimeout(function () { waitingForG = false; }, 1200);
        } else if (key === '?') {
            event.preventDefault();
            help();
        }
    });

    function help() {
        var dialog = document.getElementById('shortcuts');
        if (dialog && typeof dialog.showModal === 'function') {
            dialog.showModal();
        }
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('[data-shortcut-help]')) {
            help();
        }
    });

    /* The ticket list's ticks: one box ticks the whole page, and the bar
       says how many are ticked — and stays quiet while none are. */
    document.querySelectorAll('[data-bulk]').forEach(function (form) {
        var all = form.querySelector('[data-bulk-all]');
        var bar = form.querySelector('[data-bulk-bar]');
        var count = form.querySelector('[data-bulk-count]');
        var boxes = form.querySelectorAll('input[name="ids[]"]');

        function update() {
            var ticked = form.querySelectorAll('input[name="ids[]"]:checked').length;

            if (bar) {
                bar.classList.toggle('is-idle', ticked === 0);
            }

            if (count) {
                count.textContent = ticked === 0 ? count.dataset.idle || count.textContent : ticked + ' ✓';
            }

            if (all) {
                all.checked = ticked > 0 && ticked === boxes.length;
                all.indeterminate = ticked > 0 && ticked < boxes.length;
            }
        }

        if (count) {
            count.dataset.idle = count.textContent;
        }

        if (all) {
            all.addEventListener('change', function () {
                boxes.forEach(function (box) { box.checked = all.checked; });
                update();
            });
        }

        boxes.forEach(function (box) { box.addEventListener('change', update); });
        update();
    });
})();

/*
 * The running clock, ticking: every [data-timer-started] shows the time since
 * it started in its [data-timer-clock]. The server draws the minutes once;
 * this only keeps them moving while the page is open.
 */
(function () {
    'use strict';

    var clocks = document.querySelectorAll('[data-timer-started]');
    if (clocks.length === 0) {
        return;
    }

    function two(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function tick() {
        var now = Math.floor(Date.now() / 1000);

        clocks.forEach(function (clock) {
            var seconds = Math.max(0, now - parseInt(clock.dataset.timerStarted, 10));
            var face = clock.querySelector('[data-timer-clock]');

            if (face) {
                face.textContent = Math.floor(seconds / 3600) + ':' + two(Math.floor(seconds / 60) % 60) + ':' + two(seconds % 60);
            }
        });
    }

    tick();
    window.setInterval(tick, 1000);
})();

/*
 * "Log time" from anywhere: the top bar's button, or "l", opens the dialog;
 * typing in its ticket field asks for suggestions, and picking one fills in
 * the key and the project's billing.
 *
 * Everything else about the dialog is a plain form: a key typed in and sent
 * is logged the same way, with or without this file.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('quick-log');
    var form = dialog && dialog.querySelector('[data-quick-log]');

    if (!form || typeof dialog.showModal !== 'function') {
        return;
    }

    var input = form.elements.ticket;
    var list = document.getElementById('quick-suggestions');
    var billable = form.querySelector('[data-billable]');
    var billableSent = form.querySelector('[data-billable-sent]');
    var hint = form.querySelector('[data-billable-hint]');
    var active = -1;
    var items = [];
    var pending = null;

    /*
     * Opens the dialog, optionally filled in — the calendar hands it a day,
     * a start and a length when a stretch of it is dragged out.
     */
    window.ctQuickLog = function (values) {
        values = values || {};
        Object.keys(values).forEach(function (name) {
            if (form.elements[name]) {
                form.elements[name].value = values[name];
            }
        });

        dialog.showModal();
        (values.ticket ? form.elements.time : input).focus();
        suggest();
    };

    document.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('[data-quick-log-open]')) {
            event.preventDefault();
            window.ctQuickLog();
        }

        if (event.target.closest && event.target.closest('[data-quick-log-close]')) {
            dialog.close();
        }
    });

    // "l" for log, when nothing is being typed into.
    document.addEventListener('keydown', function (event) {
        var target = event.target;
        var typing = target && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName));

        if (event.key === 'l' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey && !dialog.open) {
            event.preventDefault();
            window.ctQuickLog();
        }
    });

    input.addEventListener('input', function () {
        window.clearTimeout(pending);
        pending = window.setTimeout(suggest, 150);
    });

    input.addEventListener('keydown', function (event) {
        if (list.hidden || items.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            active = (active + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
            mark();
        } else if (event.key === 'Enter' && active >= 0) {
            event.preventDefault();
            pick(items[active]);
        } else if (event.key === 'Escape') {
            event.stopPropagation();
            close();
        }
    });

    list.addEventListener('mousedown', function (event) {
        var option = event.target.closest('[data-index]');
        if (option) {
            event.preventDefault();
            pick(items[Number(option.dataset.index)]);
        }
    });

    input.addEventListener('blur', function () {
        window.setTimeout(close, 100);
    });

    function suggest() {
        var url = form.dataset.suggestUrl + '?q=' + encodeURIComponent(input.value.trim());

        fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
            .then(function (response) { return response.ok ? response.json() : {tickets: []}; })
            .then(function (result) {
                items = result.tickets || [];
                active = -1;
                draw();
            })
            .catch(function () {});
    }

    function draw() {
        list.innerHTML = '';

        items.forEach(function (item, index) {
            var li = document.createElement('li');
            var key = document.createElement('strong');
            li.setAttribute('role', 'option');
            li.id = 'quick-suggestion-' + index;
            li.dataset.index = index;
            key.textContent = item.key;
            li.appendChild(key);
            li.appendChild(document.createTextNode(' ' + item.title));
            list.appendChild(li);
        });

        list.hidden = items.length === 0 || document.activeElement !== input;
        input.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
    }

    function mark() {
        Array.prototype.forEach.call(list.children, function (li, index) {
            li.classList.toggle('is-active', index === active);
        });
        input.setAttribute('aria-activedescendant', active >= 0 ? 'quick-suggestion-' + active : '');
    }

    function pick(item) {
        input.value = item.key;
        // The project's own billing, until somebody says otherwise.
        billable.checked = item.billable;
        billableSent.disabled = false;
        hint.hidden = true;
        close();
        form.elements.time.focus();
    }

    billable.addEventListener('change', function () {
        billableSent.disabled = false;
        hint.hidden = true;
    });

    function close() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
    }
})();

/*
 * The week as a calendar, on one's own week: drag over a day's empty hours
 * and "Log time" opens with that day, that start and that length filled in.
 * A click without a drag is an hour from where it was clicked. Everything
 * snaps to the quarter hour.
 */
(function () {
    'use strict';

    var calendar = document.querySelector('[data-calendar]');

    if (!calendar || typeof window.ctQuickLog !== 'function') {
        return;
    }

    var from = Number(calendar.dataset.from);
    var ppm = parseFloat(getComputedStyle(calendar).getPropertyValue('--ppm')) || 0.8;
    var drag = null;

    function minuteAt(day, clientY) {
        var offset = clientY - day.getBoundingClientRect().top;
        return from + Math.max(0, Math.round(offset / ppm / 15) * 15);
    }

    function clock(minutes) {
        return String(Math.floor(minutes / 60)).padStart(2, '0') + ':' + String(minutes % 60).padStart(2, '0');
    }

    function span(a, b) {
        var start = Math.min(a, b);
        var end = Math.max(a, b);
        return {start: start, end: end === start ? start + 60 : end};
    }

    calendar.addEventListener('pointerdown', function (event) {
        var day = event.target.closest('.calendar__day');

        if (!day || event.button !== 0 || day.hasAttribute('data-future') || event.target.closest('.calendar__block')) {
            return;
        }

        event.preventDefault();
        var start = minuteAt(day, event.clientY);
        var ghost = document.createElement('div');
        ghost.className = 'calendar__ghost';
        day.appendChild(ghost);
        drag = {day: day, start: start, end: start, ghost: ghost};
        draw();
        day.setPointerCapture(event.pointerId);
    });

    calendar.addEventListener('pointermove', function (event) {
        if (drag) {
            drag.end = minuteAt(drag.day, event.clientY);
            draw();
        }
    });

    calendar.addEventListener('pointerup', function () {
        if (!drag) {
            return;
        }

        var stretch = span(drag.start, drag.end);
        drag.ghost.remove();
        var date = drag.day.dataset.date;
        drag = null;

        window.ctQuickLog({
            work_date: date,
            started_at: clock(stretch.start),
            time: (stretch.end - stretch.start) + 'm'
        });
    });

    function draw() {
        var stretch = span(drag.start, drag.end);
        drag.ghost.style.top = ((stretch.start - from) * ppm) + 'px';
        drag.ghost.style.height = ((stretch.end - stretch.start) * ppm) + 'px';
        drag.ghost.textContent = clock(stretch.start) + '–' + clock(stretch.end);
    }
})();
