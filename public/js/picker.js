/**
 * Field Lock - configuration screen.
 *
 * Loads the real GLPI form for the selected itemtype inside a same-origin
 * iframe, then attaches click handlers straight onto the iframe's fields. The
 * form itself is untouched: everything the admin sees here is added from the
 * parent document.
 */
(function () {
    'use strict';

    var cfg = JSON.parse(document.getElementById('fieldlock-config').textContent);

    var state = {
        profiles: [],
        group: Object.keys(cfg.groups)[0] || null,
        entry: null,      // the selected pill's descriptor
        itemtype: null,   // itemtype being configured
        url: null,        // form URL of the host that renders it
        itemId: '',
        locks: {},        // {itemtype: {field: {mode, profiles}}}
        csrf: cfg.csrf_token,
    };

    var els = {
        mode: document.getElementById('fieldlock-mode'),
        groupTabs: document.getElementById('fieldlock-group-tabs'),
        pills: document.getElementById('fieldlock-itemtypes'),
        counter: document.getElementById('fieldlock-counter'),
        status: document.getElementById('fieldlock-status'),
        empty: document.getElementById('fieldlock-empty'),
        frameWrap: document.getElementById('fieldlock-frame-wrap'),
        frame: document.getElementById('fieldlock-frame'),
        reload: document.getElementById('fieldlock-reload'),
        clear: document.getElementById('fieldlock-clear'),
        itemId: document.getElementById('fieldlock-item-id'),
    };

    /**
     * The profiles <select>.
     *
     * GLPI builds the multiple dropdown after this script runs and emits a
     * hidden input of the same base name next to it, so it is resolved on
     * demand and the tag is part of the selector.
     */
    function profilesEl() {
        return document.querySelector('select[name="fieldlock_profiles[]"]');
    }

    // Must stay in sync with PluginFieldlockLock::NEVER_FILTERED and with the
    // same list in fieldlock.js: offering a field here that neither the server
    // nor the runtime will ever act on would create a lock that does nothing.
    var SKIPPED_NAMES = /^(_glpi_csrf_token|_glpi_simple_form|_read_date_mod|id|entities_id|is_recursive|add|update|purge|delete|restore)$/;
    var SKIPPED_TYPES = /^(hidden|submit|button|reset|image)$/;

    function normalizeField(name) {
        return String(name || '').trim().replace(/(\[[^\]]*\])+$/, '');
    }

    /**
     * The token to send with the next write.
     *
     * GLPI 10 consumes a CSRF token on use, so every response hands back a
     * fresh one and it is used for the following request. GLPI 11 preserves
     * the token for XHR, where this is simply a no-op.
     */
    function csrfToken() {
        if (state.csrf) {
            return state.csrf;
        }
        var meta = document.querySelector('meta[property="glpi:csrf_token"]');
        return meta ? meta.content : '';
    }

    function setStatus(text, isError) {
        els.status.textContent = text || '';
        els.status.className = 'fieldlock-status ' + (isError ? 'text-danger' : 'text-muted');
    }

    function post(payload) {
        var body = new URLSearchParams();
        // Sent both ways on purpose: GLPI 11's kernel reads the header on XHR,
        // GLPI 10's Session::checkCSRF() reads the request body.
        body.append('_glpi_csrf_token', csrfToken());
        Object.keys(payload).forEach(function (key) {
            if (Array.isArray(payload[key])) {
                payload[key].forEach(function (v) { body.append(key + '[]', v); });
            } else {
                body.append(key, payload[key]);
            }
        });

        return fetch(cfg.ajax_base + '/setlock.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': csrfToken(),
            },
            body: body.toString(),
        }).then(function (res) {
            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }
            return res.json();
        }).then(function (data) {
            if (data && data.csrf_token) {
                state.csrf = data.csrf_token;
            }
            return data;
        });
    }

    function fetchLocks() {
        var params = new URLSearchParams();
        state.profiles.forEach(function (p) { params.append('profiles[]', p); });

        return fetch(cfg.ajax_base + '/getlocks.php?' + params.toString(), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (res) { return res.json(); });
    }

    /* ---------------------------------------------------------------- UI --- */

    function readProfiles() {
        var el = profilesEl();
        if (!el) {
            return [];
        }
        return Array.prototype.slice.call(el.selectedOptions || [])
            .map(function (o) { return parseInt(o.value, 10); })
            .filter(function (v) { return !isNaN(v) && v > 0; });
    }

    function renderPills() {
        els.pills.innerHTML = '';
        (cfg.groups[state.group] || []).forEach(function (entry) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm ' + (entry.itemtype === state.itemtype ? 'btn-primary' : 'btn-outline-secondary');
            btn.textContent = entry.label;
            if (entry.needs_id) {
                // Flag the pills that cannot be previewed without an item.
                btn.textContent += ' ↳';
                btn.title = entry.host_label;
            }
            btn.addEventListener('click', function () {
                selectItemtype(entry);
            });
            els.pills.appendChild(btn);
        });
    }

    /** Counts what is locked among the fields actually on screen. */
    function updateCounter() {
        var doc = els.frame.contentDocument;
        var n = doc
            ? Array.prototype.filter.call(
                doc.querySelectorAll('.fieldlock-pick-on, .fieldlock-pick-partial'),
                function (el) { return el.offsetParent !== null; }
            ).length
            : 0;
        els.counter.textContent = n + ' ' + cfg.i18n.locked;
        els.counter.className = 'badge ' + (n > 0 ? 'bg-warning text-dark' : 'bg-secondary');
    }

    function refreshAvailability() {
        var ready = state.profiles.length > 0 && state.itemtype !== null;
        els.empty.classList.toggle('d-none', ready);
        els.frameWrap.classList.toggle('d-none', !ready);
        if (!ready) {
            els.empty.textContent = state.profiles.length === 0
                ? cfg.i18n.no_profile
                : cfg.i18n.loading;
        }
        return ready;
    }

    function selectItemtype(entry) {
        state.entry = entry;
        state.itemtype = entry.itemtype;
        state.url = entry.url;
        renderPills();
        loadPreview();
    }

    function loadPreview() {
        if (!refreshAvailability()) {
            return;
        }

        state.itemId = (els.itemId.value || '').trim();

        if (state.entry && state.entry.needs_id && !state.itemId) {
            els.empty.textContent = cfg.i18n.needs_id.replace('%s', state.entry.host_label);
            els.empty.classList.remove('d-none');
            els.frameWrap.classList.add('d-none');
            els.itemId.focus();
            return;
        }

        setStatus(cfg.i18n.loading);
        var url = cfg.root_doc + state.url;
        url += (url.indexOf('?') === -1 ? '?' : '&') + '_fieldlock_pick=1';
        if (state.itemId) {
            url += '&id=' + encodeURIComponent(state.itemId);
        }
        els.frame.src = url;
    }

    /* ------------------------------------------------------------ picking --- */

    /* Layout chrome that is not part of the form being configured. */
    var CHROME = '.navbar, .sidebar, aside, header, footer, [role="search"], .modal, ' +
        '.saved-searches-panel, .fuzzysearch, .search-form, .toast, .tab-content-nav';

    function scanRoot(doc) {
        return doc.querySelector('#page') || doc.querySelector('main') || doc.body;
    }

    function fieldsIn(doc) {
        var root = scanRoot(doc);
        if (!root) {
            return [];
        }
        return Array.prototype.slice
            .call(root.querySelectorAll('input[name], select[name], textarea[name]'))
            .filter(function (el) {
                var name = normalizeField(el.getAttribute('name'));
                if (!name || SKIPPED_NAMES.test(name)) {
                    return false;
                }
                if (el.tagName === 'INPUT' && SKIPPED_TYPES.test((el.getAttribute('type') || 'text').toLowerCase())) {
                    return false;
                }
                if (el.classList.contains('select2-search__field') || el.closest(CHROME)) {
                    return false;
                }
                return true;
            });
    }

    /**
     * Smallest wrapper that still includes the field's label.
     *
     * Walks inwards past any wrapper another field already claimed, because
     * GLPI groups several inputs (actors, SLA pairs) inside one `.form-field`
     * row and the last one would otherwise overwrite the others.
     */
    function containerFor(el) {
        var candidates = [
            el.closest('.form-field'),
            el.closest('.field-container'),
            el.parentElement,
            el,
        ];

        for (var i = 0; i < candidates.length; i++) {
            if (candidates[i] && !candidates[i].hasAttribute('data-fieldlock-name')) {
                return candidates[i];
            }
        }
        return el;
    }

    /**
     * The itemtype a form writes to, from its action URL.
     *
     * A page can host several: an existing Ticket renders the ITILFollowup,
     * TicketTask, ITILSolution and TicketValidation forms in its timeline, and
     * they all have a `content` field. Attributing by form is what keeps those
     * four apart instead of collapsing them onto the ticket's own description.
     *
     * Returns null rather than guessing when the action names no known
     * itemtype, so fields of internal forms are left alone.
     */
    function itemtypeForForm(form) {
        var action = form && form.getAttribute('action');
        if (!action) {
            return null;
        }
        var match = /\/([a-z0-9_]+)(?:\.form)?\.php/i.exec(action.split('?')[0]);
        if (!match) {
            return null;
        }
        var slug = match[1].toLowerCase();
        for (var i = 0; i < cfg.itemtypes.length; i++) {
            if (cfg.itemtypes[i].toLowerCase() === slug) {
                return cfg.itemtypes[i];
            }
        }
        return null;
    }

    /** Paint one field according to the locks currently stored. */
    function paintField(target) {
        var itemtype = target.getAttribute('data-fieldlock-itemtype');
        var name = target.getAttribute('data-fieldlock-name');
        var lock = (state.locks[itemtype] || {})[name];

        target.classList.remove('fieldlock-pick-on', 'fieldlock-pick-partial');

        if (!lock) {
            target.removeAttribute('data-fieldlock-mode');
            return;
        }
        var everyProfile = state.profiles.every(function (p) {
            return lock.profiles.indexOf(p) !== -1;
        });
        target.classList.add(everyProfile ? 'fieldlock-pick-on' : 'fieldlock-pick-partial');
        target.setAttribute('data-fieldlock-mode', lock.mode);
        if (!everyProfile) {
            target.setAttribute('title', cfg.i18n.partial);
        }
    }

    function repaint(doc) {
        Array.prototype.forEach.call(doc.querySelectorAll('[data-fieldlock-name]'), paintField);
        updateCounter();
    }

    function toggleField(doc, target) {
        var itemtype = target.getAttribute('data-fieldlock-itemtype');
        var name = target.getAttribute('data-fieldlock-name');
        var locked = !!(state.locks[itemtype] || {})[name];
        setStatus('…');

        post({
            action: locked ? 'unlock' : 'lock',
            itemtype: itemtype,
            field: name,
            mode: els.mode.value,
            profiles: state.profiles,
        }).then(function (data) {
            state.locks = data.locks || {};
            repaint(doc);
            setStatus(cfg.i18n.saved);
        }).catch(function (err) {
            setStatus(cfg.i18n.error + ' (' + err.message + ')', true);
        });
    }

    /**
     * Show only the block of the itemtype being configured.
     *
     * A hosted itemtype is previewed through its parent's form, which also
     * renders the parent's own fields and the entire timeline. GLPI keeps each
     * child's form in a collapsed `#new-<Itemtype>-block`, so isolating means
     * expanding that one and letting the stylesheet hide the rest.
     *
     * Re-applied on every rescan because the timeline renders after load.
     */
    function applyIsolation(doc) {
        var entry = state.entry;

        if (!entry || !entry.needs_id) {
            doc.body.removeAttribute('data-fieldlock-isolate');
            return;
        }

        var block = doc.getElementById('new-' + state.itemtype + '-block');
        if (!block) {
            // Timeline not rendered yet; the next rescan will catch it.
            return;
        }

        doc.body.setAttribute('data-fieldlock-isolate', state.itemtype);
        block.classList.add('show', 'fieldlock-isolated');
    }

    /**
     * Tag every not-yet-tagged field as a pick target.
     *
     * Runs repeatedly: GLPI renders timelines, tabs and accordions after load,
     * so fields keep appearing well past the iframe's `load` event.
     */
    function markFields(doc) {
        var seen = {};

        Array.prototype.forEach.call(doc.querySelectorAll('[data-fieldlock-name]'), function (node) {
            seen[node.getAttribute('data-fieldlock-itemtype') + '.' + node.getAttribute('data-fieldlock-name')] = true;
        });

        fieldsIn(doc).forEach(function (el) {
            var itemtype = itemtypeForForm(el.closest('form'));
            if (!itemtype) {
                return;
            }
            var name = normalizeField(el.getAttribute('name'));
            var key = itemtype + '.' + name;
            if (seen[key]) {
                return;
            }
            seen[key] = true;

            var target = containerFor(el);
            target.classList.add('fieldlock-pick-target');
            target.setAttribute('data-fieldlock-name', name);
            target.setAttribute('data-fieldlock-itemtype', itemtype);

            var foreign = itemtype !== state.itemtype;
            if (foreign) {
                target.classList.add('fieldlock-pick-foreign');
                target.setAttribute('title', cfg.i18n.foreign.replace('%s', itemtype));
            }

            var badge = doc.createElement('span');
            badge.className = 'fieldlock-pick-badge';
            badge.textContent = foreign ? itemtype + '.' + name : name;
            target.appendChild(badge);
        });

        return Object.keys(seen).length;
    }

    /**
     * Turn the loaded form into a picker: clicking any field toggles its lock
     * instead of editing it. The form's own markup is never modified on disk -
     * everything here is added to the iframe document at runtime.
     */
    function preparePicker() {
        var doc = els.frame.contentDocument;
        if (!doc || !doc.body) {
            return;
        }

        var style = doc.createElement('link');
        style.rel = 'stylesheet';
        style.href = cfg.picker_css + '?v=' + encodeURIComponent(cfg.version || '');
        doc.head.appendChild(style);
        doc.body.classList.add('fieldlock-pick-mode');

        var rescan = function () {
            applyIsolation(doc);
            var count = markFields(doc);
            repaint(doc);
            if (count > 0) {
                setStatus('');
                return;
            }
            // No lockable field: either GLPI refused the page, or the itemtype
            // renders nothing useful without an item.
            var refused = doc.querySelector('.alert-danger, .error, [data-glpi-error]');
            if (refused) {
                setStatus(refused.textContent.trim().slice(0, 160), true);
            } else if (state.entry && state.entry.needs_id && !state.itemId) {
                setStatus(cfg.i18n.needs_id.replace('%s', state.entry.host_label), true);
            } else {
                setStatus(cfg.i18n.unsupported, true);
            }
        };

        // One delegated listener beats one per field, and it keeps working for
        // fields GLPI renders after load.
        doc.addEventListener('click', function (event) {
            // Only ever act on a real click. GLPI, Bootstrap, select2 and
            // TinyMCE all dispatch synthetic clicks while the form settles, and
            // acting on those would silently create locks nobody asked for.
            if (!event.isTrusted) {
                return;
            }
            var target = event.target.closest('.fieldlock-pick-target');
            if (!target) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            toggleField(doc, target);
        }, true);

        // Never let the preview navigate away or actually create an item.
        doc.addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopPropagation();
        }, true);

        var pending = null;
        new doc.defaultView.MutationObserver(function () {
            doc.defaultView.clearTimeout(pending);
            pending = doc.defaultView.setTimeout(rescan, 150);
        }).observe(doc.body, { childList: true, subtree: true });

        fetchLocks().then(function (data) {
            state.locks = data.locks || {};
            rescan();
            setStatus('');
        });
    }

    /* ----------------------------------------------------------- wiring --- */

    els.frame.addEventListener('load', function () {
        try {
            preparePicker();
        } catch (err) {
            setStatus(cfg.i18n.error + ' (' + err.message + ')', true);
        }
    });

    Array.prototype.forEach.call(els.groupTabs.querySelectorAll('[data-fieldlock-group]'), function (btn) {
        btn.addEventListener('click', function () {
            Array.prototype.forEach.call(els.groupTabs.querySelectorAll('.nav-link'), function (b) {
                b.classList.remove('active');
            });
            btn.classList.add('active');
            state.group = btn.getAttribute('data-fieldlock-group');
            renderPills();
        });
    });

    // Delegated so the handler survives GLPI building the dropdown late, and
    // bound twice because select2 fires its change through jQuery only.
    document.addEventListener('change', function (event) {
        if (event.target && event.target.matches && event.target.matches('select[name="fieldlock_profiles[]"]')) {
            onProfilesChanged();
        }
    }, true);
    if (window.jQuery) {
        window.jQuery(document).on('change', 'select[name="fieldlock_profiles[]"]', onProfilesChanged);
    }

    function onProfilesChanged() {
        state.profiles = readProfiles();
        if (refreshAvailability() && state.itemtype) {
            fetchLocks().then(function (data) {
                state.locks = data.locks || {};
                if (els.frame.contentDocument) {
                    repaint(els.frame.contentDocument);
                }
            });
        }
        updateCounter();
    }

    els.reload.addEventListener('click', loadPreview);

    els.itemId.addEventListener('change', loadPreview);
    els.itemId.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            loadPreview();
        }
    });

    els.clear.addEventListener('click', function () {
        if (!state.itemtype || state.profiles.length === 0) {
            return;
        }
        if (!window.confirm(cfg.i18n.confirm_wipe)) {
            return;
        }
        post({ action: 'clear', itemtype: state.itemtype, profiles: state.profiles })
            .then(function (data) {
                state.locks = data.locks || {};
                if (els.frame.contentDocument) {
                    repaint(els.frame.contentDocument);
                }
                setStatus(cfg.i18n.saved);
            })
            .catch(function (err) {
                setStatus(cfg.i18n.error + ' (' + err.message + ')', true);
            });
    });

    state.profiles = readProfiles();
    renderPills();
    refreshAvailability();
    updateCounter();
}());
