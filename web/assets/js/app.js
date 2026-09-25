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
     * data-print — the browser's print dialog, where a page is also saved
     * as a PDF. The page's print stylesheet leaves only the document.
     */
    document.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('[data-print]')) {
            window.print();
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

        // The columns the one it is in does not let it go to, greyed out
        // and not taking it.
        var moves = card.parentNode.dataset.moves;
        if (moves !== undefined) {
            var allowed = moves === '' ? [] : moves.split(',');
            allowed.push(card.parentNode.dataset.statusId);
            board.querySelectorAll('.board__column').forEach(function (column) {
                column.classList.toggle('is-closed', allowed.indexOf(column.dataset.statusId) === -1);
            });
        }

        window.requestAnimationFrame(function () {
            card.classList.add('is-dragging');
        });
    });

    board.addEventListener('dragover', function (event) {
        if (!dragged) {
            return;
        }

        var column = event.target.closest && event.target.closest('.board__column');
        if (!column || column.classList.contains('is-closed')) {
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

        board.querySelectorAll('.is-target, .is-closed').forEach(function (column) {
            column.classList.remove('is-target', 'is-closed');
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

        // A shared board's column holds several projects' statuses; the
        // server works out which one of the card's own project it means.
        if (board.dataset.boardId) {
            body.set('board', board.dataset.boardId);
            body.set('column', column.dataset.statusId);
        } else {
            body.set('status', column.dataset.statusId);
        }
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
        if (select && !board.dataset.boardId) {
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
                } else if (select && result.status) {
                    select.value = String(result.status);
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

            var jumps = {d: '/', p: '/projects', t: '/tickets', s: '/timesheet', r: '/roadmap', q: '/tickets?mode=query'};
            var inProject = {b: '', k: '/backlog', v: '/releases', w: '/pages'};
            if (inProject[key] !== undefined && project) {
                event.preventDefault();
                go('/projects/' + project + inProject[key]);
            } else if (key === 'r' && project) {
                event.preventDefault();
                go('/projects/' + project + '/roadmap');
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
    var title = document.getElementById('quick-log-title');
    var submit = form.querySelector('[data-quick-log-submit]');
    var remove = form.querySelector('[data-quick-log-delete]');
    var ticketLink = form.querySelector('[data-quick-log-ticket]');
    var remaining = form.querySelector('[data-quick-log-remaining]');
    var editing = false;
    var active = -1;
    var items = [];
    var pending = null;

    /*
     * Opens the dialog, optionally filled in — the calendar hands it a day,
     * a start and a length when a stretch of it is dragged out.
     */
    window.ctQuickLog = function (values) {
        values = values || {};

        if (editing) {
            mode(false);
            form.reset();
        }

        fill(values);
        dialog.showModal();
        (values.ticket ? form.elements.time : input).focus();
        suggest();
    };

    /*
     * The same dialog on an entry that is already there: the calendar hands
     * it the entry's id, its ticket and what it holds.
     */
    window.ctQuickLog.edit = function (entry) {
        form.reset();
        mode(true);
        form.action = form.dataset.entryUrl.replace('{id}', entry.id);
        remove.formAction = form.action + '/delete';
        ticketLink.href = entry.href;

        fill({
            ticket: entry.key,
            time: entry.time,
            work_date: entry.work_date,
            started_at: entry.started_at,
            note: entry.note
        });

        // The entry's work type — unless it is one no longer offered, when the
        // field is left out of the form and the entry keeps it.
        var type = form.elements.work_type;
        if (type) {
            type.value = entry.work_type || '';
            type.disabled = type.value !== (entry.work_type || '');
        }

        billable.checked = entry.billable;
        billableSent.disabled = false;
        hint.hidden = true;

        dialog.showModal();
        form.elements.time.focus();
    };

    function fill(values) {
        Object.keys(values).forEach(function (name) {
            if (form.elements[name]) {
                form.elements[name].value = values[name];
            }
        });
    }

    function mode(change) {
        editing = change;
        title.textContent = change ? title.dataset.change : title.dataset.create;
        submit.textContent = change ? submit.dataset.change : submit.dataset.create;
        input.readOnly = change;
        remaining.hidden = change;
        form.elements.remaining.disabled = change;
        remove.hidden = !change;
        ticketLink.hidden = !change;

        if (!change) {
            form.action = form.dataset.logUrl;
            // Back to the project's own billing, until somebody picks.
            billableSent.disabled = true;
            hint.hidden = false;
            if (form.elements.work_type) {
                form.elements.work_type.disabled = false;
            }
        }
    }

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
        if (editing) {
            return;
        }

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
        if (editing) {
            return;
        }

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
 * The week as a calendar, on one's own week.
 *
 * - Drag over a day's empty hours and "Log time" opens with that day, that
 *   start and that length filled in; a click without a drag is an hour from
 *   where it was clicked.
 * - Drag an entry to another hour or another day; drag its bottom edge to
 *   make it longer or shorter; drag one without a start from above the hours
 *   onto them. Each is saved the moment it is dropped, and the calendar is
 *   drawn again from the server.
 * - A click on an entry opens it for changing; a click with Ctrl or the
 *   middle button still opens its ticket, the link it is underneath.
 *
 * Everything snaps to the quarter hour. A closed day — handed in, approved,
 * or before the lock date — takes none of it, and neither does a day that
 * has not happened yet.
 */
(function () {
    'use strict';

    var calendar = document.querySelector('[data-calendar]');

    if (!calendar || typeof window.ctQuickLog !== 'function') {
        return;
    }

    var token = document.querySelector('meta[name="csrf-token"]');
    var ppm = parseFloat(getComputedStyle(calendar).getPropertyValue('--ppm')) || 0.8;
    var from = 0;
    var to = 0;
    var drag = null;   // a new stretch being dragged out
    var hold = null;   // an entry being moved or stretched
    var dragged = false;

    function bounds() {
        from = Number(calendar.dataset.from);
        to = Number(calendar.dataset.to) || 24 * 60;
    }

    bounds();

    function snap(minutes) {
        return Math.round(minutes / 15) * 15;
    }

    function rawMinuteAt(day, clientY) {
        return from + (clientY - day.getBoundingClientRect().top) / ppm;
    }

    function minuteAt(day, clientY) {
        return Math.max(from, snap(rawMinuteAt(day, clientY)));
    }

    function clock(minutes) {
        return String(Math.floor(minutes / 60)).padStart(2, '0') + ':' + String(minutes % 60).padStart(2, '0');
    }

    function minutesOf(value) {
        var parts = (value || '').split(':');
        return parts.length === 2 ? Number(parts[0]) * 60 + Number(parts[1]) : null;
    }

    function duration(minutes) {
        var h = Math.floor(minutes / 60);
        var m = minutes % 60;
        return (h ? h + 'h' : '') + (h && m ? ' ' : '') + (m || !h ? m + 'm' : '');
    }

    function span(a, b) {
        var start = Math.min(a, b);
        var end = Math.max(a, b);
        return {start: start, end: end === start ? start + 60 : end};
    }

    function open(day) {
        return day && !day.hasAttribute('data-future') && !day.hasAttribute('data-closed');
    }

    function dayFor(date) {
        return calendar.querySelector('.calendar__day[data-date="' + date + '"]');
    }

    // The day column under the pointer, going by its left and right edges
    // only, so a pointer dragged above or below the hours still has one.
    function dayAtX(clientX) {
        var days = calendar.querySelectorAll('.calendar__day');

        for (var i = 0; i < days.length; i++) {
            var box = days[i].getBoundingClientRect();
            if (clientX >= box.left && clientX < box.right) {
                return days[i];
            }
        }

        return null;
    }

    function ghostIn(day) {
        var ghost = document.createElement('div');
        ghost.className = 'calendar__ghost';
        day.appendChild(ghost);
        return ghost;
    }

    function place(ghost, start, end) {
        ghost.style.top = ((start - from) * ppm) + 'px';
        ghost.style.height = ((end - start) * ppm) + 'px';
        ghost.textContent = clock(start) + '–' + clock(end) + ' · ' + duration(end - start);
    }

    // A meeting from one's own calendar, logged with what it was; an entry,
    // opened for changing. The native click is what gets here, so a drag that
    // ended on the entry is told apart by the flag the drag leaves.
    calendar.addEventListener('click', function (event) {
        var meeting = event.target.closest('[data-meeting]');
        var entry = event.target.closest('[data-entry]');

        if (meeting && !meeting.disabled) {
            window.ctQuickLog({
                work_date: meeting.dataset.date,
                started_at: meeting.dataset.start,
                time: meeting.dataset.minutes + 'm',
                note: meeting.dataset.summary
            });
            return;
        }

        // On a closed day, or with Ctrl, it is the link to the ticket.
        if (!entry || event.ctrlKey || event.metaKey || event.shiftKey || !open(dayFor(entry.dataset.date))) {
            return;
        }

        event.preventDefault();

        if (dragged || typeof window.ctQuickLog.edit !== 'function') {
            return;
        }

        window.ctQuickLog.edit({
            id: entry.dataset.entry,
            key: entry.dataset.key,
            href: entry.getAttribute('href'),
            work_date: entry.dataset.date,
            started_at: entry.dataset.start,
            time: duration(Number(entry.dataset.minutes)),
            note: entry.dataset.note,
            work_type: entry.dataset.workType,
            billable: entry.dataset.billable === '1'
        });
    });

    // Links are not to be dragged off as links here.
    calendar.addEventListener('dragstart', function (event) {
        event.preventDefault();
    });

    calendar.addEventListener('pointerdown', function (event) {
        if (event.button !== 0) {
            return;
        }

        var entry = event.target.closest('[data-entry]');

        if (entry) {
            grab(entry, event);
            return;
        }

        var day = event.target.closest('.calendar__day');

        if (!open(day) || event.target.closest('[data-meeting]')) {
            return;
        }

        event.preventDefault();
        var start = minuteAt(day, event.clientY);
        drag = {day: day, start: start, end: start, ghost: ghostIn(day)};
        drawNew();
        calendar.setPointerCapture(event.pointerId);
    });

    /*
     * An entry taken hold of. Nothing moves until the pointer has gone a few
     * pixels, so a click is still a click.
     */
    function grab(entry, event) {
        var date = entry.dataset.date;

        if (!open(dayFor(date))) {
            return;
        }

        var chip = !entry.classList.contains('calendar__block');
        var start = minutesOf(entry.dataset.start);

        hold = {
            entry: entry,
            mode: event.target.closest('.calendar__resize') ? 'resize' : (chip ? 'chip' : 'move'),
            x: event.clientX,
            y: event.clientY,
            date: date,
            start: start,
            minutes: Number(entry.dataset.minutes),
            // Where in the block it was taken: the block keeps that spot
            // under the pointer as it moves.
            offset: chip || start === null ? 0 : rawMinuteAt(dayFor(date), event.clientY) - start,
            moving: false,
            pointer: event.pointerId
        };

        if (hold.mode === 'resize') {
            event.preventDefault();
        }
    }

    calendar.addEventListener('pointermove', function (event) {
        if (drag) {
            drag.end = minuteAt(drag.day, event.clientY);
            drawNew();
            return;
        }

        if (!hold) {
            return;
        }

        if (!hold.moving) {
            if (Math.abs(event.clientX - hold.x) + Math.abs(event.clientY - hold.y) < 5) {
                return;
            }

            hold.moving = true;
            hold.entry.classList.add('is-dragging');
            calendar.classList.add('calendar--dragging');
            calendar.setPointerCapture(hold.pointer);
        }

        event.preventDefault();
        var minutes = hold.minutes;
        var day = hold.day || dayFor(hold.date);
        var start = hold.start;

        if (hold.mode === 'resize') {
            var end = Math.min(to, Math.max(start + 15, snap(rawMinuteAt(day, event.clientY))));
            minutes = end - start;
        } else {
            var under = dayAtX(event.clientX);
            // A closed or future day is passed over: the entry stays where it
            // last could be.
            if (open(under)) {
                day = under;
            }
            start = snap(rawMinuteAt(day, event.clientY) - hold.offset);
            start = Math.max(from, Math.min(to - minutes, start));
        }

        if (!hold.ghost || hold.day !== day) {
            if (hold.ghost) {
                hold.ghost.remove();
            }
            hold.ghost = ghostIn(day);
            hold.day = day;
        }

        hold.to = {date: day.dataset.date, start: start, minutes: minutes};
        place(hold.ghost, start, start + minutes);
    });

    calendar.addEventListener('pointerup', function () {
        if (drag) {
            var stretch = span(drag.start, drag.end);
            var date = drag.day.dataset.date;
            drag.ghost.remove();
            drag = null;

            window.ctQuickLog({
                work_date: date,
                started_at: clock(stretch.start),
                time: (stretch.end - stretch.start) + 'm'
            });
            return;
        }

        if (!hold) {
            return;
        }

        var was = hold;
        hold = null;

        if (!was.moving) {
            return;
        }

        // The click that follows the drop is not a click on the entry.
        dragged = true;
        window.setTimeout(function () { dragged = false; }, 0);
        calendar.classList.remove('calendar--dragging');

        var goal = was.to;

        if (!goal || (goal.date === was.date && goal.start === was.start && goal.minutes === was.minutes)) {
            cancel(was);
            return;
        }

        was.ghost.classList.add('is-saving');
        save(was.entry.dataset.entry, goal);
    });

    calendar.addEventListener('pointercancel', function () {
        if (drag) {
            drag.ghost.remove();
            drag = null;
        }
        if (hold) {
            cancel(hold);
            hold = null;
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && hold && hold.moving) {
            cancel(hold);
            hold = null;
            dragged = true;
            window.setTimeout(function () { dragged = false; }, 0);
        }
    });

    function cancel(was) {
        if (was.ghost) {
            was.ghost.remove();
        }
        was.entry.classList.remove('is-dragging');
        calendar.classList.remove('calendar--dragging');
    }

    function drawNew() {
        var stretch = span(drag.start, drag.end);
        place(drag.ghost, stretch.start, stretch.end);
    }

    function save(id, goal) {
        var body = new FormData();
        body.set('work_date', goal.date);
        body.set('started_at', clock(goal.start));
        body.set('time', goal.minutes + 'm');

        fetch(calendar.dataset.placeUrl.replace('{id}', id), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-CSRF-Token': token ? token.content : ''}
        }).then(function (response) {
            return response.json().then(function (result) {
                if (!response.ok || !result.ok) {
                    window.alert(result.error || calendar.dataset.failed);
                }
            }, function () {
                window.alert(calendar.dataset.failed);
            });
        }).catch(function () {
            window.alert(calendar.dataset.failed);
        }).then(refresh);
    }

    /*
     * The week drawn again from the server — its lanes, its totals and the
     * figures above it — without leaving the page or losing the scroll.
     */
    function refresh() {
        fetch(window.location.href, {credentials: 'same-origin', headers: {'Accept': 'text/html'}})
            .then(function (response) { return response.text(); })
            .then(function (html) {
                var page = new DOMParser().parseFromString(html, 'text/html');
                var fresh = page.querySelector('[data-calendar]');

                if (!fresh) {
                    window.location.reload();
                    return;
                }

                calendar.innerHTML = fresh.innerHTML;
                calendar.setAttribute('style', fresh.getAttribute('style') || '');
                calendar.dataset.from = fresh.dataset.from;
                calendar.dataset.to = fresh.dataset.to;
                bounds();

                var stats = document.querySelector('[data-week-stats]');
                var freshStats = page.querySelector('[data-week-stats]');
                if (stats && freshStats) {
                    stats.innerHTML = freshStats.innerHTML;
                }
            })
            .catch(function () {
                window.location.reload();
            });
    }
})();

/*
 * The roadmap's epics, moved with the pointer: drag a bar to move the whole
 * epic, drag one of its ends to start or finish it on another day. Each is
 * saved when it is dropped, and the page is drawn again.
 *
 * Everything snaps to whole days. A bar drawn from its tickets gets real
 * days the first time it is moved — from then on it is the team's plan.
 */
(function () {
    'use strict';

    var roadmap = document.querySelector('[data-roadmap]');

    if (!roadmap) {
        return;
    }

    var token = document.querySelector('meta[name="csrf-token"]');
    var from = new Date(roadmap.dataset.from + 'T00:00:00');
    var days = Number(roadmap.dataset.days);
    var hold = null;
    var dragged = false;

    function ymd(date) {
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
    }

    function addDays(value, count) {
        var date = new Date(value + 'T00:00:00');
        date.setDate(date.getDate() + count);
        return ymd(date);
    }

    function offset(value) {
        return Math.round((new Date(value + 'T00:00:00') - from) / 86400000);
    }

    roadmap.addEventListener('pointerdown', function (event) {
        var bar = event.target.closest('[data-epic]');

        if (!bar || event.button !== 0) {
            return;
        }

        var edge = event.target.closest('[data-edge]');
        hold = {
            bar: bar,
            mode: edge ? edge.dataset.edge : 'move',
            x: event.clientX,
            width: bar.parentElement.getBoundingClientRect().width,
            start: bar.dataset.start,
            end: bar.dataset.end,
            moving: false,
            pointer: event.pointerId
        };
    });

    roadmap.addEventListener('pointermove', function (event) {
        if (!hold) {
            return;
        }

        var shift = Math.round((event.clientX - hold.x) / hold.width * days);

        if (!hold.moving) {
            if (Math.abs(event.clientX - hold.x) < 4) {
                return;
            }
            hold.moving = true;
            hold.bar.classList.add('is-dragging');
            roadmap.setPointerCapture(hold.pointer);
        }

        var start = hold.start;
        var end = hold.end;

        if (hold.mode === 'move' || hold.mode === 'start') {
            start = addDays(hold.start, shift);
        }
        if (hold.mode === 'move' || hold.mode === 'end') {
            end = addDays(hold.end, shift);
        }
        if (end < start) {
            if (hold.mode === 'start') {
                start = end;
            } else {
                end = start;
            }
        }

        hold.to = {start: start, end: end};
        var left = Math.max(0, offset(start));
        var right = Math.min(days, offset(end) + 1);
        hold.bar.style.left = (left / days * 100) + '%';
        hold.bar.style.width = (Math.max(0.4, (right - left) / days * 100)) + '%';
        hold.bar.title = start + ' — ' + end;
    });

    roadmap.addEventListener('pointerup', function () {
        if (!hold) {
            return;
        }

        var was = hold;
        hold = null;

        if (!was.moving || !was.to || (was.to.start === was.start && was.to.end === was.end)) {
            was.bar.classList.remove('is-dragging');
            return;
        }

        dragged = true;
        window.setTimeout(function () { dragged = false; }, 0);

        var body = new FormData();
        body.set('starts_on', was.to.start);
        body.set('ends_on', was.to.end);

        fetch(roadmap.dataset.daysUrl.replace('{id}', was.bar.dataset.epic), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-CSRF-Token': token ? token.content : ''}
        }).then(function (response) {
            return response.json().then(function (result) {
                if (!response.ok || !result.ok) {
                    window.alert(result.error || roadmap.dataset.failed);
                }
            });
        }).catch(function () {
            window.alert(roadmap.dataset.failed);
        }).then(function () {
            window.location.reload();
        });
    });

    // The click that ends a drag does not open the epic.
    roadmap.addEventListener('click', function (event) {
        if (dragged && event.target.closest('[data-epic]')) {
            event.preventDefault();
        }
    });

    roadmap.addEventListener('dragstart', function (event) {
        event.preventDefault();
    });
})();

/*
 * The query box: suggestions for what comes next where the cursor is — a
 * field at the start or after AND and OR, an operator after a field, the
 * field's own values after an operator, and AND, OR or ORDER BY after a
 * value. The values come from the server once per field: the projects,
 * people, sprints and releases the person may see.
 *
 * Arrows move through them, Tab or Enter takes one, Escape closes them;
 * Enter with nothing open sends the query. Without this file the box is a
 * plain field, and the query works the same.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-query-form]');

    if (!form) {
        return;
    }

    var input = form.querySelector('input[name="query"]');
    var list = document.getElementById('query-suggestions');
    var fields = JSON.parse(form.dataset.fields || '[]');
    var operators = ['=', '!=', '~', '!~', '<', '<=', '>', '>=', 'IN (', 'NOT IN (', 'IS EMPTY', 'IS NOT EMPTY'];
    var joiners = ['AND', 'OR', 'ORDER BY'];
    var cache = {};
    var items = [];
    var active = -1;
    var partial = {start: 0, end: 0};

    // The words before the cursor, as the query language reads them.
    function tokens(text) {
        var found = [];
        var pattern = /"(?:[^"\\]|\\.)*"?|'(?:[^'\\]|\\.)*'?|!=|<=|>=|!~|[=<>~(),]|[^\s()=<>~!,"']+/g;
        var match;
        while ((match = pattern.exec(text)) !== null) {
            found.push(match[0]);
        }
        return found;
    }

    function isField(word) {
        if (!word) {
            return false;
        }
        var wanted = word.toLowerCase();
        return fields.some(function (field) { return field.toLowerCase() === wanted; });
    }

    function upper(word) {
        return (word || '').toUpperCase();
    }

    function context() {
        var caret = input.selectionStart || 0;
        var before = input.value.slice(0, caret);
        var word = /[^\s()=<>~!,]*$/.exec(before)[0];
        var done = tokens(before.slice(0, before.length - word.length));
        partial = {start: caret - word.length, end: caret};

        var last = done[done.length - 1];
        var lastUp = upper(last);

        if (!last || lastUp === 'AND' || lastUp === 'OR' || lastUp === 'NOT' || last === '(' && !inList(done)) {
            return {kind: 'fields', word: word};
        }
        if (lastUp === 'BY' || (last === ',' && orderBy(done))) {
            return {kind: 'order', word: word};
        }
        if (isField(last)) {
            return {kind: 'operators', word: word};
        }
        if (['=', '!=', '<', '<=', '>', '>=', '~', '!~'].indexOf(last) !== -1 || (last === '(' || last === ',') && inList(done)) {
            return {kind: 'values', field: fieldOf(done), word: word};
        }
        return {kind: 'joiners', word: word};
    }

    function inList(done) {
        for (var i = done.length - 1; i >= 0; i--) {
            if (done[i] === ')') {
                return false;
            }
            if (upper(done[i]) === 'IN') {
                return true;
            }
        }
        return false;
    }

    function orderBy(done) {
        return done.map(upper).join(' ').indexOf('ORDER BY') !== -1;
    }

    function fieldOf(done) {
        for (var i = done.length - 1; i >= 0; i--) {
            if (isField(done[i])) {
                return done[i].toLowerCase();
            }
        }
        return '';
    }

    function values(field) {
        if (cache[field]) {
            return Promise.resolve(cache[field]);
        }
        return fetch(form.dataset.valuesUrl + '?field=' + encodeURIComponent(field), {credentials: 'same-origin', headers: {Accept: 'application/json'}})
            .then(function (response) { return response.ok ? response.json() : {values: []}; })
            .then(function (result) {
                cache[field] = (result.values || []).map(function (value) {
                    return /^[\w.@()+-]+$/u.test(value) ? value : '"' + value.replace(/"/g, '\\"') + '"';
                });
                return cache[field];
            })
            .catch(function () { return []; });
    }

    function suggest() {
        var at = context();
        var source;

        if (at.kind === 'fields' || at.kind === 'order') {
            source = Promise.resolve(fields);
        } else if (at.kind === 'operators') {
            source = Promise.resolve(operators);
        } else if (at.kind === 'values') {
            source = values(at.field);
        } else {
            source = Promise.resolve(joiners);
        }

        source.then(function (options) {
            var word = at.word.toLowerCase().replace(/^["']/, '');
            items = options.filter(function (option) {
                var plain = option.toLowerCase().replace(/^"/, '');
                return word === '' || plain.indexOf(word) === 0 || (word.length > 1 && plain.indexOf(word) !== -1);
            }).slice(0, 12);
            active = items.length > 0 && word !== '' ? 0 : -1;
            draw();
        });
    }

    function draw() {
        list.innerHTML = '';
        items.forEach(function (item, index) {
            var li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.id = 'query-suggestion-' + index;
            li.dataset.index = index;
            li.textContent = item;
            li.classList.toggle('is-active', index === active);
            list.appendChild(li);
        });
        list.hidden = items.length === 0 || document.activeElement !== input;
        input.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
        input.setAttribute('aria-activedescendant', active >= 0 ? 'query-suggestion-' + active : '');
    }

    function take(item) {
        var value = input.value;
        var after = value.slice(partial.end).replace(/^\S*/, '');
        var text = item + (/\($/.test(item) ? '' : ' ');
        input.value = value.slice(0, partial.start) + text + after.replace(/^\s+/, '');
        var caret = partial.start + text.length;
        input.setSelectionRange(caret, caret);
        input.focus();
        suggest();
    }

    function close() {
        items = [];
        active = -1;
        draw();
    }

    // Offered while typing, or on a click; focus alone opens them only in
    // an empty box — landing on a list of results is not asking for them.
    input.addEventListener('input', suggest);
    input.addEventListener('click', suggest);
    input.addEventListener('focus', function () {
        if (input.value.trim() === '') {
            suggest();
        }
    });

    input.addEventListener('keydown', function (event) {
        if (list.hidden || items.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            active = (active + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
            draw();
        } else if ((event.key === 'Tab' || event.key === 'Enter') && active >= 0) {
            event.preventDefault();
            take(items[active]);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            close();
        }
    });

    list.addEventListener('mousedown', function (event) {
        var option = event.target.closest('[data-index]');
        if (option) {
            event.preventDefault();
            take(items[Number(option.dataset.index)]);
        }
    });

    input.addEventListener('blur', function () {
        window.setTimeout(close, 120);
    });
})();

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

/*
 * Changing a ticket where it is shown, and looking at one without leaving
 * the board.
 *
 * Inline: a fact on a ticket's page (data-inline) turns into its control on
 * a click. Enter or Save sends it; Escape or Cancel puts it back. A select
 * changed with a mouse is sent at once. The page is then drawn again from
 * the server — only the parts marked data-refresh — so the history below
 * says what just changed, the same as after the edit form.
 *
 * The panel: a ticket link marked data-panel (a card on the board, a row on
 * the backlog) opens the ticket beside the page instead of in its place.
 * Every form in the panel is sent from here, and the panel and the page
 * behind it are drawn again. A click with Ctrl or the middle button still
 * opens the ticket's own page, as a link does.
 *
 * Without a script none of this happens: the facts are read-only, the Edit
 * page changes them, and a card's link opens the ticket.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-panel-root]');
    var body = root ? root.querySelector('[data-panel-body]') : null;
    var current = null;
    var opener = null;
    var pointer = false;

    document.addEventListener('pointerdown', function () { pointer = true; }, true);
    document.addEventListener('keydown', function () { pointer = false; }, true);

    // -----------------------------------------------------------------------
    // Inline
    // -----------------------------------------------------------------------

    function formFor(trigger) {
        if (trigger.dataset.inlineFor) {
            return document.getElementById(trigger.dataset.inlineFor);
        }
        var box = trigger.closest('[data-inline]');
        return box ? box.querySelector('[data-inline-form]') : null;
    }

    function showFor(form) {
        var box = form.closest('[data-inline]');
        if (box) {
            return box.querySelector('.inline__show');
        }
        var opener = document.querySelector('[data-inline-for="' + form.id + '"]');
        return opener ? opener.closest('h1') : null;
    }

    function open(form) {
        document.querySelectorAll('[data-inline-form]:not([hidden])').forEach(function (other) {
            if (other !== form) {
                cancel(other);
            }
        });

        var show = showFor(form);
        if (show) {
            show.hidden = true;
        }
        form.hidden = false;

        var control = form.querySelector('select, textarea, input:not([type="hidden"])');
        if (control) {
            control.focus();
            if (control.select && control.type === 'text') {
                control.select();
            }
        }
    }

    function cancel(form) {
        form.reset();
        form.hidden = true;
        error(form, '');
        var show = showFor(form);
        if (show) {
            show.hidden = false;
        }
    }

    function error(form, message) {
        var box = form.querySelector('[data-inline-error]');
        if (box) {
            box.textContent = message;
            box.hidden = message === '';
        }
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target.closest) {
            return;
        }

        var cancelButton = target.closest('[data-inline-cancel]');
        if (cancelButton) {
            cancel(cancelButton.form);
            return;
        }

        var trigger = target.closest('[data-inline-open]');
        if (!trigger) {
            return;
        }

        // A link in what is shown (an epic, a person) is still a link.
        var inner = target.closest('a, input, select, textarea, label, summary, form');
        if (inner && trigger.contains(inner)) {
            return;
        }

        var form = formFor(trigger);
        if (form) {
            event.preventDefault();
            open(form);
        }
    });

    document.addEventListener('keydown', function (event) {
        var form = event.target.closest && event.target.closest('[data-inline-form]');

        if (form && event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            cancel(form);
            var show = showFor(form);
            var pencil = show && show.querySelector('[data-inline-open]');
            if (pencil) {
                pencil.focus();
            }
        } else if (form && event.key === 'Enter' && (event.ctrlKey || event.metaKey) && event.target.matches('textarea')) {
            event.preventDefault();
            form.requestSubmit();
        } else if (!form && event.key === 'Enter' && event.target.matches && event.target.matches('.inline__show[data-inline-open]')) {
            open(formFor(event.target));
        }
    });

    document.addEventListener('change', function (event) {
        var select = event.target;
        if (pointer && select.matches && select.matches('select[data-inline-auto]')) {
            select.form.requestSubmit();
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches || !form.matches('[data-inline-form]')) {
            return;
        }

        event.preventDefault();
        form.classList.add('is-busy');

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {Accept: 'application/json'}
        }).then(function (response) {
            return response.json();
        }).then(function (result) {
            form.classList.remove('is-busy');
            if (!result.ok) {
                error(form, result.error || '');
                return;
            }
            if (root && root.contains(form)) {
                reloadPanel(false);
                refreshPage();
            } else {
                refreshPage();
            }
        }).catch(function () {
            // Something the page cannot read: sent the old way instead.
            form.submit();
        });
    });

    /* The parts of the page marked data-refresh, and the board, drawn again. */
    function refreshPage() {
        return fetch(window.location.href, {credentials: 'same-origin'})
            .then(function (response) { return response.text(); })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html');

                document.querySelectorAll('[data-refresh]').forEach(function (part) {
                    if (root && root.contains(part)) {
                        return;
                    }
                    var replacement = fresh.querySelector('[data-refresh="' + part.dataset.refresh + '"]');
                    if (replacement) {
                        part.replaceWith(document.importNode(replacement, true));
                    }
                });

                // The board keeps its element — its dragging is attached to
                // it — and takes the new cards.
                var board = document.querySelector('[data-board]');
                var freshBoard = fresh.querySelector('[data-board]');
                if (board && freshBoard) {
                    board.innerHTML = freshBoard.innerHTML;
                    markSelected();
                }
            });
    }

    window.ctRefresh = refreshPage;

    // -----------------------------------------------------------------------
    // The panel
    // -----------------------------------------------------------------------

    if (!root) {
        return;
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        var target = event.target;
        var link = target.closest && target.closest('a[data-panel]');

        // A click anywhere on a board card that is not one of its controls.
        if (!link && target.closest) {
            var card = target.closest('.ticket-card[data-ticket-id]');
            if (card && !target.closest('a, button, select, input, textarea, label, form')) {
                link = card.querySelector('a[data-panel]');
            }
        }

        if (!link) {
            return;
        }

        var match = (link.getAttribute('href') || '').match(/\/tickets\/(\d+)/);
        if (!match) {
            return;
        }

        event.preventDefault();
        if (!root.contains(link)) {
            opener = link;
        }
        show(match[1]);
    });

    function show(id) {
        current = id;
        root.hidden = false;
        document.body.classList.add('has-panel');
        markSelected();
        reloadPanel(true);
    }

    function close() {
        root.hidden = true;
        body.innerHTML = '';
        current = null;
        document.body.classList.remove('has-panel');
        markSelected();
        if (opener && document.body.contains(opener)) {
            opener.focus();
        }
    }

    function reloadPanel(focus) {
        if (!current) {
            return;
        }
        var url = root.dataset.panelUrl.replace('{id}', current)
            + '?back=' + encodeURIComponent(window.location.pathname + window.location.search);

        fetch(url, {credentials: 'same-origin'})
            .then(function (response) {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }
                return response.text();
            })
            .then(function (html) {
                body.innerHTML = html;
                if (focus) {
                    var heading = body.querySelector('.panel__heading');
                    if (heading) {
                        heading.focus();
                    }
                }
            })
            .catch(function () {
                window.location.href = root.dataset.ticketUrl.replace('{id}', current);
            });
    }

    function markSelected() {
        document.querySelectorAll('.ticket-card.is-selected').forEach(function (card) {
            card.classList.remove('is-selected');
        });
        if (current) {
            var card = document.querySelector('.ticket-card[data-ticket-id="' + current + '"]');
            if (card) {
                card.classList.add('is-selected');
            }
        }
    }

    root.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('[data-panel-close]')) {
            close();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !root.hidden && !event.defaultPrevented) {
            close();
        }
    });

    // Every other form in the panel — a move, a sprint, a comment — is sent
    // from here, without following it to where it would have gone.
    root.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.matches('[data-inline-form]')) {
            return;
        }

        event.preventDefault();
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            redirect: 'manual'
        }).then(function () {
            reloadPanel(false);
            refreshPage();
        });
    });
})();

