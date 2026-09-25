/*
 * The sidebar's scroll position across pages.
 *
 * Every page is a full load, so the menu started at the top again: after a
 * click on something low in it (Settings, Audit log), the active item sat
 * below the fold. Two steps, in this order:
 *
 *   1. Put back: when the click came from the menu, the menu stands where it
 *      stood at the click, so nothing moves — what was on screen stays.
 *   2. Bring into view: when the active item still cannot be seen (the page
 *      was reached from a link on a page, a shortcut, a new tab), it is
 *      scrolled to the middle. When it can be seen, nothing moves.
 *
 * This is its own small file, loaded right after the sidebar and not deferred,
 * so it runs before the first paint and the scroll does not visibly jump.
 * sessionStorage is only a convenience; without it, step 2 still works.
 */
(function () {
    'use strict';

    var KEY = 'ct-sidebar-scroll';
    var menu = document.querySelector('.sidebar__menu');
    if (!menu) {
        return;
    }

    try {
        var saved = window.sessionStorage.getItem(KEY);
        if (saved !== null) {
            menu.scrollTop = parseInt(saved, 10) || 0;
            window.sessionStorage.removeItem(KEY);
        }
    } catch (e) {
        // No storage: step 2 is all there is.
    }

    var active = menu.querySelector('.sidebar__link.is-active');
    if (active) {
        var box = menu.getBoundingClientRect();
        var item = active.getBoundingClientRect();
        if (box.height > 0 && (item.top < box.top || item.bottom > box.bottom)) {
            menu.scrollTop += item.top - box.top - (box.height - item.height) / 2;
        }
    }

    // Only a page change started from the menu, in this tab, keeps the place: after
    // a link in the page the active item is the right start, and a link opened in a
    // new tab (Ctrl, middle button) must not leave a stale value for the next page.
    menu.addEventListener('click', function (event) {
        var plain = event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey;
        if (plain && event.target.closest && event.target.closest('a[href]')) {
            try {
                window.sessionStorage.setItem(KEY, String(menu.scrollTop));
            } catch (e) {
                // Nothing to do.
            }
        }
    });
})();
