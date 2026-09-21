/* global jQuery */
/**
 * Field Lock - runtime enforcement.
 *
 * Reads the locks for the active profile from the `fieldlock:locks` meta tag
 * that the plugin injects into <head>, then greys out or hides the matching
 * fields. This layer is cosmetic only: `pre_item_add` / `pre_item_update` on the
 * PHP side is what actually prevents a locked value from being written.
 */
(function () {
    'use strict';

    var PICK_PARAM = '_fieldlock_pick';

    /**
     * Names that are never user-editable form fields.
     * Kept in sync with PluginFieldlockLock::NEVER_FILTERED and picker.js.
     */
    var SKIPPED_NAMES = /^(_glpi_csrf_token|_glpi_simple_form|_read_date_mod|id|entities_id|is_recursive|add|update|purge|delete|restore)$/;
    var SKIPPED_TYPES = /^(hidden|submit|button|reset|image|file)$/;

    function normalizeField(name) {
        return String(name || '').trim().replace(/(\[[^\]]*\])+$/, '');
    }

    function readLocks() {
        var meta = document.querySelector('meta[name="fieldlock:locks"]');
        if (!meta || !meta.content) {
            return {};
        }
        try {
            return JSON.parse(meta.content) || {};
        } catch (e) {
            return {};
        }
    }

    /**
     * Best-effort itemtype for a form, matched case-insensitively against the
     * itemtypes we actually hold locks for.
     *
     * GLPI form actions look like `/front/networkequipment.form.php`, so the
     * slug before `.form.php` is the lowercased class name.
     */
    function itemtypeForForm(form, knownTypes) {
        // A form's own action wins outright. One page hosts several itemtypes -
        // a Ticket's timeline also renders the ITILFollowup, TicketTask and
        // ITILSolution forms, each with its own `content` - so falling back to
        // the page URL for a form that names a different target would apply the
        // wrong itemtype's locks. The page URL is only used for a form that
        // declares no action at all.
        var source = (form && form.getAttribute('action')) || window.location.pathname;

        var match = /\/([a-z0-9_]+)(?:\.form)?\.php/i.exec(source.split('?')[0]);
        if (!match) {
            return null;
        }
        var slug = match[1].toLowerCase();
        for (var j = 0; j < knownTypes.length; j++) {
            if (knownTypes[j].toLowerCase() === slug) {
                return knownTypes[j];
            }
        }
        return null;
    }

    /** The row wrapping a field, used as the target for greying or hiding. */
    function containerFor(el) {
        return el.closest('.form-field') ||
            el.closest('.row') ||
            el.parentElement;
    }

    function isCandidate(el) {
        var name = el.getAttribute('name');
        if (!name) {
            return false;
        }
        if (SKIPPED_NAMES.test(normalizeField(name))) {
            return false;
        }
        if (el.tagName === 'INPUT' && SKIPPED_TYPES.test((el.getAttribute('type') || 'text').toLowerCase())) {
            return false;
        }
        return true;
    }

    function lockElement(el, mode) {
        var container = containerFor(el);

        if (mode === 'hidden') {
            if (container) {
                container.classList.add('fieldlock-hidden');
            } else {
                el.classList.add('fieldlock-hidden');
            }
            el.disabled = true;
            return;
        }

        if (container) {
            container.classList.add('fieldlock-locked');
        }
        el.classList.add('fieldlock-locked-input');

        if (el.tagName === 'SELECT' || el.type === 'checkbox' || el.type === 'radio') {
            // `readonly` is a no-op on these, so disable them. Disabled controls
            // are not submitted, which leaves the stored value untouched.
            el.disabled = true;
        } else {
            el.readOnly = true;
        }
        el.setAttribute('tabindex', '-1');

        // Keep select2 widgets in sync with the underlying control.
        if (window.jQuery && jQuery(el).data('select2')) {
            jQuery(el).trigger('change.select2');
        }
    }

    /** Rich text areas are replaced by TinyMCE, which needs its own call. */
    function lockEditors(lockedNames) {
        if (!window.tinymce || !window.tinymce.editors) {
            return;
        }
        window.tinymce.editors.forEach(function (editor) {
            var name = normalizeField(editor.id || '');
            if (!lockedNames[name]) {
                return;
            }
            try {
                editor.mode.set('readonly');
            } catch (e) {
                /* editor not ready yet; the DOM-level lock still applies */
            }
        });
    }

    function apply(root) {
        var locks = readLocks();
        var knownTypes = Object.keys(locks);
        if (knownTypes.length === 0) {
            return;
        }

        var forms = root.querySelectorAll('form');
        var lockedNames = {};

        Array.prototype.forEach.call(forms, function (form) {
            var itemtype = itemtypeForForm(form, knownTypes);
            if (!itemtype) {
                return;
            }
            var fields = locks[itemtype] || {};

            Array.prototype.forEach.call(form.querySelectorAll('input[name], select[name], textarea[name]'), function (el) {
                if (!isCandidate(el) || el.dataset.fieldlockApplied === '1') {
                    return;
                }
                var mode = fields[normalizeField(el.getAttribute('name'))];
                if (!mode) {
                    return;
                }
                el.dataset.fieldlockApplied = '1';
                lockedNames[normalizeField(el.getAttribute('name'))] = mode;
                lockElement(el, mode);
            });
        });

        lockEditors(lockedNames);
    }

    function start() {
        if (new URLSearchParams(window.location.search).has(PICK_PARAM)) {
            // The configuration preview must show the form unlocked so the admin
            // can still see and click every field.
            document.body.classList.add('fieldlock-picking');
            return;
        }

        apply(document);

        // GLPI injects tabs, modals and timeline forms after load.
        var observer = new MutationObserver(function () {
            apply(document);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
