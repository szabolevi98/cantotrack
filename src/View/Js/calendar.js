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
