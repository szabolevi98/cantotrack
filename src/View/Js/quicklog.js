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
