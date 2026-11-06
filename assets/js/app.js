/**
 * app.js — shared UI behaviour and small helpers used by every screen.
 * Server-side validation still runs for everything handled here.
 */
(function (window, document) {
    'use strict';

    var SWMS = window.SWMS || {};

    /* ---------------------------------------------------------------- *
     | Helpers                                                           |
     * ---------------------------------------------------------------- */

    SWMS.qs = function (selector, scope) {
        return (scope || document).querySelector(selector);
    };

    SWMS.qsa = function (selector, scope) {
        return Array.prototype.slice.call((scope || document).querySelectorAll(selector));
    };

    /** Show or clear a field-level error message. */
    SWMS.setFieldError = function (field, message) {
        if (!field) { return; }
        var wrapper = field.closest('.field');
        if (!wrapper) { return; }
        var slot = wrapper.querySelector('.field__error');

        wrapper.classList.toggle('is-invalid', Boolean(message));
        if (slot) { slot.textContent = message || ''; }
        field.setAttribute('aria-invalid', message ? 'true' : 'false');
    };

    SWMS.clearErrors = function (form) {
        SWMS.qsa('.field.is-invalid', form).forEach(function (wrapper) {
            wrapper.classList.remove('is-invalid');
            var slot = wrapper.querySelector('.field__error');
            if (slot) { slot.textContent = ''; }
        });
    };

    /** Render a message into a form's alert slot. */
    SWMS.showAlert = function (container, type, message) {
        if (!container) { return; }
        container.className = 'alert alert--' + type;
        container.textContent = message;
        container.hidden = false;
    };

    SWMS.hideAlert = function (container) {
        if (container) { container.hidden = true; }
    };

    /** Put a button into (or out of) its loading state. */
    SWMS.setLoading = function (button, loading) {
        if (!button) { return; }
        button.classList.toggle('is-loading', loading);
        button.disabled = loading;
    };

    /** POST JSON or FormData and return the decoded body. */
    SWMS.post = function (url, data) {
        var options = { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: data };

        return fetch(url, options).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, message: 'The server returned an unexpected response.' };
            });
        }).catch(function () {
            return { ok: false, message: 'The server could not be reached. Check your connection.' };
        });
    };

    /* ---------------------------------------------------------------- *
     | Validation shared by login, installation and activation           |
     * ---------------------------------------------------------------- */

    SWMS.validate = {
        email: function (value) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(value).trim());
        },
        cnic: function (value) {
            return /^\d{13}$/.test(String(value).replace(/\D+/g, ''));
        },
        identifier: function (value) {
            var trimmed = String(value).trim();
            return trimmed.indexOf('@') > -1 ? SWMS.validate.email(trimmed) : SWMS.validate.cnic(trimmed);
        },
        /** Mirrors Security::passwordProblem() on the server. */
        password: function (value) {
            if (String(value).length < 8) {
                return 'Password must be at least 8 characters.';
            }
            if (!/[A-Za-z]/.test(value) || !/\d/.test(value)) {
                return 'Password must contain at least one letter and one number.';
            }
            return null;
        },
        strength: function (value) {
            var score = 0;
            if (value.length >= 8) { score += 1; }
            if (value.length >= 12) { score += 1; }
            if (/[A-Z]/.test(value) && /[a-z]/.test(value)) { score += 1; }
            if (/\d/.test(value) && /[^A-Za-z0-9]/.test(value)) { score += 1; }
            return Math.min(score, 4);
        }
    };

    var STRENGTH_LABELS = ['', 'Weak', 'Fair', 'Good', 'Strong'];

    /** Wire a password field to its strength meter. */
    SWMS.bindStrength = function (input, meter) {
        if (!input || !meter) { return; }
        var label = meter.querySelector('.strength__label');

        input.addEventListener('input', function () {
            var level = input.value ? SWMS.validate.strength(input.value) : 0;
            meter.setAttribute('data-level', String(level));
            if (label) {
                label.textContent = input.value ? STRENGTH_LABELS[level] + ' password' : '';
            }
        });
    };

    /* ---------------------------------------------------------------- *
     | Password reveal                                                   |
     * ---------------------------------------------------------------- */

    function bindPasswordToggles() {
        SWMS.qsa('[data-password-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.getAttribute('data-password-toggle'));
                if (!input) { return; }

                var reveal = input.type === 'password';
                input.type = reveal ? 'text' : 'password';
                button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
                button.classList.toggle('is-revealed', reveal);

                var open = button.querySelector('[data-icon="eye"]');
                var closed = button.querySelector('[data-icon="eye-off"]');
                if (open && closed) {
                    open.style.display = reveal ? 'none' : '';
                    closed.style.display = reveal ? '' : 'none';
                }
                input.focus();
            });
        });
    }

    /* ---------------------------------------------------------------- *
     | CNIC formatting in the unified identifier field                   |
     * ---------------------------------------------------------------- */

    function bindIdentifierFormatting() {
        SWMS.qsa('[data-identifier-field]').forEach(function (input) {
            input.addEventListener('input', function () {
                var value = input.value;

                /* Leave email addresses completely alone. */
                if (value.indexOf('@') > -1 || /[A-Za-z]/.test(value)) { return; }

                var digits = value.replace(/\D+/g, '').slice(0, 13);
                if (!digits) { return; }

                var formatted = digits;
                if (digits.length > 5) {
                    formatted = digits.slice(0, 5) + '-' + digits.slice(5, 12);
                }
                if (digits.length > 12) {
                    formatted += '-' + digits.slice(12, 13);
                }

                var atEnd = input.selectionStart === value.length;
                input.value = formatted;
                if (atEnd) {
                    input.setSelectionRange(formatted.length, formatted.length);
                }
            });
        });
    }

    /* ---------------------------------------------------------------- *
     | Application shell                                                 |
     * ---------------------------------------------------------------- */

    /* Any form with data-confirm="..." asks before submitting. Shared by
       every module — first needed by Users (deactivate/block), now also
       by Structure (deactivate region/district/etc). */
    function bindConfirmForms() {
        SWMS.qsa('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        });
    }

    function bindShell() {
        var sidebar = SWMS.qs('.sidebar');
        var toggle = SWMS.qs('.sidebar-toggle');
        var backdrop = SWMS.qs('.sidebar-backdrop');

        if (toggle && sidebar) {
            toggle.addEventListener('click', function () {
                var open = sidebar.classList.toggle('is-open');
                if (backdrop) { backdrop.classList.toggle('is-open', open); }
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        if (backdrop && sidebar) {
            backdrop.addEventListener('click', function () {
                sidebar.classList.remove('is-open');
                backdrop.classList.remove('is-open');
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') { return; }
            if (sidebar) { sidebar.classList.remove('is-open'); }
            if (backdrop) { backdrop.classList.remove('is-open'); }
            SWMS.qsa('.modal.is-open').forEach(function (modal) { modal.classList.remove('is-open'); });
        });

        SWMS.qsa('[data-modal-open]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                var modal = document.getElementById(trigger.getAttribute('data-modal-open'));
                if (modal) { modal.classList.add('is-open'); }
            });
        });

        SWMS.qsa('[data-modal-close]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                var modal = trigger.closest('.modal');
                if (modal) { modal.classList.remove('is-open'); }
            });
        });
    }

    function init() {
        bindPasswordToggles();
        bindIdentifierFormatting();
        bindConfirmForms();
        bindShell();
    }

    window.SWMS = SWMS;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
