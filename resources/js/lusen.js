/*
 | Progressive enhancement only.
 |
 | Every control this file touches starts hidden and is revealed here, so a
 | reader without JavaScript sees a complete, navigable page rather than dead
 | buttons. Nothing here is required to read the documentation.
 |
 | No framework and no build step: this ships as-is inside the page.
 */
(function () {
    'use strict';

    /* ---------------------------------------------------------------- copy */

    function initCopy() {
        if (!navigator.clipboard) return;

        document.querySelectorAll('[data-lusen-copy]').forEach(function (button) {
            button.hidden = false;

            button.addEventListener('click', function () {
                var block = button.closest('.lusen-code');
                var code = block && block.querySelector('code');

                if (!code) return;

                navigator.clipboard.writeText(code.innerText).then(function () {
                    var previous = button.textContent;
                    button.textContent = 'Copied';
                    button.classList.add('is-copied');

                    setTimeout(function () {
                        button.textContent = previous;
                        button.classList.remove('is-copied');
                    }, 1600);
                });
            });
        });
    }

    /* -------------------------------------------------------------- search */

    var index = null;
    var loading = null;

    function loadIndex(url) {
        if (index) return Promise.resolve(index);
        if (loading) return loading;

        loading = fetch(url)
            .then(function (response) {
                if (!response.ok) throw new Error('unavailable');
                return response.json();
            })
            .then(function (data) {
                index = (data && data.items) || [];
                return index;
            });

        return loading;
    }

    function score(item, terms) {
        var title = item.title.toLowerCase();
        var path = (item.path || '').toLowerCase();
        var text = (item.text || '').toLowerCase();
        var total = 0;

        for (var i = 0; i < terms.length; i++) {
            var term = terms[i];

            if (title.indexOf(term) === 0) total += 10;
            else if (title.indexOf(term) !== -1) total += 6;
            else if (path.indexOf(term) !== -1) total += 4;
            else if (text.indexOf(term) !== -1) total += 1;
            else return 0; // every term must match somewhere
        }

        return total;
    }

    function search(terms) {
        return index
            .map(function (item) { return { item: item, score: score(item, terms) }; })
            .filter(function (hit) { return hit.score > 0; })
            .sort(function (a, b) { return b.score - a.score; })
            .slice(0, 10)
            .map(function (hit) { return hit.item; });
    }

    function render(list, empty, results) {
        list.textContent = '';

        results.forEach(function (item) {
            var li = document.createElement('li');
            var a = document.createElement('a');

            a.href = item.url;
            a.className = 'lusen-search-hit';

            if (item.method) {
                var badge = document.createElement('span');
                badge.className = 'lusen-search-method';
                badge.textContent = item.method;
                a.appendChild(badge);
            }

            var label = document.createElement('span');
            label.className = 'lusen-search-title';
            label.textContent = item.title;
            a.appendChild(label);

            if (item.context) {
                var context = document.createElement('span');
                context.className = 'lusen-search-context';
                context.textContent = item.context;
                a.appendChild(context);
            }

            li.appendChild(a);
            list.appendChild(li);
        });

        list.hidden = results.length === 0;
        empty.hidden = results.length !== 0;
    }

    function initSearch() {
        var form = document.querySelector('[data-lusen-search]');
        var url = window.lusenSearchIndex;

        if (!form || !url || !window.fetch) return;

        var input = form.querySelector('input');
        var list = form.querySelector('.lusen-search-results');
        var empty = form.querySelector('.lusen-search-empty');

        form.addEventListener('submit', function (event) { event.preventDefault(); });

        input.addEventListener('input', function () {
            var query = input.value.trim().toLowerCase();

            if (query.length < 2) {
                list.hidden = true;
                empty.hidden = true;
                return;
            }

            loadIndex(url).then(function () {
                render(list, empty, search(query.split(/\s+/)));
            }).catch(function () { form.hidden = true; });
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                input.value = '';
                list.hidden = true;
                empty.hidden = true;
                input.blur();
            }
        });

        // Only reveal the box once an index is known to be reachable: a search
        // field that cannot search is worse than none at all.
        loadIndex(url)
            .then(function () { form.hidden = false; })
            .catch(function () { form.hidden = true; });
    }

    /* --------------------------------------------------------------- theme */

    function initTheme() {
        var button = document.querySelector('[data-lusen-theme]');

        if (!button) return;

        var label = button.querySelector('[data-lusen-theme-label]');
        var root = document.documentElement;

        function stored() {
            try { return localStorage.getItem('lusen-theme'); } catch (e) { return null; }
        }

        function systemDark() {
            return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        }

        function current() {
            return root.getAttribute('data-theme') || (systemDark() ? 'dark' : 'light');
        }

        function paint() {
            var choice = stored();
            label.textContent = choice === null
                ? 'Theme: system'
                : (choice === 'dark' ? 'Theme: dark' : 'Theme: light');
        }

        button.addEventListener('click', function () {
            // Cycles through the three real states, so a reader can get back
            // to following the operating system.
            var next = stored() === null
                ? (current() === 'dark' ? 'light' : 'dark')
                : (stored() === 'dark' ? null : 'dark');

            try {
                if (next === null) {
                    localStorage.removeItem('lusen-theme');
                    root.removeAttribute('data-theme');
                } else {
                    localStorage.setItem('lusen-theme', next);
                    root.setAttribute('data-theme', next);
                }
            } catch (e) {}

            paint();
        });

        paint();
        button.hidden = false;
    }

    function init() {
        initCopy();
        initSearch();
        initTheme();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
