/*
 | Progressive enhancement only.
 |
 | Every control this file touches starts hidden, or starts as a link that
 | already works, and is upgraded here. A reader without JavaScript gets a
 | complete, navigable page rather than dead buttons: the menu link jumps to
 | the navigation at the end of the document, the snippets stay stacked and
 | labelled, and search is simply absent.
 |
 | No framework and no build step: this ships as-is inside the page.
 */
(function () {
    'use strict';

    var mobile = window.matchMedia ? window.matchMedia('(max-width: 1023px)') : null;

    function isNarrow() {
        return mobile ? mobile.matches : false;
    }

    /* ---------------------------------------------------------------- copy */

    function flash(button, message) {
        var previous = button.getAttribute('data-lusen-label') || button.textContent;

        button.setAttribute('data-lusen-label', previous);
        button.textContent = message;
        button.classList.add('is-copied');

        setTimeout(function () {
            button.textContent = previous;
            button.classList.remove('is-copied');
        }, 1600);
    }

    function initCopy() {
        if (!navigator.clipboard) return;

        document.querySelectorAll('[data-lusen-copy]').forEach(function (button) {
            button.hidden = false;

            button.addEventListener('click', function () {
                var block = button.closest('.lusen-code');
                var code = block && block.querySelector('code');

                if (!code) return;

                navigator.clipboard.writeText(code.innerText).then(function () {
                    flash(button, 'Copied');
                });
            });
        });
    }

    /*
     | Hands over the page's Markdown twin rather than its HTML.
     |
     | Pasting a documentation page into a model is the common case now, and
     | copying the rendered page brings navigation, badges and markup the model
     | pays for and cannot use. The .md file is the same content without any of
     | that, and it already exists.
     */
    function initCopyPage() {
        if (!navigator.clipboard || !window.fetch) return;

        document.querySelectorAll('[data-lusen-copy-page]').forEach(function (button) {
            button.hidden = false;

            button.addEventListener('click', function () {
                fetch(button.getAttribute('data-lusen-copy-page'))
                    .then(function (response) {
                        if (!response.ok) throw new Error('unavailable');
                        return response.text();
                    })
                    .then(function (markdown) {
                        return navigator.clipboard.writeText(markdown);
                    })
                    .then(function () {
                        flash(button, 'Copied');
                    })
                    .catch(function () {
                        flash(button, 'Copy failed');
                    });
            });
        });
    }

    /* ---------------------------------------------------------------- menu */

    /*
     | The navigation sits at the end of the document on a narrow screen, and
     | the bar at the top links to it. That already works; this upgrades the
     | link to a control that opens the same element as a panel, so reaching
     | the navigation does not mean losing your place in the page.
     */
    var menu = null;

    function closeMenu() {
        if (!menu || !menu.open) return;

        menu.open = false;
        menu.nav.classList.remove('is-open');
        menu.toggle.setAttribute('aria-expanded', 'false');
        menu.toggle.textContent = 'Menu';
        document.body.classList.remove('lusen-locked');
    }

    function openMenu() {
        if (!menu || menu.open) return;

        menu.open = true;
        menu.nav.classList.add('is-open');
        menu.toggle.setAttribute('aria-expanded', 'true');
        menu.toggle.textContent = 'Close';
        document.body.classList.add('lusen-locked');
    }

    function initMenu() {
        var toggle = document.querySelector('[data-lusen-menu]');
        var nav = document.querySelector('.lusen-nav');

        if (!toggle || !nav) return;

        menu = { toggle: toggle, nav: nav, open: false };

        toggle.setAttribute('aria-expanded', 'false');

        toggle.addEventListener('click', function (event) {
            event.preventDefault();

            if (menu.open) closeMenu();
            else openMenu();
        });

        // At runtime the whole API is one page and every link is a fragment,
        // so a panel that stayed open would cover what was just navigated to.
        nav.addEventListener('click', function (event) {
            if (event.target.closest('a')) closeMenu();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeMenu();
        });

        // Widening past the breakpoint puts the navigation back in its column,
        // where a leftover open state would pin it over the content.
        if (mobile && mobile.addEventListener) {
            mobile.addEventListener('change', function () {
                if (!isNarrow()) closeMenu();
            });
        }
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

    function initSearch() {
        var form = document.querySelector('[data-lusen-search]');
        var url = window.lusenSearchIndex;

        if (!form || !url || !window.fetch) return;

        var input = form.querySelector('input');
        var list = form.querySelector('.lusen-search-results');
        var empty = form.querySelector('.lusen-search-empty');
        var hint = form.querySelector('[data-lusen-search-hint]');
        var options = [];
        var active = -1;

        function highlight(next) {
            if (options.length === 0) return;

            // Wraps, so holding one arrow key cycles instead of dead-ending.
            active = (next + options.length) % options.length;

            options.forEach(function (option, position) {
                var on = position === active;
                option.classList.toggle('is-active', on);
                option.setAttribute('aria-selected', on ? 'true' : 'false');
            });

            input.setAttribute('aria-activedescendant', options[active].id);
            options[active].scrollIntoView({ block: 'nearest' });
        }

        function close() {
            list.hidden = true;
            empty.hidden = true;
            options = [];
            active = -1;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }

        function render(results) {
            list.textContent = '';
            options = [];
            active = -1;
            input.removeAttribute('aria-activedescendant');

            results.forEach(function (item, position) {
                var li = document.createElement('li');
                var a = document.createElement('a');

                li.setAttribute('role', 'presentation');

                a.href = item.url;
                a.className = 'lusen-search-hit';
                a.id = 'lusen-search-option-' + position;
                a.setAttribute('role', 'option');
                a.setAttribute('aria-selected', 'false');

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
                options.push(a);
            });

            list.hidden = results.length === 0;
            empty.hidden = results.length !== 0;
            input.setAttribute('aria-expanded', results.length === 0 ? 'false' : 'true');
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            // Enter with nothing highlighted takes the best match, which is
            // what someone who typed and hit return meant.
            if (options.length > 0) options[active === -1 ? 0 : active].click();
        });

        input.addEventListener('input', function () {
            var query = input.value.trim().toLowerCase();

            if (query.length < 2) {
                close();
                return;
            }

            loadIndex(url).then(function () {
                render(search(query.split(/\s+/)));
            }).catch(function () { form.hidden = true; });
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                input.value = '';
                close();
                input.blur();
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                highlight(active + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                highlight(active - 1);
            } else if (event.key === 'Enter' && active !== -1) {
                event.preventDefault();
                options[active].click();
            }
        });

        // A click anywhere else is a dismissal; the results otherwise sit over
        // the navigation until something is typed.
        document.addEventListener('click', function (event) {
            if (!form.contains(event.target)) close();
        });

        /*
         | The shortcut everyone tries first, and slash for the people who came
         | from a terminal. Both are announced in the field rather than left to
         | be discovered - on a pointer device, where the keys exist.
         */
        document.addEventListener('keydown', function (event) {
            var focused = document.activeElement || document.body;
            var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(focused.tagName) || focused.isContentEditable;

            var shortcut = (event.key === 'k' && (event.metaKey || event.ctrlKey))
                || (event.key === '/' && !typing && !event.metaKey && !event.ctrlKey && !event.altKey);

            if (!shortcut) return;

            event.preventDefault();

            // On a narrow screen the field is inside the navigation panel, so
            // the panel has to come up with it.
            if (isNarrow()) openMenu();

            input.focus();
            input.select();
        });

        if (hint && window.matchMedia && window.matchMedia('(hover: hover)').matches) {
            hint.textContent = /Mac|iPhone|iPad/.test(navigator.platform || '') ? '⌘K' : 'Ctrl K';
            hint.hidden = false;
        }

        // Only reveal the box once an index is known to be reachable: a search
        // field that cannot search is worse than none at all.
        loadIndex(url)
            .then(function () { form.hidden = false; })
            .catch(function () { form.hidden = true; });
    }

    /* ---------------------------------------------------------------- tabs */

    /*
     | Request examples ship stacked, one labelled block per language, because
     | that is what reads without JavaScript and what a model retrieving the
     | HTML should see. Where the script runs they become tabs: three languages
     | stacked push the responses - the thing you read next - a screen and a
     | half down the page.
     |
     | The choice is remembered, so a reader who works in JavaScript is not
     | reselecting it on every endpoint.
     */
    var SNIPPET_KEY = 'lusen-snippet';

    function storedSnippet() {
        try { return localStorage.getItem(SNIPPET_KEY); } catch (e) { return null; }
    }

    var snippetId = 0;

    function initTabs() {
        var preferred = storedSnippet();

        document.querySelectorAll('[data-lusen-tabs]').forEach(function (set) {
            var blocks = Array.prototype.slice.call(set.querySelectorAll('.lusen-code'));

            if (blocks.length < 2) return;

            var names = blocks.map(function (block, index) {
                var label = block.querySelector('.lusen-code-label');

                return label ? label.textContent.trim() : 'Example ' + (index + 1);
            });

            // One strip per block, sitting in the block's own head beside its
            // copy button. Only one block is ever visible, so what a reader
            // sees is a single bar - tabs on the left, copy on the right -
            // rather than a strip stacked on top of a header.
            var strips = blocks.map(function () { return []; });

            function select(position, focus) {
                blocks.forEach(function (block, index) {
                    block.hidden = index !== position;
                });

                strips.forEach(function (tabs) {
                    tabs.forEach(function (tab, index) {
                        var on = index === position;
                        tab.setAttribute('aria-selected', on ? 'true' : 'false');
                        tab.tabIndex = on ? 0 : -1;
                        tab.classList.toggle('is-active', on);
                    });
                });

                if (focus) strips[position][position].focus();
            }

            blocks.forEach(function (block) {
                block.id = 'lusen-snippet-' + (++snippetId);
                block.setAttribute('role', 'tabpanel');
            });

            blocks.forEach(function (block, blockIndex) {
                var head = block.querySelector('.lusen-code-head');

                if (!head) return;

                var strip = document.createElement('div');

                strip.className = 'lusen-tabs';
                strip.setAttribute('role', 'tablist');

                names.forEach(function (name, index) {
                    var tab = document.createElement('button');

                    tab.type = 'button';
                    tab.className = 'lusen-tab';
                    tab.textContent = name;
                    tab.setAttribute('role', 'tab');
                    tab.setAttribute('aria-controls', blocks[index].id);

                    tab.addEventListener('click', function () {
                        select(index, false);

                        try { localStorage.setItem(SNIPPET_KEY, name); } catch (e) {}
                    });

                    tab.addEventListener('keydown', function (event) {
                        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;

                        event.preventDefault();
                        select((index + (event.key === 'ArrowRight' ? 1 : -1) + names.length) % names.length, true);
                    });

                    strips[blockIndex].push(tab);
                    strip.appendChild(tab);
                });

                head.insertBefore(strip, head.firstChild);
            });

            set.classList.add('is-tabbed');

            var remembered = names.indexOf(preferred);

            select(remembered === -1 ? 0 : remembered, false);
        });
    }

    /* ------------------------------------------------------------- servers */

    /*
     | Switching the base URL rewrites it wherever it appears in the page -
     | every example, the full URL line, the prose - so the request a reader
     | copies is against the server they picked.
     |
     | Text nodes rather than innerHTML: the examples are highlighted at build
     | time, and rewriting markup would mean re-parsing spans on every change
     | and losing anything the reader had selected.
     */
    function initServers() {
        var select = document.querySelector('[data-lusen-server]');
        var root = document.getElementById('content');

        if (!select || !root || select.options.length < 2) return;

        var current = select.options[0].value;

        function rewrite(next) {
            if (next === current || next === '') return;

            var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
            var node;

            while ((node = walker.nextNode())) {
                if (node.nodeValue.indexOf(current) !== -1) {
                    node.nodeValue = node.nodeValue.split(current).join(next);
                }
            }

            current = next;
        }

        var stored = null;

        try { stored = localStorage.getItem('lusen-server'); } catch (e) {}

        if (stored) {
            for (var i = 0; i < select.options.length; i++) {
                if (select.options[i].value === stored) {
                    select.value = stored;
                    rewrite(stored);
                    break;
                }
            }
        }

        select.addEventListener('change', function () {
            rewrite(select.value);

            try { localStorage.setItem('lusen-server', select.value); } catch (e) {}
        });

        select.hidden = false;
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

    /* ------------------------------------------------------------- sidebar */

    /*
     | The sidebar scrolls independently, so on an API with forty endpoints the
     | page you are reading is often below the fold of its own navigation, and
     | nothing on screen says where you are.
     */
    function initSidebar() {
        var current = document.querySelector('.lusen-nav [aria-current="page"]');

        if (!current || isNarrow()) return;

        var pane = current.closest('.lusen-nav div');

        if (!pane || pane.scrollHeight <= pane.clientHeight) return;

        // Measured rather than read off offsetTop, which is relative to
        // whichever ancestor happens to be positioned.
        var offset = current.getBoundingClientRect().top
            - pane.getBoundingClientRect().top
            + pane.scrollTop;

        if (offset > pane.clientHeight - 80) {
            pane.scrollTop = offset - pane.clientHeight / 2;
        }
    }

    function init() {
        initCopy();
        initCopyPage();
        initMenu();
        initSearch();
        initTabs();
        initServers();
        initTheme();
        initSidebar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
