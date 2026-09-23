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
