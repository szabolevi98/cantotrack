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
