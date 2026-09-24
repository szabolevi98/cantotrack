/*
 * The query box: suggestions for what comes next where the cursor is — a
 * field at the start or after AND and OR, an operator after a field, the
 * field's own values after an operator, and AND, OR or ORDER BY after a
 * value. The values come from the server once per field: the projects,
 * people, sprints and releases the person may see.
 *
 * Arrows move through them, Tab or Enter takes one, Escape closes them;
 * Enter with nothing open sends the query. Without this file the box is a
 * plain field, and the query works the same.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-query-form]');

    if (!form) {
        return;
    }

    var input = form.querySelector('input[name="query"]');
    var list = document.getElementById('query-suggestions');
    var fields = JSON.parse(form.dataset.fields || '[]');
    var operators = ['=', '!=', '~', '!~', '<', '<=', '>', '>=', 'IN (', 'NOT IN (', 'IS EMPTY', 'IS NOT EMPTY'];
    var joiners = ['AND', 'OR', 'ORDER BY'];
    var cache = {};
    var items = [];
    var active = -1;
    var partial = {start: 0, end: 0};

    // The words before the cursor, as the query language reads them.
    function tokens(text) {
        var found = [];
        var pattern = /"(?:[^"\\]|\\.)*"?|'(?:[^'\\]|\\.)*'?|!=|<=|>=|!~|[=<>~(),]|[^\s()=<>~!,"']+/g;
        var match;
        while ((match = pattern.exec(text)) !== null) {
            found.push(match[0]);
        }
        return found;
    }

    function isField(word) {
        if (!word) {
            return false;
        }
        var wanted = word.toLowerCase();
        return fields.some(function (field) { return field.toLowerCase() === wanted; });
    }

    function upper(word) {
        return (word || '').toUpperCase();
    }

    function context() {
        var caret = input.selectionStart || 0;
        var before = input.value.slice(0, caret);
        var word = /[^\s()=<>~!,]*$/.exec(before)[0];
        var done = tokens(before.slice(0, before.length - word.length));
        partial = {start: caret - word.length, end: caret};

        var last = done[done.length - 1];
        var lastUp = upper(last);

        if (!last || lastUp === 'AND' || lastUp === 'OR' || lastUp === 'NOT' || last === '(' && !inList(done)) {
            return {kind: 'fields', word: word};
        }
        if (lastUp === 'BY' || (last === ',' && orderBy(done))) {
            return {kind: 'order', word: word};
        }
        if (isField(last)) {
            return {kind: 'operators', word: word};
        }
        if (['=', '!=', '<', '<=', '>', '>=', '~', '!~'].indexOf(last) !== -1 || (last === '(' || last === ',') && inList(done)) {
            return {kind: 'values', field: fieldOf(done), word: word};
        }
        return {kind: 'joiners', word: word};
    }

    function inList(done) {
        for (var i = done.length - 1; i >= 0; i--) {
            if (done[i] === ')') {
                return false;
            }
            if (upper(done[i]) === 'IN') {
                return true;
            }
        }
        return false;
    }

    function orderBy(done) {
        return done.map(upper).join(' ').indexOf('ORDER BY') !== -1;
    }

    function fieldOf(done) {
        for (var i = done.length - 1; i >= 0; i--) {
            if (isField(done[i])) {
                return done[i].toLowerCase();
            }
        }
        return '';
    }

    function values(field) {
        if (cache[field]) {
            return Promise.resolve(cache[field]);
        }
        return fetch(form.dataset.valuesUrl + '?field=' + encodeURIComponent(field), {credentials: 'same-origin', headers: {Accept: 'application/json'}})
            .then(function (response) { return response.ok ? response.json() : {values: []}; })
            .then(function (result) {
                cache[field] = (result.values || []).map(function (value) {
                    return /^[\w.@()+-]+$/u.test(value) ? value : '"' + value.replace(/"/g, '\\"') + '"';
                });
                return cache[field];
            })
            .catch(function () { return []; });
    }

    function suggest() {
        var at = context();
        var source;

        if (at.kind === 'fields' || at.kind === 'order') {
            source = Promise.resolve(fields);
        } else if (at.kind === 'operators') {
            source = Promise.resolve(operators);
        } else if (at.kind === 'values') {
            source = values(at.field);
        } else {
            source = Promise.resolve(joiners);
        }

        source.then(function (options) {
            var word = at.word.toLowerCase().replace(/^["']/, '');
            items = options.filter(function (option) {
                var plain = option.toLowerCase().replace(/^"/, '');
                return word === '' || plain.indexOf(word) === 0 || (word.length > 1 && plain.indexOf(word) !== -1);
            }).slice(0, 12);
            active = items.length > 0 && word !== '' ? 0 : -1;
            draw();
        });
    }

    function draw() {
        list.innerHTML = '';
        items.forEach(function (item, index) {
            var li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.id = 'query-suggestion-' + index;
            li.dataset.index = index;
            li.textContent = item;
            li.classList.toggle('is-active', index === active);
            list.appendChild(li);
        });
        list.hidden = items.length === 0 || document.activeElement !== input;
        input.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
        input.setAttribute('aria-activedescendant', active >= 0 ? 'query-suggestion-' + active : '');
    }

    function take(item) {
        var value = input.value;
        var after = value.slice(partial.end).replace(/^\S*/, '');
        var text = item + (/\($/.test(item) ? '' : ' ');
        input.value = value.slice(0, partial.start) + text + after.replace(/^\s+/, '');
        var caret = partial.start + text.length;
        input.setSelectionRange(caret, caret);
        input.focus();
        suggest();
    }

    function close() {
        items = [];
        active = -1;
        draw();
    }

    // Offered while typing, or on a click; focus alone opens them only in
    // an empty box — landing on a list of results is not asking for them.
    input.addEventListener('input', suggest);
    input.addEventListener('click', suggest);
    input.addEventListener('focus', function () {
        if (input.value.trim() === '') {
            suggest();
        }
    });

    input.addEventListener('keydown', function (event) {
        if (list.hidden || items.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            active = (active + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
            draw();
        } else if ((event.key === 'Tab' || event.key === 'Enter') && active >= 0) {
            event.preventDefault();
            take(items[active]);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            close();
        }
    });

    list.addEventListener('mousedown', function (event) {
        var option = event.target.closest('[data-index]');
        if (option) {
            event.preventDefault();
            take(items[Number(option.dataset.index)]);
        }
    });

    input.addEventListener('blur', function () {
        window.setTimeout(close, 120);
    });
})();
