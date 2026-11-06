/**
 * complaints.js — behaviour shared by the Complaints module screens:
 *   - Region → District → Tehsil → UC → Beat cascades
 *   - optional GPS capture
 *   - dialogs (action reasons, reassign, duplicate warning)
 *   - the Add Complaint flow: save → duplicate warning → upload the chosen
 *     Before pictures / attachments in small batches → open the complaint
 * Server-side validation and authorization still run for everything here;
 * nothing in this file is trusted for access control.
 */
(function (window, document) {
    'use strict';

    var Cmp = window.Cmp = window.Cmp || {};

    Cmp.qs = function (s, scope) { return (scope || document).querySelector(s); };
    Cmp.qsa = function (s, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(s)); };

    Cmp.csrf = function () {
        var holder = Cmp.qs('[data-csrf]');
        if (holder) { return holder.getAttribute('data-csrf'); }
        var input = Cmp.qs('input[name="_csrf_token"]');
        return input ? input.value : '';
    };

    /** fetch wrapper that marks the request as AJAX and sends the CSRF token. */
    Cmp.post = function (url, body) {
        return window.fetch(url, {
            method: 'POST', body: body, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': Cmp.csrf() }
        }).then(function (res) {
            return res.json().catch(function () { return { ok: false, message: 'Unexpected server response.' }; })
                .then(function (data) { data._status = res.status; return data; });
        });
    };

    /* ---------------------------------------------------------------- *
     | Dialogs                                                           |
     * ---------------------------------------------------------------- */
    Cmp.openModal = function (modal) {
        if (!modal) { return; }
        modal.hidden = false;
        document.body.classList.add('cmp-modal-open');
        var focusable = modal.querySelector('textarea, input:not([type=hidden]), select, button');
        if (focusable) { window.setTimeout(function () { focusable.focus(); }, 30); }
    };
    Cmp.closeModal = function (modal) {
        if (!modal) { return; }
        modal.hidden = true;
        if (!Cmp.qs('.cmp-modal:not([hidden])')) { document.body.classList.remove('cmp-modal-open'); }
    };

    function initModals() {
        Cmp.qsa('[data-open-modal]').forEach(function (btn) {
            btn.addEventListener('click', function () { Cmp.openModal(document.getElementById(btn.getAttribute('data-open-modal'))); });
        });
        Cmp.qsa('[data-close-modal]').forEach(function (btn) {
            btn.addEventListener('click', function () { Cmp.closeModal(btn.closest('.cmp-modal')); });
        });
        Cmp.qsa('.cmp-modal').forEach(function (modal) {
            modal.addEventListener('mousedown', function (e) { if (e.target === modal) { Cmp.closeModal(modal); } });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var open = Cmp.qs('.cmp-modal:not([hidden])');
                if (open) { Cmp.closeModal(open); }
            }
        });

        /* Generic "reason" dialog driven by the button's data-* attributes. */
        var modal = document.getElementById('action-modal');
        if (!modal) { return; }
        Cmp.qsa('[data-act]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                modal.querySelector('[data-op]').value = btn.getAttribute('data-act');
                modal.querySelector('[data-title]').textContent = btn.getAttribute('data-title') || 'Action';
                modal.querySelector('[data-label]').textContent = btn.getAttribute('data-label') || 'Reason';
                var area = modal.querySelector('textarea');
                area.value = '';
                area.required = btn.getAttribute('data-required') === '1';
                var submit = modal.querySelector('[data-submit]');
                submit.querySelector('.btn__label').textContent = btn.getAttribute('data-submit') || 'Confirm';
                submit.classList.toggle('btn--danger', btn.getAttribute('data-danger') === '1');
                Cmp.openModal(modal);
            });
        });
        modal.querySelector('form').addEventListener('submit', function (e) {
            var area = modal.querySelector('textarea');
            if (area.required && !area.value.trim()) {
                e.preventDefault();
                area.focus();
                area.classList.add('is-invalid');
            }
        });
    }

    /* ---------------------------------------------------------------- *
     | Geography cascade                                                 |
     * ---------------------------------------------------------------- */
    function fillSelect(select, items, placeholder) {
        select.innerHTML = '';
        var first = document.createElement('option');
        first.value = '';
        first.textContent = placeholder;
        select.appendChild(first);
        items.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.name;
            select.appendChild(opt);
        });
    }

    function initCascade() {
        Cmp.qsa('.cmp-cascade').forEach(function (box) {
            var url = box.getAttribute('data-cascade-url');
            var order = ['district', 'tehsil', 'uc', 'beat'];
            var selects = {};
            Cmp.qsa('select', box).forEach(function (s) {
                var key = (s.name || '').replace('_id', '');
                selects[key] = s;
            });

            function reset(fromKey) {
                var start = order.indexOf(fromKey);
                for (var i = start; i < order.length; i++) {
                    var s = selects[order[i]];
                    if (s) { fillSelect(s, [], order[i] === 'beat' ? 'No beat' : 'Select ' + (order[i] === 'uc' ? 'UC' : order[i])); }
                }
            }

            Cmp.qsa('select[data-cascade-child]', box).forEach(function (select) {
                select.addEventListener('change', function () {
                    var child = select.getAttribute('data-cascade-child');
                    reset(child);
                    if (!select.value) { return; }
                    window.fetch(url + '?level=' + encodeURIComponent(child) + '&parent=' + encodeURIComponent(select.value), {
                        credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    }).then(function (r) { return r.json(); }).then(function (data) {
                        if (data && data.ok && selects[child]) {
                            fillSelect(selects[child], data.items, child === 'beat' ? 'No beat' : 'Select ' + (child === 'uc' ? 'UC' : child));
                        }
                    }).catch(function () {});
                });
            });
        });
    }

    /* ---------------------------------------------------------------- *
     | Optional GPS                                                      |
     * ---------------------------------------------------------------- */
    function initGps() {
        var btn = Cmp.qs('[data-gps]');
        if (!btn) { return; }
        var status = Cmp.qs('[data-gps-status]');
        btn.addEventListener('click', function () {
            if (!navigator.geolocation) {
                status.textContent = 'This browser cannot provide GPS. Type the coordinates instead.';
                return;
            }
            status.textContent = 'Getting location…';
            navigator.geolocation.getCurrentPosition(function (pos) {
                Cmp.qs('#latitude').value = pos.coords.latitude.toFixed(7);
                Cmp.qs('#longitude').value = pos.coords.longitude.toFixed(7);
                status.textContent = 'Location added (accuracy ≈ ' + Math.round(pos.coords.accuracy) + ' m).';
            }, function () {
                status.textContent = 'Location is unavailable or was refused. You can type the coordinates or leave them empty.';
            }, { enableHighAccuracy: true, timeout: 15000 });
        });
    }

    /* ---------------------------------------------------------------- *
     | Add-complaint flow                                                |
     * ---------------------------------------------------------------- */
    var IMAGE_RE = /\.(jpe?g|png|webp)$/i;

    function naturalSort(files) {
        return files.slice().sort(function (a, b) {
            var an = a.webkitRelativePath || a.name, bn = b.webkitRelativePath || b.name;
            return an.localeCompare(bn, undefined, { numeric: true, sensitivity: 'base' });
        });
    }

    /** Upload files in small batches, in order. onProgress(done, total). */
    Cmp.uploadBatches = function (url, fields, files, onProgress) {
        var MAX_COUNT = 8, MAX_BYTES = 6 * 1024 * 1024;
        var batches = [], current = [], bytes = 0;
        files.forEach(function (f) {
            if (current.length && (current.length >= MAX_COUNT || bytes + f.size > MAX_BYTES)) {
                batches.push(current); current = []; bytes = 0;
            }
            current.push(f); bytes += f.size;
        });
        if (current.length) { batches.push(current); }

        var done = 0, items = [], errors = [];
        return batches.reduce(function (chain, batch) {
            return chain.then(function () {
                var fd = new FormData();
                Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
                fd.append('_csrf_token', Cmp.csrf());
                batch.forEach(function (f) { fd.append('files[]', f, f.name); });
                return Cmp.post(url, fd).then(function (data) {
                    if (data.csrf) {
                        var holder = Cmp.qs('[data-csrf]');
                        if (holder) { holder.setAttribute('data-csrf', data.csrf); }
                    }
                    if (data.items) { items = items.concat(data.items); }
                    if (data.errors && data.errors.length) { errors = errors.concat(data.errors); }
                    else if (!data.ok && data.message) { batch.forEach(function (f) { errors.push({ name: f.name, message: data.message }); }); }
                    done += batch.length;
                    if (onProgress) { onProgress(done, files.length, data.items || []); }
                }).catch(function () {
                    batch.forEach(function (f) { errors.push({ name: f.name, message: 'Network error.' }); });
                    done += batch.length;
                    if (onProgress) { onProgress(done, files.length, []); }
                });
            });
        }, Promise.resolve()).then(function () { return { items: items, errors: errors }; });
    };

    function initCreateForm() {
        var form = Cmp.qs('#complaint-form[data-ajax-url]');
        if (!form) { return; }

        var queues = { before: [], attachment: [] };
        var dupModal = document.getElementById('dup-modal');
        var progress = Cmp.qs('[data-progress]', form);
        var bar = Cmp.qs('[data-progress-bar]', form);
        var text = Cmp.qs('[data-progress-text]', form);
        var submit = Cmp.qs('[data-submit]', form);

        Cmp.qsa('input[data-queue]', form).forEach(function (input) {
            input.addEventListener('change', function () {
                var kind = input.getAttribute('data-queue');
                var chosen = Array.prototype.slice.call(input.files);
                if (kind === 'before') { chosen = chosen.filter(function (f) { return IMAGE_RE.test(f.name); }); }
                queues[kind] = naturalSort(queues[kind].concat(chosen));
                var info = Cmp.qs('[data-queue-info="' + kind + '"]', form);
                if (info) {
                    info.textContent = queues[kind].length
                        ? queues[kind].length + (kind === 'before' ? ' picture' : ' document') + (queues[kind].length === 1 ? '' : 's') + ' ready to upload.'
                        : (kind === 'before' ? 'No pictures chosen.' : 'No documents chosen.');
                }
                input.value = '';
            });
        });

        function setBusy(busy) {
            if (submit) { submit.classList.toggle('is-loading', busy); submit.disabled = busy; }
        }
        function showProgress(done, total, label) {
            progress.hidden = false;
            bar.style.width = (total ? Math.round(done / total * 100) : 0) + '%';
            text.textContent = label + ' ' + done + ' of ' + total + '…';
        }

        function send(ack) {
            setBusy(true);
            var fd = new FormData(form);
            if (ack) { fd.set('ack_duplicate', '1'); }
            Cmp.post(form.getAttribute('data-ajax-url'), fd).then(function (data) {
                if (data.duplicate) {
                    setBusy(false);
                    var list = Cmp.qs('[data-dup-list]', dupModal);
                    list.innerHTML = '';
                    data.matches.forEach(function (m) {
                        var li = document.createElement('li');
                        var a = document.createElement('a');
                        a.href = m.url; a.target = '_blank'; a.rel = 'noopener';
                        var strong = document.createElement('strong');
                        strong.textContent = m.number;
                        a.appendChild(strong);
                        li.appendChild(a);
                        li.appendChild(document.createTextNode(' — ' + m.complainant + ', ' + m.type + ', ' + m.uc + ', ' + m.date + ' (' + m.status + ')'));
                        list.appendChild(li);
                    });
                    Cmp.openModal(dupModal);
                    return;
                }
                if (!data.ok) {
                    setBusy(false);
                    window.alert(data.message || 'The complaint could not be saved.');
                    return;
                }
                if (data.csrf) { form.setAttribute('data-csrf', data.csrf); }
                afterCreate(data);
            }).catch(function () {
                setBusy(false);
                window.alert('Network error — the complaint was not saved.');
            });
        }

        function afterCreate(created) {
            var problems = [];
            var chain = Promise.resolve();
            if (queues.before.length) {
                chain = chain.then(function () {
                    return Cmp.uploadBatches(form.getAttribute('data-evidence-url'), { complaint_id: created.id, kind: 'before' }, queues.before,
                        function (d, t) { showProgress(d, t, 'Uploading Before pictures'); });
                }).then(function (r) { r.errors.forEach(function (e) { problems.push(e.name + ': ' + e.message); }); });
            }
            if (queues.attachment.length) {
                chain = chain.then(function () {
                    return Cmp.uploadBatches(form.getAttribute('data-attachment-url'), { complaint_id: created.id }, queues.attachment,
                        function (d, t) { showProgress(d, t, 'Uploading attachments'); });
                }).then(function (r) { r.errors.forEach(function (e) { problems.push(e.name + ': ' + e.message); }); });
            }
            chain.then(function () {
                if (problems.length) {
                    window.alert('Complaint ' + created.number + ' was created, but some files were not uploaded:\n\n' + problems.slice(0, 10).join('\n') + (problems.length > 10 ? '\n…' : '') + '\n\nYou can add them again from the complaint page.');
                }
                window.location.href = created.url;
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var invalid = Cmp.qsa('[required]', form).filter(function (f) { return !f.value || !String(f.value).trim(); })[0];
            if (invalid) {
                invalid.focus();
                invalid.classList.add('is-invalid');
                window.alert('Please fill in the required fields.');
                return;
            }
            var ack = form.querySelector('input[name="ack_duplicate"]');
            send(Boolean(ack && ack.checked));
        });

        if (dupModal) {
            Cmp.qs('[data-dup-continue]', dupModal).addEventListener('click', function () { Cmp.closeModal(dupModal); send(true); });
            Cmp.qs('[data-dup-cancel]', dupModal).addEventListener('click', function () { Cmp.closeModal(dupModal); });
        }
    }

    function boot() {
        initModals();
        initCascade();
        initGps();
        initCreateForm();
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})(window, document);
