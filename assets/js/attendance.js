/**
 * attendance.js — Attendance module.
 *
 *  • Live Asia/Karachi clock, driven by the SERVER time (data-server-ms), so a
 *    wrong device clock never misleads — the server still decides everything.
 *  • Check-in / Check-out buttons: one employee per click, POST to
 *    process/mark.php, with a confirmation and a pending state so a double-tap
 *    cannot send twice. The page only reflects the server's answer.
 *  • Dashboard: refreshes the numbers every 30 seconds without a reload.
 */
(function (window, document) {
    'use strict';

    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function showMessage(type, text) {
        var box = document.getElementById('form-alert');
        if (!box) { window.alert(text); return; }
        box.className = 'alert alert--' + type;
        box.textContent = text;
        box.hidden = false;
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    /* ---- live clock (Asia/Karachi, offset by the server) ---- */
    function initClock() {
        var el = document.querySelector('[data-server-ms]');
        if (!el) { return; }
        var offset = parseInt(el.getAttribute('data-server-ms'), 10) - Date.now();
        var timeEl = document.getElementById('att-clock-time');
        function tick() {
            var d = new Date(Date.now() + offset);
            if (timeEl) {
                timeEl.textContent = d.toLocaleTimeString('en-GB', { timeZone: 'Asia/Karachi', hour12: false }).slice(0, 8);
            }
        }
        tick();
        window.setInterval(tick, 1000);
    }

    /* ---- marking ---- */
    function applyRow(row, data) {
        var tr = row.closest('[data-att-row]') || row;
        var map = { status: '[data-cell="status"]', checkin: '[data-cell="checkin"]', checkout: '[data-cell="checkout"]', hint: '[data-cell="hint"]' };
        if (data.status_html) { var s = tr.querySelector(map.status); if (s) { s.innerHTML = data.status_html; } }
        if (data.checkin !== undefined) { var a = tr.querySelector(map.checkin); if (a) { a.textContent = data.checkin; } }
        if (data.checkout !== undefined) { var b = tr.querySelector(map.checkout); if (b) { b.textContent = data.checkout; } }
        if (data.hint !== undefined) { var h = tr.querySelector(map.hint); if (h) { h.textContent = data.hint; } }
        qsa('[data-att-event]', tr).forEach(function (btn) {
            var can = btn.getAttribute('data-att-event') === 'checkin' ? data.can_checkin : data.can_checkout;
            btn.disabled = !can;
        });
    }

    function initMarking() {
        var root = document.querySelector('[data-att-csrf]');
        if (!root) { return; }
        var csrf = root.getAttribute('data-att-csrf');
        var url = root.getAttribute('data-att-url');

        qsa('[data-att-event]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (btn.disabled) { return; }
                var event = btn.getAttribute('data-att-event');
                var name = btn.getAttribute('data-att-name') || 'this employee';
                var label = event === 'checkin' ? 'Check-in' : 'Check-out';
                if (!window.confirm('Record ' + label + ' for ' + name + ' now?\nThe server time will be used and cannot be changed afterwards.')) { return; }

                var group = qsa('[data-att-event]', btn.closest('[data-att-row]') || document);
                group.forEach(function (b) { b.disabled = true; });          // no double-tap

                var body = new URLSearchParams();
                body.set('event', event);
                if (btn.getAttribute('data-att-own') === '1') { body.set('own', '1'); }
                else { body.set('employee_id', btn.getAttribute('data-att-employee')); }

                window.fetch(url, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Unexpected response from the server.' }; }); })
                  .then(function (res) {
                      showMessage(res.ok ? 'success' : 'error', res.message || (res.ok ? 'Saved.' : 'Not saved.'));
                      if (res.row) { applyRow(btn, res.row); }
                      else { group.forEach(function (b) { b.disabled = false; }); }
                      if (res.ok) { document.dispatchEvent(new CustomEvent('att:marked')); }
                  })
                  .catch(function () {
                      showMessage('error', 'Network problem — nothing was recorded. Check your connection and try again.');
                      group.forEach(function (b) { b.disabled = false; });
                  });
            });
        });
    }

    /* ---- dashboard auto-refresh ---- */
    function initDashboard() {
        var box = document.querySelector('[data-att-dashboard]');
        if (!box) { return; }
        var url = box.getAttribute('data-att-dashboard');
        var stamp = document.getElementById('att-updated');
        function refresh() {
            window.fetch(url + (url.indexOf('?') > -1 ? '&' : '?') + '_=' + Date.now(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data || !data.cards) { return; }
                    Object.keys(data.cards).forEach(function (k) {
                        qsa('[data-card="' + k + '"]').forEach(function (el) { el.textContent = data.cards[k]; });
                    });
                    if (stamp) { stamp.textContent = 'Updated ' + data.generated_at; }
                }).catch(function () { /* keep showing the last numbers */ });
        }
        if (box.getAttribute('data-live') === '1') { window.setInterval(refresh, 30000); }
        var btn = document.getElementById('att-refresh');
        if (btn) { btn.addEventListener('click', refresh); }
    }

    /* ---- generic: confirm-on-submit forms and auto-submit selects ---- */
    function initForms() {
        qsa('[data-att-confirm]').forEach(function (b) {
            b.addEventListener('click', function (ev) { if (!window.confirm(b.getAttribute('data-att-confirm'))) { ev.preventDefault(); } });
        });
        qsa('[data-att-autosubmit]').forEach(function (sel) {
            sel.addEventListener('change', function () { if (sel.form) { sel.form.submit(); } });
        });
        qsa('[data-att-period]').forEach(function (sel) {
            function sync() {
                var range = sel.value === 'range';
                qsa('[data-att-range]').forEach(function (el) { el.hidden = !range; });
                qsa('[data-att-single]').forEach(function (el) { el.hidden = range; });
            }
            sel.addEventListener('change', sync);
            sync();
        });
    }

    function boot() { initClock(); initMarking(); initDashboard(); initForms(); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})(window, document);
