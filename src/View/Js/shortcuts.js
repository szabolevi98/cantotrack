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
