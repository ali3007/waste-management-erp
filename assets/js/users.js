/**
 * users.js — behaviour for the User Management module (Phase 2).
 * Filtering and pagination are plain GET requests (no JS required);
 * this file only handles things a full page load can't: confirmations,
 * copy-to-clipboard, avatar preview and the permission matrix helpers.
 */
(function (window, document) {
    'use strict';

    function init() {
        var SWMS = window.SWMS;
        if (!SWMS) { return; }

        bindCopyButtons(SWMS);
        bindAvatarUploaders(SWMS);
        bindMatrixGroups(SWMS);
        bindRoleForm(SWMS);
    }

    /* Confirmation prompts (data-confirm) are now bound globally by app.js. */

    /* Copies the activation link shown after creating or resending. */
    function bindCopyButtons(SWMS) {
        SWMS.qsa('[data-copy-target]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.getAttribute('data-copy-target'));
                if (!input) { return; }

                input.select();
                input.setSelectionRange(0, input.value.length);

                var done = function () {
                    var label = button.querySelector('.btn__label');
                    var original = label ? label.textContent : '';
                    if (label) { label.textContent = 'Copied'; }
                    window.setTimeout(function () {
                        if (label) { label.textContent = original; }
                    }, 1600);
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(input.value).then(done).catch(function () {
                        document.execCommand('copy');
                        done();
                    });
                } else {
                    document.execCommand('copy');
                    done();
                }
            });
        });
    }

    /* Avatar picker on the create/edit user forms — the same preview
       behaviour the installer uses, scoped to this module's own forms
       so the frozen installation.js is left untouched. */
    function bindAvatarUploaders(SWMS) {
        SWMS.qsa('[data-uploader]', document.getElementById('user-form')).forEach(function (uploader) {
            var input = uploader.querySelector('input[type="file"]');
            var preview = uploader.querySelector('.uploader__preview');
            var name = uploader.querySelector('.uploader__name');
            var trigger = uploader.querySelector('[data-uploader-trigger]');
            var clear = uploader.querySelector('[data-uploader-clear]');

            if (!input) { return; }

            if (trigger) {
                trigger.addEventListener('click', function () { input.click(); });
            }

            input.addEventListener('change', function () {
                var file = input.files[0];
                if (!file) { return; }

                if (!/^image\/(png|jpeg|gif|webp)$/.test(file.type) || file.size > 2 * 1024 * 1024) {
                    window.alert('Choose a PNG, JPG, GIF or WEBP image up to 2 MB.');
                    input.value = '';
                    return;
                }

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
    }

    /* Each module block in the permission matrix has a "select all"
       checkbox that toggles every action checkbox beneath it. */
    function bindMatrixGroups(SWMS) {
        SWMS.qsa('.matrix__group').forEach(function (group) {
            var master = group.querySelector('[data-matrix-toggle]');
            var boxes = SWMS.qsa('input[type="checkbox"][data-matrix-item]', group);
            if (!master || !boxes.length) { return; }

            function syncMaster() {
                var checked = boxes.filter(function (box) { return box.checked; }).length;
                master.checked = checked === boxes.length;
                master.indeterminate = checked > 0 && checked < boxes.length;
            }

            master.addEventListener('change', function () {
                boxes.forEach(function (box) { box.checked = master.checked; });
            });

            boxes.forEach(function (box) { box.addEventListener('change', syncMaster); });
            syncMaster();
        });
    }

    /* Role name -> live slug preview on the create-role form. */
    function bindRoleForm(SWMS) {
        var nameField = document.getElementById('role_name');
        var slugPreview = document.getElementById('role-slug-preview');
        if (!nameField || !slugPreview) { return; }

        nameField.addEventListener('input', function () {
            var slug = nameField.value
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
            slugPreview.textContent = slug || 'role-slug';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
