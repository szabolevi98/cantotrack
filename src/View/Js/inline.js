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
