/**
 * transport.js — Transport module behaviour.
 *
 *  - [data-picker]  search-as-you-type vehicle / driver pickers
 *  - [data-geo]     Region → District → Tehsil → UC → Beat cascade (AJAX)
 *  - [data-calc]    fuel total = quantity × rate
 *  - [data-toggle-target]  show/hide an inline panel
 * Nothing here holds data: every option comes from the server.
 */
(function (window, document) {
    'use strict';

    function qsa(sel, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(sel)); }
    function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }

    /* ---------------------------------------------------------------- *
     | Pickers                                                           |
     * ---------------------------------------------------------------- */
    function initPicker(root) {
        var input = root.querySelector('input[type="text"]');
        var hidden = root.querySelector('input[type="hidden"]');
        var list = root.querySelector('.picker__list');
        var kind = root.getAttribute('data-kind');
        var url = root.getAttribute('data-url');
        var seq = 0, active = -1;

        function close() { list.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; }
        function pick(item) {
            hidden.value = item.id; input.value = item.label; root.classList.add('is-selected'); close();
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function render(items) {
            list.innerHTML = '';
            if (!items.length) {
                var li = document.createElement('li'); li.className = 'is-empty'; li.textContent = 'No matches'; list.appendChild(li);
            }
            items.forEach(function (item) {
                var li = document.createElement('li'); li.setAttribute('role', 'option');
                var s = document.createElement('strong'); s.textContent = item.label;
                var sub = document.createElement('span'); sub.textContent = item.sub || '';
                li.appendChild(s); li.appendChild(sub);
                li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(item); });
                list.appendChild(li);
            });
            list.hidden = false; input.setAttribute('aria-expanded', 'true');
        }
        var search = debounce(function () {
            var q = input.value.trim(); var my = ++seq;
            fetch(url + '?kind=' + encodeURIComponent(kind) + '&q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (my === seq) { render((d && d.items) || []); } })
                .catch(function () { if (my === seq) { render([]); } });
        }, 200);

        input.addEventListener('input', function () { hidden.value = ''; root.classList.remove('is-selected'); search(); });
        input.addEventListener('focus', function () { if (!hidden.value) { search(); } });
        input.addEventListener('blur', function () { setTimeout(close, 120); });
        input.addEventListener('keydown', function (e) {
            var items = qsa('li:not(.is-empty)', list);
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault(); if (list.hidden) { search(); return; }
                active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                items.forEach(function (li, i) { li.classList.toggle('is-active', i === active); });
            } else if (e.key === 'Enter' && !list.hidden && active >= 0 && items[active]) {
                e.preventDefault(); items[active].dispatchEvent(new MouseEvent('mousedown'));
            } else if (e.key === 'Escape') { close(); }
        });
        if (hidden.value) { root.classList.add('is-selected'); }

        var form = root.closest('form');
        if (form && !form.__pickerGuard) {
            form.__pickerGuard = true;
            form.addEventListener('submit', function (e) {
                var bad = qsa('[data-picker] input[data-required]', form).filter(function (h) { return !h.value; })[0];
                if (bad) {
                    e.preventDefault();
                    var f = bad.closest('.field'); var err = f && f.querySelector('.field__error');
                    if (err) { err.textContent = 'Pick one from the list.'; }
                    var t = f && f.querySelector('input[type="text"]'); if (t) { t.focus(); }
                }
            });
        }
    }

    /* ---------------------------------------------------------------- *
     | Geography cascade                                                 |
     * ---------------------------------------------------------------- */
    function initGeo(root) {
        var url = root.getAttribute('data-url');
        var order = ['region', 'district', 'tehsil', 'uc', 'beat'];
        var sel = {};
        order.forEach(function (l) { sel[l] = root.querySelector('[data-geo-level="' + l + '"]'); });
        var labels = { district: 'Choose a district…', tehsil: 'Choose a tehsil…', uc: 'Choose a UC…', beat: 'No specific beat' };
        var waiting = { district: 'Choose a region first…', tehsil: 'Choose a district first…', uc: 'Choose a tehsil first…', beat: 'Choose a UC first…' };

        function reset(level) {
            var el = sel[level]; if (!el) { return; }
            el.innerHTML = ''; var o = document.createElement('option'); o.value = ''; o.textContent = waiting[level]; el.appendChild(o); el.disabled = true;
        }
        order.forEach(function (level, i) {
            var el = sel[level]; var child = order[i + 1];
            if (!el || !child || !sel[child]) { return; }
            el.addEventListener('change', function () {
                for (var j = i + 1; j < order.length; j++) { reset(order[j]); }
                if (!el.value) { return; }
                var target = sel[child]; target.disabled = true;
                fetch(url + '?level=' + child + '&parent=' + encodeURIComponent(el.value), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        target.innerHTML = ''; var o = document.createElement('option'); o.value = ''; o.textContent = labels[child]; target.appendChild(o);
                        ((d && d.items) || []).forEach(function (it) { var op = document.createElement('option'); op.value = it.id; op.textContent = it.name; target.appendChild(op); });
                        target.disabled = target.options.length < 2;
                    }).catch(function () { target.disabled = true; });
            });
        });
    }

    /* ---------------------------------------------------------------- *
     | Fuel total, panel toggles, filter auto-submit for plain forms      |
     * ---------------------------------------------------------------- */
    function initCalc(form) {
        var q = form.querySelector('[data-qty]'), r = form.querySelector('[data-rate]'), t = form.querySelector('[data-total]');
        if (!q || !r || !t) { return; }
        function calc() {
            var a = parseFloat(String(q.value).replace(/,/g, '')), b = parseFloat(String(r.value).replace(/,/g, ''));
            t.value = (isFinite(a) && isFinite(b)) ? (a * b).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '';
        }
        q.addEventListener('input', calc); r.addEventListener('input', calc); calc();
    }

    function initToggles() {
        qsa('[data-toggle-target]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var panel = document.getElementById(btn.getAttribute('data-toggle-target'));
                if (!panel) { return; }
                panel.hidden = !panel.hidden;
                btn.setAttribute('aria-expanded', String(!panel.hidden));
                if (!panel.hidden) { var f = panel.querySelector('input:not([type=hidden]),select,textarea'); if (f) { f.focus(); } }
            });
        });
    }

    function boot() {
        qsa('[data-picker]').forEach(initPicker);
        qsa('[data-geo]').forEach(initGeo);
        qsa('form').forEach(initCalc);
        initToggles();
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})(window, document);
