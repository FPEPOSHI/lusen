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
    function storedTab(kind) {
        try { return localStorage.getItem('lusen-tab-' + kind); } catch (e) { return null; }
    }

    var snippetId = 0;

    function initTabs() {
        document.querySelectorAll('[data-lusen-tabs]').forEach(function (set) {
            // Languages and status codes are both tab sets and remember
            // separately: picking a 404 must not be read back as a request
            // language on the next page.
            var kind = set.getAttribute('data-lusen-tabs') || 'snippet';
            var preferred = storedTab(kind);

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

                        try { localStorage.setItem('lusen-tab-' + kind, name); } catch (e) {}
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

    /* -------------------------------------------------------------- try it */

    /*
     | Sends the request the page is documenting, from the page.
     |
     | There is no proxy: the call goes straight from the reader's browser to
     | the API, which is the only thing a package of flat files can honestly
     | do. That makes CORS the whole story, so the failure it produces is
     | handled as carefully as the success - a browser reports a blocked
     | cross-origin response as an indistinguishable "Failed to fetch", and a
     | reader who sees that concludes the API is down.
     |
     | The request is read from the JSON the page carries, which is the same
     | model the printed example was rendered from. Nothing here re-derives
     | what a parameter or a body should contain.
     */
    function element(tag, className, text) {
        var node = document.createElement(tag);

        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;

        return node;
    }

    /*
     | Credentials, and where they are kept.
     |
     | There is no store a browser offers that a script on this origin cannot
     | read: localStorage and sessionStorage are equally exposed to anything
     | running here, and a token that JavaScript has to attach to a fetch
     | cannot be hidden from JavaScript. HttpOnly cookies are the exception and
     | are no use, because the point is to set a header.
     |
     | So the difference between the two is lifetime, not secrecy, and lifetime
     | is what this manages:
     |
     |   - memory        nothing is written down; gone on reload
     |   - session       gone when the tab closes - the default
     |   - local         survives the browser; only when the site allows it AND
     |                   the reader asks for it, and one button forgets it
     |
     | Keys carry the base URL, so a sandbox token is never offered up to a
     | production host, and nothing is ever written into a copied example.
     */
    var tokenFields = [];
    var memory = {};
    var remember = false;

    function tokenPolicy() {
        return (window.lusenTryIt && window.lusenTryIt.persist) || 'session';
    }

    function tokenStore() {
        var policy = tokenPolicy();

        try {
            if (policy === 'local' && remember) return localStorage;
            if (policy === 'local' || policy === 'session') return sessionStorage;
        } catch (e) {}

        return null;
    }

    function tokenKey(origin, header) {
        return 'lusen-token:' + origin + ':' + header;
    }

    function tokenOrigin() {
        var select = document.querySelector('[data-lusen-server]');

        return (select && select.value) || (window.lusenTryIt && window.lusenTryIt.baseUrl) || '';
    }

    function readToken(header) {
        var key = tokenKey(tokenOrigin(), header);

        if (memory[key] !== undefined) return memory[key];

        try {
            return (localStorage.getItem(key) || sessionStorage.getItem(key)) || '';
        } catch (e) {
            return '';
        }
    }

    function writeToken(header, value, source) {
        var key = tokenKey(tokenOrigin(), header);
        var store = tokenStore();

        memory[key] = value;

        try {
            // Only ever in one place at a time, so turning remembering off
            // does not leave a copy behind in the other.
            (store === localStorage ? sessionStorage : localStorage).removeItem(key);

            if (store) store.setItem(key, value);
        } catch (e) {}

        tokenFields.forEach(function (field) {
            if (field.header === header && field.input !== source) field.input.value = value;
        });
    }

    function forgetTokens() {
        memory = {};

        tokenFields.forEach(function (field) {
            var key = tokenKey(tokenOrigin(), field.header);

            try {
                localStorage.removeItem(key);
                sessionStorage.removeItem(key);
            } catch (e) {}

            field.input.value = '';
        });
    }

    function bindToken(header, input) {
        input.value = readToken(header);
        tokenFields.push({ header: header, input: input });

        input.addEventListener('input', function () {
            writeToken(header, input.value, input);
        });
    }

    var REMEMBER_KEY = 'lusen-remember';

    function initAuth() {
        var panel = document.querySelector('[data-lusen-auth]');

        if (!panel) return;

        var policy = tokenPolicy();

        try { remember = policy === 'local' && localStorage.getItem(REMEMBER_KEY) === 'yes'; } catch (e) {}

        panel.querySelectorAll('[data-lusen-auth-input]').forEach(function (input) {
            bindToken(input.getAttribute('data-lusen-auth-input'), input);
        });

        var wrap = panel.querySelector('[data-lusen-auth-remember]');
        var checkbox = wrap && wrap.querySelector('input');

        if (checkbox && policy === 'local') {
            checkbox.checked = remember;
            wrap.hidden = false;

            checkbox.addEventListener('change', function () {
                remember = checkbox.checked;

                try {
                    if (remember) localStorage.setItem(REMEMBER_KEY, 'yes');
                    else localStorage.removeItem(REMEMBER_KEY);
                } catch (e) {}

                // Move what is already typed to wherever it now belongs.
                tokenFields.forEach(function (field) {
                    writeToken(field.header, field.input.value, null);
                });
            });
        }

        var clear = panel.querySelector('[data-lusen-auth-clear]');

        if (clear) clear.addEventListener('click', forgetTokens);

        var note = panel.querySelector('[data-lusen-auth-note]');

        if (note) {
            note.textContent = policy === 'none'
                ? 'Kept in this page only: reloading asks again.'
                : (policy === 'local'
                    ? 'Kept in this browser tab, or on this browser if you ask it to remember. Anything that can run scripts on this site can read it either way, so prefer a credential you can revoke.'
                    : 'Kept in this browser tab and forgotten when you close it. Prefer a credential you can revoke.');
        }

        // Switching server switches which credentials are in play; showing the
        // previous host's token against the new one would be a quiet way to
        // send it somewhere it does not belong.
        var select = document.querySelector('[data-lusen-server]');

        if (select) {
            select.addEventListener('change', function () {
                tokenFields.forEach(function (field) {
                    field.input.value = readToken(field.header);
                });
            });
        }

        panel.hidden = false;
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
    function storedTab(kind) {
        try { return localStorage.getItem('lusen-tab-' + kind); } catch (e) { return null; }
    }

    var snippetId = 0;

    function initTabs() {
        document.querySelectorAll('[data-lusen-tabs]').forEach(function (set) {
            // Languages and status codes are both tab sets and remember
            // separately: picking a 404 must not be read back as a request
            // language on the next page.
            var kind = set.getAttribute('data-lusen-tabs') || 'snippet';
            var preferred = storedTab(kind);

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

                        try { localStorage.setItem('lusen-tab-' + kind, name); } catch (e) {}
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

    /* -------------------------------------------------------------- try it */

    /*
     | Sends the request the page is documenting, from the page.
     |
     | There is no proxy: the call goes straight from the reader's browser to
     | the API, which is the only thing a package of flat files can honestly
     | do. That makes CORS the whole story, so the failure it produces is
     | handled as carefully as the success - a browser reports a blocked
     | cross-origin response as an indistinguishable "Failed to fetch", and a
     | reader who sees that concludes the API is down.
     |
     | The request is read from the JSON the page carries, which is the same
     | model the printed example was rendered from. Nothing here re-derives
     | what a parameter or a body should contain.
     */
    function element(tag, className, text) {
        var node = document.createElement(tag);

        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;

        return node;
    }

    function initTryIt() {
        if (!window.fetch) return;

        var options = window.lusenTryIt || { credentials: false, persist: 'session' };

        document.querySelectorAll('[data-lusen-try]').forEach(function (mount) {
            var source = mount.querySelector('[data-lusen-request]');
            var open = mount.querySelector('[data-lusen-try-open]');
            var request;

            try {
                request = JSON.parse(source.textContent);
            } catch (e) {
                return;
            }

            var panel = null;

            open.hidden = false;
            open.addEventListener('click', function () {
                if (!panel) panel = build(mount, request, options);

                panel.repaint();
                show(panel);
            });
        });
    }

    function show(panel) {
        if (panel.node.showModal) {
            panel.node.showModal();
            document.body.classList.add('lusen-locked');
        } else {
            panel.node.hidden = false;
        }

        var first = panel.node.querySelector('.lusen-try-input');

        if (first) first.focus();
    }

    function build(mount, request, options) {
        // <dialog> where it exists: it is the one element that traps focus,
        // closes on Escape and dims the page behind it without any of that
        // being written here. Where it does not, the same panel opens in place
        // under the button, which is what this used to do for everyone.
        var modal = typeof HTMLDialogElement === 'function';
        var panel = element(modal ? 'dialog' : 'div', 'lusen-try-panel');

        if (!modal) panel.hidden = true;

        var head = element('div', 'lusen-try-head');
        head.appendChild(element('span', 'lusen-try-method', request.method));
        head.appendChild(element('code', 'lusen-try-path', request.path));

        var close = element('button', 'lusen-try-close', 'Close');
        close.type = 'button';
        close.setAttribute('aria-label', 'Close');
        close.addEventListener('click', function () {
            if (panel.close) panel.close();
            else panel.hidden = true;
        });

        head.appendChild(close);
        panel.appendChild(head);

        // Escape and the close button both fire this, so the page is never
        // left scroll-locked behind a dialog that is no longer there.
        panel.addEventListener('close', function () {
            document.body.classList.remove('lusen-locked');
        });

        // Clicking the dim area outside the dialog is a dismissal everywhere
        // else; the target is the dialog itself only when the click missed
        // everything inside it.
        panel.addEventListener('click', function (event) {
            if (event.target === panel && panel.close) panel.close();
        });

        /*
         | What a reader has to know before they send, repeated here because
         | the dialog covers the page that says it: what this operation is,
         | which host it goes to, what it costs against a rate limit, whether
         | it needs credentials, and whether it is deprecated. Anything left
         | out is something they have to close the dialog to go and read.
         */
        var notes = element('div', 'lusen-try-notes');

        if (request.title) notes.appendChild(element('p', 'lusen-try-title', request.title));

        var facts = element('ul', 'lusen-try-facts');

        function fact(label, value, tone) {
            var li = element('li', tone ? 'lusen-try-fact lusen-try-' + tone : 'lusen-try-fact');
            li.appendChild(element('span', 'lusen-try-fact-label', label));
            li.appendChild(element('span', 'lusen-try-fact-value', value));
            facts.appendChild(li);

            return li;
        }

        var host = fact('Sends to', request.baseUrl || location.origin);

        fact('Authentication', request.auth
            ? (request.auth.scheme + ' · ' + request.auth.headers.join(', '))
            : 'None required');

        if (request.rateLimit) fact('Rate limit', request.rateLimit);
        if (request.deprecated) fact('Deprecated', 'This operation is on its way out', 'warn');

        notes.appendChild(facts);
        panel.appendChild(notes);

        var columns = element('div', 'lusen-try-columns');
        var form = element('form', 'lusen-try-form');
        var inputs = [];
        var auth = [];
        var body = null;

        function field(label, hint, description) {
            var wrap = element('label', 'lusen-try-field');
            wrap.appendChild(element('span', 'lusen-try-label', label));

            if (hint) wrap.appendChild(element('span', 'lusen-try-hint', hint));
            // The description is the reason a reader knows what to type here,
            // and it is on the page they cannot see while this is open.
            if (description) wrap.appendChild(element('span', 'lusen-try-note', description));

            return wrap;
        }

        // Everything the reader can fill in, in the order the page lists it.
        request.fields.forEach(function (spec) {
            var wrap = field(
                spec.name,
                spec.in + ' · ' + spec.type + (spec.required ? ' · required' : ' · optional'),
                spec.description
            );
            var input;

            if (spec.enum && spec.enum.length > 0) {
                input = element('select', 'lusen-try-input');

                if (!spec.required) input.appendChild(element('option', null, ''));

                spec.enum.forEach(function (value) {
                    var option = element('option', null, value);
                    option.value = value;
                    input.appendChild(option);
                });
            } else {
                input = element('input', 'lusen-try-input');
                input.type = 'text';
                input.autocomplete = 'off';
                input.placeholder = spec.type;
            }

            input.value = spec.value;
            wrap.appendChild(input);
            form.appendChild(wrap);

            inputs.push({ spec: spec, input: input });
        });

        // One field per header the scheme actually requires, so a client id
        // and client secret pair gets both rather than one box labelled token.
        // They share the sidebar's store, so a token typed once is already
        // here, and one changed here is there.
        if (request.auth) {
            request.auth.headers.forEach(function (header) {
                var wrap = field(header, request.auth.scheme, 'Shared with every request you send from these docs.');
                var input = element('input', 'lusen-try-input');

                input.type = 'password';
                input.autocomplete = 'off';
                input.placeholder = header === 'Authorization' && request.auth.scheme === 'bearer'
                    ? 'token'
                    : 'value';

                wrap.appendChild(input);
                form.appendChild(wrap);
                bindToken(header, input);
                auth.push({ header: header, input: input });
            });
        }

        if (request.body) {
            var wrap = field('Body', 'json', 'Sent as the request body. It has to parse as JSON.');
            body = element('textarea', 'lusen-try-input lusen-try-body');
            body.rows = Math.min(18, JSON.stringify(request.body, null, 2).split('\n').length);
            body.spellcheck = false;
            body.value = JSON.stringify(request.body, null, 2);
            wrap.appendChild(body);
            form.appendChild(wrap);
        }

        var send = element('button', 'lusen-try-send', 'Send');
        send.type = 'submit';
        form.appendChild(send);

        /*
         | The call as it stands, updated as the fields are edited, so nobody
         | has to press send to find out what pressing send would do. The token
         | is masked: this is here to be read, and the copy button on the page
         | already hands over a command with a placeholder in it.
         */
        var preview = element('div', 'lusen-try-preview');
        var previewHead = element('div', 'lusen-code-head');
        previewHead.appendChild(element('span', 'lusen-code-label', 'What will be sent'));

        var previewBody = element('pre', 'lusen-code-body');
        var previewCode = element('code');
        previewBody.appendChild(previewCode);

        var previewFigure = element('figure', 'lusen-code');
        previewFigure.appendChild(previewHead);
        previewFigure.appendChild(previewBody);
        preview.appendChild(previewFigure);

        var handle = { inputs: inputs, auth: auth, body: body };

        function repaint() {
            var url = buildUrl(request, inputs);
            var headers = buildHeaders(request, handle, options);
            var lines = ['curl -X ' + request.method + " '" + url + "'"];

            Object.keys(headers).forEach(function (name) {
                var secret = request.auth && request.auth.headers.indexOf(name) !== -1;

                lines.push("  -H '" + name + ': ' + (secret ? mask(headers[name]) : headers[name]) + "'");
            });

            if (body) lines.push("  -d '" + body.value.replace(/\s+/g, ' ').slice(0, 400) + "'");

            previewCode.textContent = lines.join(' \\\n');
            host.querySelector('.lusen-try-fact-value').textContent = baseUrl(request);
        }

        form.addEventListener('input', repaint);

        columns.appendChild(form);
        columns.appendChild(preview);
        panel.appendChild(columns);

        var status = element('p', 'lusen-try-status');
        status.hidden = true;

        var output = element('div', 'lusen-try-output');
        output.hidden = true;

        panel.appendChild(status);
        panel.appendChild(output);
        mount.appendChild(panel);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            perform(request, options, handle, send, status, output);
        });

        repaint();

        return { node: panel, repaint: repaint };
    }

    /* A token is shown, never printed: the length is a hint, the value is not. */
    function mask(value) {
        var visible = /^Bearer /i.test(value) ? 'Bearer ' : '';

        return visible + new Array(Math.min(12, Math.max(4, value.length - visible.length)) + 1).join('•');
    }

    /*
     | The base URL comes from the switcher when the page has one, so trying a
     | request against the sandbox is the same act as reading the sandbox
     | example.
     */
    function baseUrl(request) {
        var select = document.querySelector('[data-lusen-server]');

        return (select && select.value) || request.baseUrl;
    }

    function buildUrl(request, inputs) {
        var path = request.path;
        var query = [];

        inputs.forEach(function (entry) {
            var value = entry.input.value.trim();

            if (entry.spec.in === 'path') {
                path = path
                    .replace('{' + entry.spec.name + '?}', encodeURIComponent(value))
                    .replace('{' + entry.spec.name + '}', encodeURIComponent(value));
            } else if (entry.spec.in === 'query' && value !== '') {
                query.push(encodeURIComponent(entry.spec.name) + '=' + encodeURIComponent(value));
            }
        });

        // An optional segment nobody filled in is not part of the URL.
        path = path.replace(/\/\{[^}]*\?\}/g, '').replace(/\{[^}]*\?\}/g, '');

        return baseUrl(request) + path + (query.length ? '?' + query.join('&') : '');
    }

    function buildHeaders(request, form, options) {
        var headers = {};

        // The example's headers minus its placeholder credentials: those are
        // the reader's to supply, and sending YOUR_TOKEN would earn a 401 that
        // looks like the API's fault.
        Object.keys(request.headers).forEach(function (name) {
            var isAuth = request.auth && request.auth.headers.indexOf(name) !== -1;

            if (!isAuth) headers[name] = request.headers[name];
        });

        form.inputs.forEach(function (entry) {
            var value = entry.input.value.trim();

            if (entry.spec.in === 'header' && value !== '') headers[entry.spec.name] = value;
        });

        form.auth.forEach(function (entry) {
            var value = entry.input.value.trim();

            if (value === '') return;

            var bearer = entry.header === 'Authorization' && request.auth.scheme === 'bearer';

            headers[entry.header] = bearer && !/^Bearer /i.test(value) ? 'Bearer ' + value : value;
        });

        return headers;
    }

    function perform(request, options, form, send, status, output) {
        var url = buildUrl(request, form.inputs);
        var init = {
            method: request.method,
            headers: buildHeaders(request, form, options),
        };

        if (options.credentials) init.credentials = 'include';

        if (form.body) {
            try {
                init.body = JSON.stringify(JSON.parse(form.body.value));
            } catch (e) {
                report(status, output, 'bad', 'The body is not valid JSON, so nothing was sent.', null);
                return;
            }
        }

        var controller = window.AbortController ? new AbortController() : null;

        if (controller) {
            init.signal = controller.signal;
            setTimeout(function () { controller.abort(); }, 30000);
        }

        send.disabled = true;
        send.textContent = 'Sending…';
        status.hidden = false;
        status.className = 'lusen-try-status';
        status.textContent = request.method + ' ' + url;
        output.hidden = true;

        var started = (window.performance || Date).now();

        fetch(url, init).then(function (response) {
            return response.text().then(function (text) {
                render(status, output, response, text, Math.round((window.performance || Date).now() - started));
            });
        }).catch(function (error) {
            explain(status, output, error, url);
        }).then(function () {
            send.disabled = false;
            send.textContent = 'Send';
        });
    }

    function render(status, output, response, text, ms) {
        var tone = response.status < 300 ? 'ok' : (response.status >= 500 ? 'bad' : 'warn');
        var size = new Blob([text]).size;

        status.textContent = '';
        status.className = 'lusen-try-status';
        status.appendChild(element('span', 'lusen-status lusen-status-' + tone, String(response.status)));
        status.appendChild(element('span', 'lusen-try-meta', response.statusText + ' · ' + ms + ' ms · ' + size + ' B'));
        status.hidden = false;

        var pretty = text;

        try {
            pretty = JSON.stringify(JSON.parse(text), null, 2);
        } catch (e) {}

        output.textContent = '';

        var figure = element('figure', 'lusen-code');
        var head = element('figcaption', 'lusen-code-head');
        head.appendChild(element('span', 'lusen-code-label', 'Response'));

        var copy = element('button', 'lusen-copy', 'Copy');
        copy.type = 'button';
        copy.addEventListener('click', function () {
            if (!navigator.clipboard) return;

            navigator.clipboard.writeText(pretty).then(function () { flash(copy, 'Copied'); });
        });

        head.appendChild(copy);

        var pre = element('pre', 'lusen-code-body');
        pre.appendChild(element('code', null, pretty === '' ? '(no body)' : pretty));

        figure.appendChild(head);
        figure.appendChild(pre);
        output.appendChild(figure);
        output.hidden = false;
    }

    /*
     | A blocked cross-origin request and an API that is genuinely unreachable
     | look identical from here - both are a TypeError with no response - so
     | the message names the likelier one and says exactly what would fix it,
     | with the origins filled in. Guessing wrong costs a sentence; saying
     | "Failed to fetch" costs an afternoon.
     */
    function explain(status, output, error, url) {
        var target = '';

        try { target = new URL(url, location.href).origin; } catch (e) {}

        var aborted = error && error.name === 'AbortError';
        var crossOrigin = target !== '' && target !== location.origin;

        var message = aborted
            ? 'The request was still running after 30 seconds and was cancelled.'
            : (crossOrigin
                ? target + ' did not allow a request from ' + location.origin + '. '
                    + 'That is what a browser reports when the response carries no '
                    + 'Access-Control-Allow-Origin header for this origin - the API may well be fine. '
                    + 'The cURL example above is unaffected, and always works.'
                : 'The request did not complete: ' + (error && error.message ? error.message : 'unknown error') + '.');

        report(status, output, aborted ? 'warn' : 'bad', message, crossOrigin && !aborted ? target : null);
    }

    function report(status, output, tone, message, origin) {
        status.textContent = '';
        status.className = 'lusen-try-status lusen-try-' + tone;
        status.appendChild(element('span', null, message));
        status.hidden = false;

        output.textContent = '';

        if (origin) {
            var figure = element('figure', 'lusen-code');
            var head = element('figcaption', 'lusen-code-head');
            head.appendChild(element('span', 'lusen-code-label', 'What the API has to send'));

            var pre = element('pre', 'lusen-code-body');
            pre.appendChild(element('code', null,
                'Access-Control-Allow-Origin: ' + location.origin + '\n'
                + 'Access-Control-Allow-Headers: authorization, content-type'));

            figure.appendChild(head);
            figure.appendChild(pre);
            output.appendChild(figure);
            output.hidden = false;
        } else {
            output.hidden = true;
        }
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
        initTryIt();
        initAuth();
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
