/*
 * Dragging cards on the board.
 *
 * The card moves in the page at once, and the server is told where it went:
 * which column, between which two cards, and — dropped into another swimlane
 * — whose it is now or which epic it belongs to. If the server refuses, the
 * page reloads and shows where the card really is.
 *
 * This is the mouse's way of doing what the select on every card does. That
 * select stays: it is how a keyboard, a phone and a page without scripts move
 * a ticket, and dragging is not something all of those can do.
 */
(function () {
    'use strict';

    var board = document.querySelector('[data-board]');
    if (!board) {
        return;
    }

    var token = document.querySelector('meta[name="csrf-token"]');
    var dragged = null;
    var origin = null;
    var placeholder = document.createElement('div');
    placeholder.className = 'ticket-card ticket-card--placeholder';

    board.addEventListener('dragstart', function (event) {
        var card = event.target.closest && event.target.closest('.ticket-card[data-ticket-id]');
        if (!card) {
            return;
        }

        dragged = card;
        origin = {column: card.parentNode, next: card.nextSibling};
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', card.dataset.ticketId);

        // The placeholder takes the card's height, so the column does not jump.
        placeholder.style.height = card.offsetHeight + 'px';

        window.requestAnimationFrame(function () {
            card.classList.add('is-dragging');
        });
    });

    board.addEventListener('dragover', function (event) {
        if (!dragged) {
            return;
        }

        var column = event.target.closest && event.target.closest('.board__column');
        if (!column) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        board.querySelectorAll('.board__column.is-target').forEach(function (other) {
            if (other !== column) {
                other.classList.remove('is-target');
            }
        });
        column.classList.add('is-target');

        var after = cardAfter(column, event.clientY);
        if (after) {
            column.insertBefore(placeholder, after);
        } else {
            column.insertBefore(placeholder, column.querySelector('.board__more'));
        }
    });

    board.addEventListener('drop', function (event) {
        if (!dragged || !placeholder.parentNode) {
            return;
        }

        event.preventDefault();

        var column = placeholder.parentNode;
        column.insertBefore(dragged, placeholder);
        finish();

        send(dragged, column);
    });

    board.addEventListener('dragend', function () {
        // Dropped somewhere that is not a column: back where it came from.
        if (dragged && dragged.classList.contains('is-dragging') && placeholder.parentNode) {
            origin.column.insertBefore(dragged, origin.next);
        }

        finish();
    });

    function finish() {
        if (placeholder.parentNode) {
            placeholder.parentNode.removeChild(placeholder);
        }

        board.querySelectorAll('.is-target').forEach(function (column) {
            column.classList.remove('is-target');
        });

        if (dragged) {
            dragged.classList.remove('is-dragging');
        }
    }

    /* The first card in the column whose middle is below the pointer. */
    function cardAfter(column, y) {
        var cards = column.querySelectorAll('.ticket-card[data-ticket-id]:not(.is-dragging)');

        for (var i = 0; i < cards.length; i++) {
            var box = cards[i].getBoundingClientRect();
            if (y < box.top + box.height / 2) {
                return cards[i];
            }
        }

        return null;
    }

    function neighbour(card, direction) {
        var sibling = direction < 0 ? card.previousElementSibling : card.nextElementSibling;

        while (sibling && !sibling.matches('.ticket-card[data-ticket-id]')) {
            sibling = direction < 0 ? sibling.previousElementSibling : sibling.nextElementSibling;
        }

        return sibling ? sibling.dataset.ticketId : '';
    }

    function send(card, column) {
        var fromColumn = origin.column;
        var row = column.closest('.board__row');
        var body = new URLSearchParams();

        body.set('status', column.dataset.statusId);
        body.set('above', neighbour(card, -1));
        body.set('below', neighbour(card, 1));

        // Across lanes, the lane's person or epic comes with the card.
        if (row && row.dataset.laneField && fromColumn.closest('.board__row') !== row) {
            body.set('lane_field', row.dataset.laneField);
            body.set('lane_value', row.dataset.laneValue || '');
        }

        // The select on the card follows, so a keyboard user after a drag
        // sees the column the card is in.
        var select = card.querySelector('select[name="status"]');
        if (select) {
            select.value = column.dataset.statusId;
        }

        if (fromColumn.dataset.statusId !== column.dataset.statusId) {
            recount(fromColumn.dataset.statusId, -1);
            recount(column.dataset.statusId, 1);
        }

        fetch(board.dataset.moveUrl.replace('{id}', card.dataset.ticketId), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {'Accept': 'application/json', 'X-CSRF-Token': token ? token.content : ''}
        }).then(function (response) {
            return response.json().then(function (result) {
                if (!response.ok || !result.ok) {
                    window.alert(result.error || board.dataset.moveFailed);
                    window.location.reload();
                }
            });
        }).catch(function () {
            window.location.reload();
        });
    }

    /* The count in a column's head, and whether it is now over its limit. */
    function recount(statusId, change) {
        var head = board.querySelector('[data-head-for="' + statusId + '"]');
        if (!head) {
            return;
        }

        var count = head.querySelector('[data-count]');
        var limit = head.querySelector('[data-limit]');
        var value = Math.max(0, parseInt(count.textContent, 10) + change);
        count.textContent = value;

        if (limit) {
            head.classList.toggle('board__head--over', value > parseInt(limit.textContent, 10));
        }
    }
})();
