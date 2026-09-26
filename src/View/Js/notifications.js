/*
 * The bell: its menu, and what is new.
 *
 * Opened, the menu is drawn by the server there and then — the newest few,
 * never a stale copy from when the page was loaded. Every minute, while the
 * tab is being looked at, the bell asks what came in since the newest one it
 * knows: the number on it and in the tab's title follow, and each new one
 * pops up in the corner for a few seconds. In a tab left in the background,
 * the browser's own notifications say so instead, for somebody who switched
 * them on (the button on the notifications page).
 *
 * Asking rather than being told: a connection held open for every tab is
 * something PHP under Apache does badly, and a minute is soon enough for a
 * tracker. A page signed out in the meantime stops asking.
 */
(function () {
    'use strict';

    var bell = document.querySelector('[data-notifications]');
    var token = document.querySelector('meta[name="csrf-token"]');
    var DESKTOP = 'ct.desktopNotify';
    var EVERY = 60000;

    setUpDesktopButton();

    if (!bell) {
        return;
    }

    var list = bell.querySelector('[data-notifications-list]');
    var badge = bell.querySelector('[data-notifications-badge]');
    var summary = bell.querySelector('summary');
    var after = parseInt(bell.dataset.after || '0', 10);
    var baseTitle = document.title.replace(/^\(\d+\+?\) /, '');
    var lastAsked = Date.now();
    var stopped = false;
    var timer = null;

    show(parseInt(badge.textContent, 10) || 0);

    // The menu, drawn when it is opened.
    bell.addEventListener('toggle', function () {
        if (bell.open) {
            load();
        }
    });

    // "Mark everything read" in the menu, without leaving the page.
    bell.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-notifications-read-all]');
        if (!form) {
            return;
        }

        event.preventDefault();
        post(form.action).then(function (result) {
            if (result) {
                show(result.unread);
                load();
            }
        });
    });

    timer = window.setInterval(ask, EVERY);

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && Date.now() - lastAsked > EVERY) {
            ask();
        }
    });

    function load() {
        fetch(bell.dataset.menuUrl, {credentials: 'same-origin', headers: {'Accept': 'text/html'}})
            .then(function (response) {
                return response.ok && !response.redirected ? response.text() : null;
            })
            .then(function (html) {
                if (html !== null) {
                    list.innerHTML = html;
                }
            })
            .catch(function () {});
    }

    function ask() {
        // A tab nobody is looking at asks nothing — unless its person wants
        // to be told there, by the browser.
        if (stopped || (document.hidden && !desktopOn())) {
            return;
        }

        lastAsked = Date.now();

        // Marked as asked in the background: it does not count as somebody
        // using the tracker, so it keeps nobody signed in (see Session::start).
        fetch(bell.dataset.pollUrl + '?after=' + after, {credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-CT-Background': '1'}})
            .then(function (response) {
                if (response.status === 401 || response.status === 403 || response.redirected) {
                    stop();
                    return null;
                }

                return response.ok ? response.json() : null;
            })
            .then(function (result) {
                if (!result) {
                    return;
                }

                after = Math.max(after, result.latest || 0);
                show(result.unread);

                if (result.fresh && result.fresh.length) {
                    arrived(result.fresh);
                }
            })
            .catch(function () {});
    }

    function stop() {
        stopped = true;
        window.clearInterval(timer);
    }

    /* The number: on the bell, in its label, and in the tab's title. */
    function show(count) {
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.hidden = count <= 0;
        summary.setAttribute('aria-label', count > 0
            ? bell.dataset.labelUnread.replace('{count}', count)
            : bell.dataset.label);
        document.title = (count > 0 ? '(' + (count > 99 ? '99+' : count) + ') ' : '') + baseTitle;
    }

    function arrived(fresh) {
        if (document.hidden && desktopOn()) {
            fresh.slice(-3).forEach(function (item) {
                var note = new window.Notification(item.title, {body: item.text, tag: 'ct-notification-' + item.id});
                note.onclick = function () {
                    window.focus();
                    window.location.href = item.url;
                    note.close();
                };
            });
            return;
        }

        // More than three at once is one note saying how many.
        if (fresh.length > 3) {
            toast('<a class="toast__link" href="' + bell.dataset.menuUrl.replace(/\/menu$/, '') + '"><i class="bi bi-bell notification__icon" aria-hidden="true"></i><span class="toast__body"><span class="toast__what">'
                + bell.dataset.many.replace('{count}', fresh.length) + '</span></span></a>');
            return;
        }

        fresh.forEach(function (item) {
            toast(item.html);
        });
    }

    function toast(html) {
        var shelf = document.querySelector('.toasts');
        if (!shelf) {
            shelf = document.createElement('div');
            shelf.className = 'toasts';
            shelf.setAttribute('role', 'status');
            shelf.setAttribute('aria-live', 'polite');
            document.body.appendChild(shelf);
        }

        var note = document.createElement('div');
        note.className = 'toast';
        note.innerHTML = html;

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast__close';
        close.setAttribute('aria-label', '×');
        close.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
        close.addEventListener('click', function () {
            note.remove();
        });
        note.appendChild(close);

        shelf.appendChild(note);

        // Gone by itself after a while — later while the pointer is on it.
        var left = 8000;
        var tick = window.setInterval(function () {
            if (!note.matches(':hover')) {
                left -= 500;
            }
            if (left <= 0 || !note.isConnected) {
                window.clearInterval(tick);
                note.remove();
            }
        }, 500);
    }

    function post(url) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-CSRF-Token': token ? token.content : ''}
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).catch(function () {
            return null;
        });
    }

    function desktopOn() {
        try {
            return 'Notification' in window && window.Notification.permission === 'granted' && window.localStorage.getItem(DESKTOP) === '1';
        } catch (e) {
            return false;
        }
    }

    /* The button on the notifications page that switches the browser's own on and off. */
    function setUpDesktopButton() {
        var button = document.querySelector('[data-desktop-notify]');
        if (!button || !('Notification' in window)) {
            return;
        }

        var label = button.querySelector('span');
        var on = function () {
            try {
                return window.Notification.permission === 'granted' && window.localStorage.getItem(DESKTOP) === '1';
            } catch (e) {
                return false;
            }
        };
        var set = function (value) {
            try {
                window.localStorage.setItem(DESKTOP, value ? '1' : '0');
            } catch (e) {
                // Somewhere nothing can be kept: it stays as it was.
            }
            refresh();
        };
        var refresh = function () {
            label.textContent = on() ? button.dataset.on : button.dataset.off;
            button.classList.toggle('is-on', on());
            button.setAttribute('aria-pressed', on() ? 'true' : 'false');
        };

        button.hidden = false;
        refresh();

        button.addEventListener('click', function () {
            if (on()) {
                set(false);
            } else if (window.Notification.permission === 'granted') {
                set(true);
            } else if (window.Notification.permission === 'denied') {
                window.alert(button.dataset.blocked);
            } else {
                window.Notification.requestPermission().then(function (permission) {
                    set(permission === 'granted');
                });
            }
        });
    }
})();
