/**
 * installation.js — the first-run wizard.
 *
 * Each step is validated in the browser, then posted to install/process.php
 * which re-validates it and keeps the answers in the installer session.
 * The final step writes the config, the schema and the Super Admin.
 */
(function (window, document) {
    'use strict';

    var TOTAL_STEPS = 5;

    function init() {
        var SWMS = window.SWMS;
        var wizard = document.getElementById('install-wizard');
        if (!SWMS || !wizard) { return; }

        var endpoint = wizard.getAttribute('data-endpoint');
        var token = wizard.getAttribute('data-token');
        var alertBox = document.getElementById('form-alert');
        var current = 1;

        var panels = SWMS.qsa('.step-panel', wizard);
        var markers = SWMS.qsa('.steps__item', wizard);
        var nextButton = document.getElementById('step-next');
        var backButton = document.getElementById('step-back');

        /* ------------------------------------------------------------ *
         | Navigation                                                    |
         * ------------------------------------------------------------ */

        function show(step) {
            current = step;

            panels.forEach(function (panel) {
                panel.classList.toggle('is-active', Number(panel.getAttribute('data-step')) === step);
            });

            markers.forEach(function (marker) {
                var index = Number(marker.getAttribute('data-step'));
                marker.classList.toggle('is-active', index === step);
                marker.classList.toggle('is-done', index < step);
            });

            backButton.style.visibility = step > 1 && step < TOTAL_STEPS ? 'visible' : 'hidden';
            nextButton.querySelector('.btn__label').textContent = step === 4 ? 'Install now' : 'Continue';
            nextButton.hidden = step === TOTAL_STEPS;

            SWMS.hideAlert(alertBox);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        /* ------------------------------------------------------------ *
         | Per-step validation                                           |
         * ------------------------------------------------------------ */

        function field(id) {
            return document.getElementById(id);
        }

        function requireValue(id, message) {
            var input = field(id);
            if (!input.value.trim()) {
                SWMS.setFieldError(input, message);
                return false;
            }
            return true;
        }

        function validateStep(step) {
            var panel = panels[step - 1];
            SWMS.clearErrors(panel);
            var valid = true;

            if (step === 2) {
                valid = requireValue('db_host', 'Enter the database host.') && valid;
                valid = requireValue('db_name', 'Enter the database name.') && valid;
                valid = requireValue('db_user', 'Enter the database username.') && valid;
            }

            if (step === 3) {
                valid = requireValue('company_name', 'Enter the company name.') && valid;

                var companyEmail = field('company_email');
                if (companyEmail.value.trim() && !SWMS.validate.email(companyEmail.value)) {
                    SWMS.setFieldError(companyEmail, 'Enter a valid email address.');
                    valid = false;
                }
            }

            if (step === 4) {
                valid = requireValue('admin_name', 'Enter the administrator name.') && valid;

                var email = field('admin_email');
                if (!SWMS.validate.email(email.value)) {
                    SWMS.setFieldError(email, 'Enter a valid email address.');
                    valid = false;
                }

                var password = field('admin_password');
                var problem = SWMS.validate.password(password.value);
                if (problem) {
                    SWMS.setFieldError(password, problem);
                    valid = false;
                }

                var confirm = field('admin_password_confirm');
                if (confirm.value !== password.value) {
                    SWMS.setFieldError(confirm, 'Passwords do not match.');
                    valid = false;
                }
            }

            if (!valid) {
                var firstInvalid = panel.querySelector('.is-invalid input');
                if (firstInvalid) { firstInvalid.focus(); }
            }

            return valid;
        }

        /* ------------------------------------------------------------ *
         | Submission                                                    |
         * ------------------------------------------------------------ */

        function payloadFor(step) {
            var data = new FormData();
            data.append('_csrf_token', token);

            if (step === 2) {
                data.append('action', 'database');
                ['db_host', 'db_port', 'db_name', 'db_user', 'db_pass'].forEach(function (name) {
                    data.append(name, field(name).value);
                });
            }

            if (step === 3) {
                data.append('action', 'company');
                ['company_name', 'company_address', 'company_phone', 'company_email', 'company_website'].forEach(function (name) {
                    data.append(name, field(name).value);
                });
                var logo = field('company_logo');
                if (logo.files.length) { data.append('company_logo', logo.files[0]); }
            }

            if (step === 4) {
                data.append('action', 'install');
                ['admin_name', 'admin_email', 'admin_password', 'admin_password_confirm'].forEach(function (name) {
                    data.append(name, field(name).value);
                });
                var picture = field('admin_picture');
                if (picture.files.length) { data.append('admin_picture', picture.files[0]); }
            }

            return data;
        }

        function applyServerErrors(errors) {
            Object.keys(errors || {}).forEach(function (name) {
                SWMS.setFieldError(field(name), errors[name]);
            });
        }

        nextButton.addEventListener('click', function () {
            if (current === 1) { show(2); return; }

            if (!validateStep(current)) { return; }

            SWMS.setLoading(nextButton, true);
            SWMS.hideAlert(alertBox);

            SWMS.post(endpoint, payloadFor(current)).then(function (response) {
                SWMS.setLoading(nextButton, false);

                if (!response.ok) {
                    SWMS.showAlert(alertBox, 'error', response.message || 'That step could not be completed.');
                    applyServerErrors(response.errors);
                    return;
                }

                if (response.redirect) {
                    window.location.href = response.redirect;
                    return;
                }

                if (response.message) {
                    SWMS.showAlert(alertBox, 'success', response.message);
                }

                show(current + 1);
            });
        });

        backButton.addEventListener('click', function () {
            if (current > 1) { show(current - 1); }
        });

        /* ------------------------------------------------------------ *
         | Niceties                                                      |
         * ------------------------------------------------------------ */

        SWMS.bindStrength(field('admin_password'), SWMS.qs('.strength', wizard));

        SWMS.qsa('[data-uploader]').forEach(function (uploader) {
            var input = uploader.querySelector('input[type="file"]');
            var preview = uploader.querySelector('.uploader__preview');
            var name = uploader.querySelector('.uploader__name');
            var trigger = uploader.querySelector('[data-uploader-trigger]');
            var clear = uploader.querySelector('[data-uploader-clear]');

            if (trigger) {
                trigger.addEventListener('click', function () { input.click(); });
            }

            input.addEventListener('change', function () {
                var file = input.files[0];

                if (!file) { return; }

                if (!/^image\/(png|jpeg|gif|webp)$/.test(file.type)) {
                    SWMS.showAlert(alertBox, 'error', 'Choose a PNG, JPG, GIF or WEBP image.');
                    input.value = '';
                    return;
                }

                if (file.size > 2 * 1024 * 1024) {
                    SWMS.showAlert(alertBox, 'error', 'Choose an image smaller than 2 MB.');
                    input.value = '';
                    return;
                }

                SWMS.hideAlert(alertBox);
                if (name) { name.textContent = file.name; }
                if (clear) { clear.hidden = false; }

                var reader = new FileReader();
                reader.onload = function (event) {
                    preview.innerHTML = '<img src="' + event.target.result + '" alt="">';
                };
                reader.readAsDataURL(file);
            });

            if (clear) {
                clear.addEventListener('click', function () {
                    input.value = '';
                    preview.innerHTML = preview.getAttribute('data-placeholder') || '';
                    if (name) { name.textContent = 'No file selected'; }
                    clear.hidden = true;
                });
            }
        });

        /* Enter moves to the next step rather than submitting the page. */
        wizard.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && event.target.tagName === 'INPUT') {
                event.preventDefault();
                nextButton.click();
            }
        });

        show(1);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
