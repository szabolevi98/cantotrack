/*
 * Customizing the dashboard: dragging its pieces, and changing one in place.
 *
 * In the customizing mode (?edit=1) a piece is dragged by anywhere on it
 * into another place or the other column; a line shows where it will land.
 * On the drop the whole layout is sent at once, and nothing reloads. The
 * arrows on each piece do the same without a mouse — they are forms, and
 * work without this file too.
 *
 * The pencil on a piece of one's own opens its fields; "Split by" only
 * shows for "How they split", the one it is for.
 */
(function () {
    'use strict';

    var dash = document.querySelector('[data-dashboard]');
    var token = document.querySelector('meta[name="csrf-token"]');

    // The fields of a piece: "Split by" only where it means something.
    function kindChanged(form) {
        var kind = form.querySelector('[data-piece-kind]');
        var group = form.querySelector('[data-piece-group]');
        if (kind && group) {
            group.hidden = kind.value !== 'breakdown';
        }
    }

    document.querySelectorAll('[data-piece-form]').forEach(kindChanged);
    document.addEventListener('change', function (event) {
        if (event.target.matches && event.target.matches('[data-piece-kind]')) {
            kindChanged(event.target.form);
        }
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-piece-edit]');
        if (!button) {
            return;
        }
        var piece = button.closest('.piece');
        var form = piece && piece.querySelector('.piece__edit');
        if (!form) {
            return;
        }
        form.hidden = !form.hidden;
        piece.querySelectorAll('button[data-piece-edit][aria-expanded]').forEach(function (b) {
            b.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');
        });
        if (!form.hidden) {
            var first = form.querySelector('input[type="text"]');
            if (first) {
                first.focus();
            }
        }
    });

    if (!dash || !dash.dataset.arrangeUrl) {
        return;
    }

    var dragged = null;
    var marker = document.createElement('div');
    marker.className = 'dash__marker';

    dash.addEventListener('dragstart', function (event) {
        var piece = event.target.closest && event.target.closest('.piece[data-gadget-id]');
        // Not from inside a form being filled in.
        if (!piece || (event.target.closest('input, select, textarea') !== null)) {
            return;
        }
        dragged = piece;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', piece.dataset.gadgetId);
        marker.style.height = Math.min(piece.offsetHeight, 120) + 'px';
        window.requestAnimationFrame(function () {
            piece.classList.add('is-dragging');
        });
    });

    dash.addEventListener('dragover', function (event) {
        if (!dragged) {
            return;
        }
        var column = event.target.closest && event.target.closest('.dash__column');
        if (!column) {
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        var after = null;
        var pieces = column.querySelectorAll('.piece[data-gadget-id]:not(.is-dragging)');
        for (var i = 0; i < pieces.length; i++) {
            var box = pieces[i].getBoundingClientRect();
            if (event.clientY < box.top + box.height / 2) {
                after = pieces[i];
                break;
            }
        }
        column.insertBefore(marker, after || column.querySelector('.dash__drop'));
    });

    dash.addEventListener('drop', function (event) {
        if (!dragged || !marker.parentNode) {
            return;
        }
        event.preventDefault();
        marker.parentNode.insertBefore(dragged, marker);
        finish();
        save();
    });

    dash.addEventListener('dragend', finish);

    function finish() {
        if (marker.parentNode) {
            marker.parentNode.removeChild(marker);
        }
        if (dragged) {
            dragged.classList.remove('is-dragging');
            dragged = null;
        }
        dash.querySelectorAll('.dash__drop').forEach(function (drop) {
            var column = drop.closest('.dash__column');
            drop.hidden = column.querySelector('.piece[data-gadget-id]') !== null;
        });
    }

    function save() {
        var layout = {main: [], side: []};
        dash.querySelectorAll('.dash__column').forEach(function (column) {
            column.querySelectorAll('.piece[data-gadget-id]').forEach(function (piece) {
                layout[column.dataset.area].push(parseInt(piece.dataset.gadgetId, 10));
            });
        });

        dash.classList.add('is-saving');
        fetch(dash.dataset.arrangeUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': token ? token.content : ''},
            body: JSON.stringify(layout)
        }).then(function (response) {
            dash.classList.remove('is-saving');
            if (!response.ok) {
                window.location.reload();
            }
        }).catch(function () {
            window.location.reload();
        });
    }

    finish();
})();
