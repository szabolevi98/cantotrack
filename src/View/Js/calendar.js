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