/*
 * Suggestions while typing in a text box marked data-suggest: "@" offers
 * the people it could mean, "#" and the start of a ticket's key (BIKE-2)
 * the tickets. Arrow keys move through them, Enter or Tab takes one,
 * Escape closes the list; a click takes one too.
 *
 * What is taken is plain text — "@anna", "BIKE-23" — which is what the
 * text would have said if it had been typed out. Without a script nothing
 * is offered and typing it out works as it always did.
 */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="suggest-url"]');
    if (!meta) {
        return;
    }

    var list = document.createElement('ul');
    list.className = 'suggest';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    document.body.appendChild(list);

    var field = null;
    var token = null;
    var items = [];
    var active = 0;
    var timer = null;
    var asked = 0;

    /* What is being typed at the caret, if it is something to suggest for. */
    function tokenAt(textarea) {
        var before = textarea.value.slice(0, textarea.selectionStart);
        var m;

        if ((m = before.match(/(^|[\s(\[])@([\w.\-]{0,40})$/u))) {
            return {kind: 'people', q: m[2], start: before.length - m[2].length - 1};
        }
        if ((m = before.match(/(^|[\s(\[])#([^\s#]{0,40})$/u))) {
            return {kind: 'tickets', q: m[2], start: before.length - m[2].length - 1};
        }
        if ((m = before.match(/(^|[\s(\[])([A-Z][A-Z0-9]{1,9}-\d*)$/))) {
            return {kind: 'tickets', q: m[2], start: before.length - m[2].length};
        }

        return null;
    }

    function ask(textarea) {
        var found = tokenAt(textarea);
        if (!found) {
            close();
            return;
        }

        field = textarea;
        token = found;
        var mine = ++asked;

        fetch(meta.content + '?kind=' + found.kind + '&q=' + encodeURIComponent(found.q), {credentials: 'same-origin'})
            .then(function (response) { return response.ok ? response.json() : {items: []}; })
            .then(function (result) {
                if (mine !== asked) {
                    return;
                }
                items = result.items || [];
                active = 0;
                draw();
            })
            .catch(close);
    }

    function draw() {
        list.innerHTML = '';

        if (items.length === 0) {
            close();
            return;
        }

        items.forEach(function (item, index) {
            var li = document.createElement('li');
            li.className = 'suggest__item' + (index === active ? ' is-active' : '');
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', index === active ? 'true' : 'false');
            var hint = document.createElement('span');
            hint.className = 'suggest__hint';
            hint.textContent = item.hint;
            var label = document.createElement('span');
            label.className = 'suggest__label';
            label.textContent = item.label;
            li.appendChild(hint);
            li.appendChild(label);
            li.addEventListener('mousedown', function (event) {
                event.preventDefault();
                take(index);
            });
            list.appendChild(li);
        });

        place();
        list.hidden = false;
    }

    /* Under the caret: a copy of the text box, as far as the caret, says
       where on the screen that is. */
    function place() {
        var style = window.getComputedStyle(field);
        var mirror = document.createElement('div');
        ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'paddingTop', 'paddingRight',
            'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderLeftWidth', 'boxSizing', 'wordWrap', 'whiteSpace'].forEach(function (name) {
            mirror.style[name] = style[name];
        });
        mirror.style.position = 'absolute';
        mirror.style.visibility = 'hidden';
        mirror.style.whiteSpace = 'pre-wrap';
        mirror.style.width = field.clientWidth + 'px';
        mirror.textContent = field.value.slice(0, field.selectionStart);
        var caret = document.createElement('span');
        caret.textContent = '​';
        mirror.appendChild(caret);
        document.body.appendChild(mirror);

        var box = field.getBoundingClientRect();
        var top = box.top + caret.offsetTop - field.scrollTop + parseFloat(style.lineHeight || '20') + window.scrollY;
        var left = box.left + Math.min(caret.offsetLeft, field.clientWidth - 260) + window.scrollX;
        document.body.removeChild(mirror);

        list.style.top = Math.min(top, box.bottom + window.scrollY) + 'px';
        list.style.left = Math.max(box.left + window.scrollX, left) + 'px';
    }

    function take(index) {
        var item = items[index];
        if (!item || !field || !token) {
            return;
        }

        var value = field.value;
        var caret = field.selectionStart;
        var insert = item.value + ' ';
        field.value = value.slice(0, token.start) + insert + value.slice(caret);
        field.selectionStart = field.selectionEnd = token.start + insert.length;
        field.focus();
        field.dispatchEvent(new Event('input', {bubbles: true}));
        close();
    }

    function close() {
        list.hidden = true;
        items = [];
        token = null;
    }

    document.addEventListener('input', function (event) {
        var textarea = event.target;
        if (!textarea.matches || !textarea.matches('textarea[data-suggest]')) {
            return;
        }
        window.clearTimeout(timer);
        timer = window.setTimeout(function () { ask(textarea); }, 120);
    });

    document.addEventListener('keydown', function (event) {
        if (list.hidden || event.target !== field) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            active = (active + (event.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
            draw();
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            event.stopPropagation();
            take(active);
        } else if (event.key === 'Escape') {
            // Only the list closes; the form it is in stays open.
            event.preventDefault();
            event.stopPropagation();
            close();
        }
    }, true);

    document.addEventListener('focusout', function (event) {
        if (event.target === field) {
            window.setTimeout(close, 150);
        }
    });
})();

/*
 * Jump anywhere, with Ctrl+K (or Cmd+K): a box that goes to a ticket, a
 * page or a project by a few words of it, to what was opened lately, or
 * does one of the things there are shortcuts for.
 *
 * The things to do come with the page (data-commands on the dialog) and are
 * matched here, as they are typed; the tickets, pages and projects are
 * asked of the server. Arrow keys move, Enter goes, Ctrl+Enter opens a new
 * tab, Escape closes — the dialog element takes care of focus.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('palette');
    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }

    var input = dialog.querySelector('[data-palette-input]');
    var list = dialog.querySelector('[data-palette-list]');
    var commands = [];
    try {
        commands = JSON.parse(dialog.dataset.commands || '[]');
    } catch (e) {
        commands = [];
    }

    var items = [];
    var active = 0;
    var asked = 0;
    var timer = null;

    function open() {
        input.value = '';
        dialog.showModal();
        input.focus();
        search();
    }

    document.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && !event.altKey && (event.key === 'k' || event.key === 'K')) {
            event.preventDefault();
            if (dialog.open) {
                dialog.close();
            } else {
                open();
            }
        }
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('[data-palette-open]')) {
            event.preventDefault();
            open();
        }
    });

    // A click on the backdrop — outside the box — closes it.
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    input.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(search, 110);
    });

    input.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (items.length) {
                active = (active + (event.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
                draw();
            }
        } else if (event.key === 'Enter') {
            event.preventDefault();
            go(items[active], event.ctrlKey || event.metaKey);
        }
    });

    /* The things to do whose words are all in what was typed. */
    function matching(q) {
        var words = q.toLowerCase().split(/\s+/).filter(Boolean);

        return commands.filter(function (command) {
            var label = command.label.toLowerCase();
            return words.every(function (word) { return label.indexOf(word) !== -1; });
        }).map(function (command) {
            return {group: dialog.dataset.thingsToDo, label: command.label, hint: '', url: command.url || '', action: command.action || ''};
        });
    }

    function search() {
        var q = input.value.trim();
        var mine = ++asked;

        fetch(dialog.dataset.paletteUrl + encodeURIComponent(q), {credentials: 'same-origin'})
            .then(function (response) { return response.ok ? response.json() : {items: []}; })
            .catch(function () { return {items: []}; })
            .then(function (result) {
                if (mine !== asked) {
                    return;
                }
                var found = result.items || [];
                // With nothing typed: lately, then everything there is to
                // do; with words, what they find, then the things to do.
                items = found.concat(q === '' ? matching('').slice(0, 8) : matching(q));
                active = 0;
                draw();
            });
    }

    function draw() {
        list.innerHTML = '';
        var group = null;

        if (items.length === 0) {
            var empty = document.createElement('li');
            empty.className = 'palette__empty';
            empty.textContent = dialog.dataset.nothing;
            list.appendChild(empty);
            return;
        }

        items.forEach(function (item, index) {
            if (item.group !== group) {
                group = item.group;
                var heading = document.createElement('li');
                heading.className = 'palette__group';
                heading.setAttribute('role', 'presentation');
                heading.textContent = group;
                list.appendChild(heading);
            }

            var li = document.createElement('li');
            li.className = 'palette__item' + (index === active ? ' is-active' : '');
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', index === active ? 'true' : 'false');

            if (item.hint) {
                var hint = document.createElement('span');
                hint.className = 'palette__hint';
                hint.textContent = item.hint;
                li.appendChild(hint);
            }
            var label = document.createElement('span');
            label.className = 'palette__label';
            label.textContent = item.label;
            li.appendChild(label);

            li.addEventListener('mousemove', function () {
                if (active !== index) {
                    active = index;
                    draw();
                }
            });
            li.addEventListener('click', function (event) {
                go(item, event.ctrlKey || event.metaKey);
            });
            list.appendChild(li);
        });

        var current = list.querySelector('.is-active');
        if (current && current.scrollIntoView) {
            current.scrollIntoView({block: 'nearest'});
        }
    }

    function go(item, newTab) {
        if (!item) {
            return;
        }

        if (item.action) {
            dialog.close();
            if (item.action === 'log' && typeof window.ctQuickLog === 'function') {
                window.ctQuickLog();
            } else if (item.action === 'shortcuts') {
                var shortcuts = document.getElementById('shortcuts');
                if (shortcuts && shortcuts.showModal) {
                    shortcuts.showModal();
                }
            } else if (item.action === 'theme') {
                var theme = document.querySelector('[data-theme-switch]');
                if (theme) {
                    theme.requestSubmit ? theme.requestSubmit() : theme.submit();
                }
            }
            return;
        }

        if (newTab) {
            window.open(item.url, '_blank', 'noopener');
        } else {
            window.location.href = item.url;
        }
    }
})();

/*
 * Customizing the dashboard: dragging its pieces, and changing one in place.
 *
 * In the customizing mode (?edit=1) a piece is dragged by anywhere on it
 * into another place or the other column; a line shows where it will land.
 * On the drop the whole layout is sent at once, and nothing reloads. The
 * arrows on each piece do the same without a mouse — they are forms, and
 * work without this file too.
 *
 * The pencil on a piece of one's own opens its fields; "Split by" only
 * shows for "How they split", the one it is for.
 */
(function () {
    'use strict';

    var dash = document.querySelector('[data-dashboard]');
    var token = document.querySelector('meta[name="csrf-token"]');

    // The fields of a piece: "Split by" only where it means something.
    function kindChanged(form) {
        var kind = form.querySelector('[data-piece-kind]');
        var group = form.querySelector('[data-piece-group]');
        if (kind && group) {
            group.hidden = kind.value !== 'breakdown';
        }
    }

    document.querySelectorAll('[data-piece-form]').forEach(kindChanged);
    document.addEventListener('change', function (event) {
        if (event.target.matches && event.target.matches('[data-piece-kind]')) {
            kindChanged(event.target.form);
        }
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-piece-edit]');
        if (!button) {
            return;
        }
        var piece = button.closest('.piece');
        var form = piece && piece.querySelector('.piece__edit');
        if (!form) {
            return;
        }
        form.hidden = !form.hidden;
        piece.querySelectorAll('button[data-piece-edit][aria-expanded]').forEach(function (b) {
            b.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');
        });
        if (!form.hidden) {
            var first = form.querySelector('input[type="text"]');
            if (first) {
                first.focus();
            }
        }
    });

    if (!dash || !dash.dataset.arrangeUrl) {
        return;
    }

    var dragged = null;
    var marker = document.createElement('div');
    marker.className = 'dash__marker';

    dash.addEventListener('dragstart', function (event) {
        var piece = event.target.closest && event.target.closest('.piece[data-gadget-id]');
        // Not from inside a form being filled in.
        if (!piece || (event.target.closest('input, select, textarea') !== null)) {
            return;
        }
        dragged = piece;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', piece.dataset.gadgetId);
        marker.style.height = Math.min(piece.offsetHeight, 120) + 'px';
        window.requestAnimationFrame(function () {
            piece.classList.add('is-dragging');
        });
    });

    dash.addEventListener('dragover', function (event) {
        if (!dragged) {
            return;
        }
        var column = event.target.closest && event.target.closest('.dash__column');
        if (!column) {
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        var after = null;
        var pieces = column.querySelectorAll('.piece[data-gadget-id]:not(.is-dragging)');
        for (var i = 0; i < pieces.length; i++) {
            var box = pieces[i].getBoundingClientRect();
            if (event.clientY < box.top + box.height / 2) {
                after = pieces[i];
                break;
            }
        }
        column.insertBefore(marker, after || column.querySelector('.dash__drop'));
    });

    dash.addEventListener('drop', function (event) {
        if (!dragged || !marker.parentNode) {
            return;
        }
        event.preventDefault();
        marker.parentNode.insertBefore(dragged, marker);
        finish();
        save();
    });

    dash.addEventListener('dragend', finish);

    function finish() {
        if (marker.parentNode) {
            marker.parentNode.removeChild(marker);
        }
        if (dragged) {
            dragged.classList.remove('is-dragging');
            dragged = null;
        }
        dash.querySelectorAll('.dash__drop').forEach(function (drop) {
            var column = drop.closest('.dash__column');
            drop.hidden = column.querySelector('.piece[data-gadget-id]') !== null;
        });
    }

    function save() {
        var layout = {main: [], side: []};
        dash.querySelectorAll('.dash__column').forEach(function (column) {
            column.querySelectorAll('.piece[data-gadget-id]').forEach(function (piece) {
                layout[column.dataset.area].push(parseInt(piece.dataset.gadgetId, 10));
            });
        });

        dash.classList.add('is-saving');
        fetch(dash.dataset.arrangeUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token ? token.content : ''},
            body: JSON.stringify(layout)
        }).then(function (response) {
            dash.classList.remove('is-saving');
            if (!response.ok) {
                window.location.reload();
            }
        }).catch(function () {
            window.location.reload();
        });
    }

    finish();
})();
