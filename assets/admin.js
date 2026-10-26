(function () {
    var data = window.keyWardenAdmin || { templates: {}, i18n: {} };

    // Profile editor.
    var form = document.querySelector('.key-warden-profile-form');
    if (form) {
        var textarea = form.querySelector('.key-warden-rules-input');
        var nameInput = form.querySelector('input[name="name"]');
        var getMode = function () {
            var checked = form.querySelector('.key-warden-mode:checked');
            return checked ? checked.value : 'allowlist';
        };
        var setMode = function (mode) {
            var radio = form.querySelector('.key-warden-mode[value="' + mode + '"]');
            if (radio) radio.checked = true;
        };
        var lines = function () {
            return textarea.value.split('\n').map(function (l) { return l.trim(); });
        };
        var append = function (newLines) {
            var existing = lines();
            var added = newLines.filter(function (r) { return r.charAt(0) === '#' || existing.indexOf(r) === -1; });
            if (!added.length) return;
            var current = textarea.value.replace(/\s+$/, '');
            textarea.value = (current ? current + '\n' : '') + added.join('\n');
            textarea.rows = Math.max(textarea.rows, textarea.value.split('\n').length + 1);
            textarea.dispatchEvent(new Event('input'));
        };

        form.querySelector('.key-warden-template-add').addEventListener('click', function () {
            var select = form.querySelector('.key-warden-template-select');
            var template = data.templates[select.value];
            if (!template) return;
            var wasEmpty = lines().join('') === '';
            append(['# ' + template.name].concat(template.rules));
            if (wasEmpty) {
                setMode(template.mode);
                if (nameInput.value.trim() === '') nameInput.value = template.name;
                var readOnly = form.querySelector('input[name="abilities_read_only"]');
                if (readOnly) readOnly.checked = template.abilities === 'read';
            } else if (getMode() !== template.mode) {
                window.alert(data.i18n.modeMismatch);
            }
            select.value = '';
        });

        document.querySelectorAll('.key-warden-route-add').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault(); // Don't toggle the <details> when clicking + in a summary.
                append([btn.getAttribute('data-route')]);
                btn.classList.add('is-added');
            });
        });

        // Route filter: show matching routes and open their groups.
        var filter = document.querySelector('.key-warden-route-filter');
        if (filter) filter.addEventListener('input', function () {
            var term = filter.value.trim().toLowerCase();
            document.querySelectorAll('.key-warden-ns').forEach(function (ns) {
                var any = false;
                ns.querySelectorAll('li').forEach(function (li) {
                    var hit = !term || li.textContent.toLowerCase().indexOf(term) !== -1;
                    li.hidden = !hit;
                    any = any || hit;
                });
                ns.hidden = !any;
                ns.open = !!term && any;
            });
        });

        // Live tester: asks the server, so it uses the exact same matching code as enforcement.
        var methodSelect = document.querySelector('.key-warden-test-method');
        var routeInput = document.querySelector('.key-warden-test-route');
        var result = document.querySelector('.key-warden-result');
        var testTimer = null;
        var runTest = function () {
            clearTimeout(testTimer);
            testTimer = setTimeout(function () {
                if (!routeInput.value.trim()) { result.hidden = true; return; }
                var body = new URLSearchParams({
                    action: 'key_warden_test_route', _ajax_nonce: data.nonce, rules: textarea.value,
                    mode: getMode(), method: methodSelect.value, route: routeInput.value.trim()
                });
                fetch(data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (json) {
                        if (!json || !json.success) return;
                        result.hidden = false;
                        result.className = 'key-warden-result ' + (json.data.allowed ? 'key-warden-allowed' : 'key-warden-blocked');
                        result.textContent = json.data.message;
                    });
            }, 250);
        };
        if (routeInput) {
            [routeInput, textarea].forEach(function (el) { el.addEventListener('input', runTest); });
            methodSelect.addEventListener('change', runTest);
            form.querySelectorAll('.key-warden-mode').forEach(function (r) { r.addEventListener('change', runTest); });
        }
    }

    // Show the role/people pickers only when "restricted" is chosen.
    document.querySelectorAll('input[data-key-warden-toggle]').forEach(function (radio) {
        var type = radio.getAttribute('data-key-warden-toggle');
        var sync = function () {
            var checked = document.querySelector('input[data-key-warden-toggle="' + type + '"]:checked');
            var show = checked && checked.value === 'restricted';
            document.querySelectorAll('.key-warden-restricted-' + type).forEach(function (row) {
                row.style.display = show ? '' : 'none';
            });
        };
        radio.addEventListener('change', sync);
        sync();
    });

    // Searchable chip input for picking people.
    document.querySelectorAll('.key-warden-chips').forEach(function (box) {
        var input = box.querySelector('.key-warden-chips-input');
        var list = box.querySelector('.key-warden-chips-results');
        var name = box.getAttribute('data-name');
        var results = [];
        var active = -1;
        var timer = null;
        var lastTerm = '';

        var selectedIds = function () {
            return Array.prototype.map.call(box.querySelectorAll('input[type="hidden"]'), function (i) { return i.value; });
        };

        var close = function () {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            active = -1;
        };

        var render = function () {
            list.innerHTML = '';
            if (!results.length) {
                var empty = document.createElement('li');
                empty.className = 'key-warden-chips-empty';
                empty.textContent = data.i18n.noUsers;
                list.appendChild(empty);
            }
            results.forEach(function (user, i) {
                var li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.className = i === active ? 'is-active' : '';
                var label = document.createElement('span');
                label.textContent = user.label;
                var email = document.createElement('span');
                email.className = 'key-warden-muted';
                email.textContent = user.email;
                li.appendChild(label);
                li.appendChild(email);
                li.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    add(user);
                });
                list.appendChild(li);
            });
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        };

        var add = function (user) {
            if (selectedIds().indexOf(String(user.id)) !== -1) return;
            var chip = document.createElement('span');
            chip.className = 'key-warden-chip';
            chip.title = user.email;
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = name;
            hidden.value = user.id;
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'key-warden-chip-remove';
            remove.setAttribute('aria-label', data.i18n.remove + ' ' + user.label);
            remove.innerHTML = '&times;';
            chip.appendChild(hidden);
            chip.appendChild(document.createTextNode(user.label));
            chip.appendChild(remove);
            box.insertBefore(chip, input);
            input.value = '';
            lastTerm = '';
            close();
            input.focus();
        };

        var search = function () {
            var term = input.value.trim();
            if (term === lastTerm) return;
            lastTerm = term;
            if (!term) { close(); return; }
            var params = new URLSearchParams({ action: 'key_warden_user_search', _ajax_nonce: data.nonce, term: term });
            selectedIds().forEach(function (id) { params.append('exclude[]', id); });
            fetch(data.ajaxUrl + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (term !== input.value.trim()) return; // A newer search is on its way.
                    results = json && json.success ? json.data : [];
                    active = results.length ? 0 : -1;
                    render();
                })
                .catch(close);
        };

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(search, 200);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (list.hidden || !results.length) return;
                e.preventDefault();
                active = (active + (e.key === 'ArrowDown' ? 1 : -1) + results.length) % results.length;
                render();
            } else if (e.key === 'Enter') {
                e.preventDefault(); // Never submit the form from here.
                if (!list.hidden && results[active]) add(results[active]);
            } else if (e.key === 'Escape') {
                close();
            } else if (e.key === 'Backspace' && input.value === '') {
                var chips = box.querySelectorAll('.key-warden-chip');
                if (chips.length) chips[chips.length - 1].remove();
            }
        });

        input.addEventListener('blur', close);
        box.addEventListener('click', function (e) {
            var btn = e.target.closest('.key-warden-chip-remove');
            if (btn) {
                btn.parentNode.remove();
                input.focus();
            } else if (e.target === box) {
                input.focus();
            }
        });
    });

    document.addEventListener('click', function (e) {
        var el = e.target.closest('.key-warden-confirm');
        if (el && !window.confirm(data.i18n.confirm)) e.preventDefault();
    });
})();
