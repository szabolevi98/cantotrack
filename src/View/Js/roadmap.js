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
