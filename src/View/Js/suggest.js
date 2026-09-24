/*
 * Suggestions while typing in a text box marked data-suggest: "@" offers
 * the people it could mean, "#" and the start of a ticket's key (BIKE-2)
 * the tickets. Arrow keys move through them, Enter or Tab takes one,
 * Escape closes the list; a click takes one too.
 *
 * What is taken is plain text — "@anna", "BIKE-23" — which is what the
 * text would have said if it had been typed out. Without a script nothing
 * is offered and typing it out works as it always did.
 */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="suggest-url"]');
    if (!meta) {
        return;
    }

    var list = document.createElement('ul');
    list.className = 'suggest';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    document.body.appendChild(list);

    var field = null;
    var token = null;
    var items = [];
    var active = 0;
    var timer = null;
    var asked = 0;

    /* What is being typed at the caret, if it is something to suggest for. */
    function tokenAt(textarea) {
        var before = textarea.value.slice(0, textarea.selectionStart);
        var m;

        if ((m = before.match(/(^|[\s(\[])@([\w.\-]{0,40})$/u))) {
            return {kind: 'people', q: m[2], start: before.length - m[2].length - 1};
        }
        if ((m = before.match(/(^|[\s(\[])#([^\s#]{0,40})$/u))) {
            return {kind: 'tickets', q: m[2], start: before.length - m[2].length - 1};
        }
        if ((m = before.match(/(^|[\s(\[])([A-Z][A-Z0-9]{1,9}-\d*)$/))) {
            return {kind: 'tickets', q: m[2], start: before.length - m[2].length};
        }

        return null;
    }

    function ask(textarea) {
        var found = tokenAt(textarea);
        if (!found) {
            close();
            return;
        }

        field = textarea;
        token = found;
        var mine = ++asked;

        fetch(meta.content + '?kind=' + found.kind + '&q=' + encodeURIComponent(found.q), {credentials: 'same-origin'})
            .then(function (response) { return response.ok ? response.json() : {items: []}; })
            .then(function (result) {
                if (mine !== asked) {
                    return;
                }
                items = result.items || [];
                active = 0;
                draw();
            })
            .catch(close);
    }

    function draw() {
        list.innerHTML = '';

        if (items.length === 0) {
            close();
            return;
        }

        items.forEach(function (item, index) {
            var li = document.createElement('li');
            li.className = 'suggest__item' + (index === active ? ' is-active' : '');
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', index === active ? 'true' : 'false');
            var hint = document.createElement('span');
            hint.className = 'suggest__hint';
            hint.textContent = item.hint;
            var label = document.createElement('span');
            label.className = 'suggest__label';
            label.textContent = item.label;
            li.appendChild(hint);
            li.appendChild(label);
            li.addEventListener('mousedown', function (event) {
                event.preventDefault();
                take(index);
            });
            list.appendChild(li);
        });

        place();
        list.hidden = false;
    }

    /* Under the caret: a copy of the text box, as far as the caret, says
       where on the screen that is. */
    function place() {
        var style = window.getComputedStyle(field);
        var mirror = document.createElement('div');
        ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'paddingTop', 'paddingRight',
            'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderLeftWidth', 'boxSizing', 'wordWrap', 'whiteSpace'].forEach(function (name) {
            mirror.style[name] = style[name];
        });
        mirror.style.position = 'absolute';
        mirror.style.visibility = 'hidden';
        mirror.style.whiteSpace = 'pre-wrap';
        mirror.style.width = field.clientWidth + 'px';
        mirror.textContent = field.value.slice(0, field.selectionStart);
        var caret = document.createElement('span');
        caret.textContent = '​';
        mirror.appendChild(caret);
        document.body.appendChild(mirror);

        var box = field.getBoundingClientRect();
        var top = box.top + caret.offsetTop - field.scrollTop + parseFloat(style.lineHeight || '20') + window.scrollY;
        var left = box.left + Math.min(caret.offsetLeft, field.clientWidth - 260) + window.scrollX;
        document.body.removeChild(mirror);

        list.style.top = Math.min(top, box.bottom + window.scrollY) + 'px';
        list.style.left = Math.max(box.left + window.scrollX, left) + 'px';
    }

    function take(index) {
        var item = items[index];
        if (!item || !field || !token) {
            return;
        }

        var value = field.value;
        var caret = field.selectionStart;
        var insert = item.value + ' ';
        field.value = value.slice(0, token.start) + insert + value.slice(caret);
        field.selectionStart = field.selectionEnd = token.start + insert.length;
        field.focus();
        field.dispatchEvent(new Event('input', {bubbles: true}));
        close();
    }

    function close() {
        list.hidden = true;
        items = [];
        token = null;
    }

    document.addEventListener('input', function (event) {
        var textarea = event.target;
        if (!textarea.matches || !textarea.matches('textarea[data-suggest]')) {
            return;
        }
        window.clearTimeout(timer);
        timer = window.setTimeout(function () { ask(textarea); }, 120);
    });

    document.addEventListener('keydown', function (event) {
        if (list.hidden || event.target !== field) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            active = (active + (event.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
            draw();
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            event.stopPropagation();
            take(active);
        } else if (event.key === 'Escape') {
            // Only the list closes; the form it is in stays open.
            event.preventDefault();
            event.stopPropagation();
            close();
        }
    }, true);

    document.addEventListener('focusout', function (event) {
        if (event.target === field) {
            window.setTimeout(close, 150);
        }
    });
})();
