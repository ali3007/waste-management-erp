/**
 * auth.js — client-side behaviour for the authentication screens.
 * Everything validated here is validated again on the server.
 */
(function (window, document) {
    'use strict';

    function init() {
        var SWMS = window.SWMS;
        if (!SWMS) { return; }

        bindLogin(SWMS);
        bindForgot(SWMS);
        bindPasswordForm(SWMS, document.getElementById('reset-form'));
        bindPasswordForm(SWMS, document.getElementById('activate-form'));
    }

    /* Login: unified Email / CNIC field. */
    function bindLogin(SWMS) {
        var form = document.getElementById('login-form');
        if (!form) { return; }

        var identifier = document.getElementById('identifier');
        var password = document.getElementById('password');
        var submit = form.querySelector('[type="submit"]');
        var alertBox = document.getElementById('form-alert');

        form.addEventListener('submit', function (event) {
            SWMS.clearErrors(form);
            var valid = true;

            if (!identifier.value.trim()) {
                SWMS.setFieldError(identifier, 'Enter your email address or CNIC.');
                valid = false;
            } else if (!SWMS.validate.identifier(identifier.value)) {
                SWMS.setFieldError(identifier, 'Enter a valid email address or a 13-digit CNIC.');
                valid = false;
            }

            if (!password.value) {
                SWMS.setFieldError(password, 'Enter your password.');
                valid = false;
            }

            if (!valid) {
                event.preventDefault();
                SWMS.hideAlert(alertBox);
                (form.querySelector('.is-invalid input') || identifier).focus();
                return;
            }

            SWMS.setLoading(submit, true);
        });

        /* Tell the person which credential the system recognised. */
        var hint = document.getElementById('identifier-hint');
        if (hint && identifier) {
            identifier.addEventListener('input', function () {
                var value = identifier.value.trim();
                if (!value) {
                    hint.textContent = 'Administrators sign in with email; staff sign in with CNIC.';
                } else if (value.indexOf('@') > -1) {
                    hint.textContent = 'Signing in with an email address.';
                } else {
                    hint.textContent = 'Signing in with a CNIC number.';
                }
            });
        }
    }

    /* Forgot password: same unified identifier rules. */
    function bindForgot(SWMS) {
        var form = document.getElementById('forgot-form');
        if (!form) { return; }

        var identifier = document.getElementById('identifier');
        var submit = form.querySelector('[type="submit"]');

        form.addEventListener('submit', function (event) {
            SWMS.clearErrors(form);

            if (!SWMS.validate.identifier(identifier.value)) {
                event.preventDefault();
                SWMS.setFieldError(identifier, 'Enter a valid email address or a 13-digit CNIC.');
                identifier.focus();
                return;
            }

            SWMS.setLoading(submit, true);
        });
    }

    /* Reset and activation share one "choose a password" form. */
    function bindPasswordForm(SWMS, form) {
        if (!form) { return; }

        var password = form.querySelector('[data-password]');
        var confirm = form.querySelector('[data-password-confirm]');
        var meter = form.querySelector('.strength');
        var submit = form.querySelector('[type="submit"]');

        SWMS.bindStrength(password, meter);

        if (confirm) {
            confirm.addEventListener('input', function () {
                if (confirm.value && confirm.value !== password.value) {
                    SWMS.setFieldError(confirm, 'Passwords do not match.');
                } else {
                    SWMS.setFieldError(confirm, '');
                }
            });
        }

        form.addEventListener('submit', function (event) {
            SWMS.clearErrors(form);
            var problem = SWMS.validate.password(password.value);
            var valid = true;

            if (problem) {
                SWMS.setFieldError(password, problem);
                valid = false;
            }

            if (confirm && confirm.value !== password.value) {
                SWMS.setFieldError(confirm, 'Passwords do not match.');
                valid = false;
            }

            if (!valid) {
                event.preventDefault();
                (form.querySelector('.is-invalid input') || password).focus();
                return;
            }

            SWMS.setLoading(submit, true);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
