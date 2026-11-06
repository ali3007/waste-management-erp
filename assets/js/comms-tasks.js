/**
 * comms-tasks.js — task form helpers: searchable employee multi-select, checklist builder, template
 * fill, ERP record search, one-click checklist ticking. Server text goes in with textContent only.
 * Everything here is a convenience: the server re-validates every field.
 */
(function (window, document) {
    'use strict';
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
    function postJson(picker, data) {
        return fetch(picker.getAttribute('data-url'), { method: 'POST', credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': picker.getAttribute('data-csrf'), 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data).toString() }).then(function (r) { return r.json(); });
    }

    /* ---------- employee multi-select ---------- */
    var ap = document.querySelector('[data-assignee-picker]');
    if (ap) {
        var chips = ap.querySelector('[data-chips]'), q = ap.querySelector('[data-people-q]'), results = ap.querySelector('[data-people-results]'), timer = null;
        var selected = function () { var m = {}; Array.prototype.forEach.call(chips.querySelectorAll('.comms-chip'), function (c) { m[c.getAttribute('data-id')] = true; }); return m; };
        chips.addEventListener('click', function (e) { if (e.target.hasAttribute('data-chip-remove')) { e.target.parentNode.remove(); search(); } });
        function addChip(p) {
            var c = el('span', 'comms-chip'); c.setAttribute('data-id', p.id); c.appendChild(document.createTextNode(p.name));
            var b = el('button', '', '×'); b.type = 'button'; b.setAttribute('data-chip-remove', '1'); b.setAttribute('aria-label', 'Remove ' + p.name); c.appendChild(b);
            var h = document.createElement('input'); h.type = 'hidden'; h.name = 'assignees[]'; h.value = p.id; c.appendChild(h); chips.appendChild(c);
        }
        function search() {
            postJson(ap, { action: 'people', q: q.value }).then(function (j) {
                results.textContent = '';
                if (!j.ok || !j.people.length) { results.appendChild(el('p', 'comms-muted', 'No eligible employees found.')); return; }
                var sel = selected();
                j.people.forEach(function (p) {
                    if (sel[p.id]) { return; }
                    var row = el('button', 'comms-person'); row.type = 'button';
                    row.appendChild(el('strong', '', p.name + (p.self ? ' (you)' : ''))); row.appendChild(el('small', '', p.role + (p.code ? ' · ' + p.code : '')));
                    row.addEventListener('click', function () { addChip(p); row.remove(); }); results.appendChild(row);
                });
            }).catch(function () { results.textContent = 'Search is unavailable. Check your connection.'; });
        }
        q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(search, 250); });
        q.addEventListener('focus', function () { if (!results.children.length) { search(); } });
    }

    /* ---------- checklist builder ---------- */
    var ce = document.querySelector('[data-checklist-editor]');
    if (ce) {
        var rows = ce.querySelector('[data-checklist-rows]');
        var renumber = function () {
            Array.prototype.forEach.call(rows.children, function (row, i) {
                Array.prototype.forEach.call(row.querySelectorAll('[data-f]'), function (inp) { inp.name = 'checklist[' + i + '][' + inp.getAttribute('data-f') + ']'; });
            });
        };
        var addRow = function (text, required, key) {
            var row = el('div', 'comms-clrow');
            var k = document.createElement('input'); k.type = 'hidden'; k.setAttribute('data-f', 'key'); k.value = key || ''; row.appendChild(k);
            var t = document.createElement('input'); t.type = 'text'; t.className = 'input'; t.maxLength = 255; t.placeholder = 'Step'; t.setAttribute('data-f', 'text'); t.value = text || ''; row.appendChild(t);
            var lab = el('label', 'comms-check'); var cb = document.createElement('input'); cb.type = 'checkbox'; cb.value = '1'; cb.setAttribute('data-f', 'required'); cb.checked = required !== false; lab.appendChild(cb); lab.appendChild(document.createTextNode(' Required')); row.appendChild(lab);
            [['↑', 'data-cl-up'], ['↓', 'data-cl-down'], ['Remove', 'data-cl-del']].forEach(function (x) { var b = el('button', 'comms-link', x[0]); b.type = 'button'; b.setAttribute(x[1], '1'); row.appendChild(b); });
            rows.appendChild(row); renumber(); return t;
        };
        ce.querySelector('[data-cl-add]').addEventListener('click', function () { addRow('', true).focus(); });
        ce.addEventListener('click', function (e) {
            var row = e.target.closest ? e.target.closest('.comms-clrow') : null; if (!row) { return; }
            if (e.target.hasAttribute('data-cl-del')) { row.remove(); }
            else if (e.target.hasAttribute('data-cl-up') && row.previousElementSibling) { rows.insertBefore(row, row.previousElementSibling); }
            else if (e.target.hasAttribute('data-cl-down') && row.nextElementSibling) { rows.insertBefore(row.nextElementSibling, row); }
            renumber();
        });
        renumber();
        window.__commsSetChecklist = function (items) { rows.textContent = ''; (items || []).forEach(function (i) { addRow(i.text, !!i.required, ''); }); };
    }

    /* ---------- template fill ---------- */
    var ts = document.querySelector('[data-template-select]');
    if (ts) {
        var templates = []; try { templates = JSON.parse(ts.getAttribute('data-templates') || '[]'); } catch (e) { templates = []; }
        ts.addEventListener('change', function () {
            var t = templates.filter(function (x) { return String(x.id) === ts.value; })[0]; if (!t) { return; }
            var set = function (id, v) { var n = document.getElementById(id); if (n && v !== null && v !== undefined) { n.value = v; } };
            set('title', t.title || ''); set('description', t.description || ''); set('priority', t.priority); set('evidence_required', String(t.evidence));
            if (window.__commsSetChecklist) { window.__commsSetChecklist(t.checklist); }
        });
    }

    /* ---------- ERP record link ---------- */
    var lp = document.querySelector('[data-link-picker]');
    if (lp) {
        var typeSel = lp.querySelector('[data-link-type]'), lq = lp.querySelector('[data-link-q]'), lres = lp.querySelector('[data-link-results]');
        var th = lp.querySelector('[data-link-type-h]'), ih = lp.querySelector('[data-link-id-h]'), chosen = lp.querySelector('[data-link-chosen]'), lt = null;
        typeSel.addEventListener('change', function () { th.value = typeSel.value; ih.value = ''; chosen.textContent = ''; lres.textContent = ''; lq.hidden = !typeSel.value; lq.value = ''; if (typeSel.value) { lq.focus(); } });
        lq.hidden = !typeSel.value;
        lq.addEventListener('input', function () {
            clearTimeout(lt);
            lt = setTimeout(function () {
                if (lq.value.trim().length < 2) { lres.textContent = ''; return; }
                postJson(lp, { action: 'link_search', type: typeSel.value, q: lq.value }).then(function (j) {
                    lres.textContent = '';
                    if (!j.ok || !j.results.length) { lres.appendChild(el('p', 'comms-muted', 'No matching records you can access.')); return; }
                    j.results.forEach(function (r) {
                        var b = el('button', 'comms-person', r.label); b.type = 'button';
                        b.addEventListener('click', function () { ih.value = r.id; th.value = typeSel.value; chosen.textContent = 'Linked: ' + r.label; lres.textContent = ''; });
                        lres.appendChild(b);
                    });
                });
            }, 250);
        });
    }

    /* ---------- misc ---------- */
    Array.prototype.forEach.call(document.querySelectorAll('[data-step-box]'), function (box) { box.addEventListener('change', function () { box.form.submit(); }); });
    var range = document.querySelector('[data-range]'), out = document.querySelector('[data-range-out]');
    if (range && out) { range.addEventListener('input', function () { out.textContent = range.value + '%'; }); }
    Array.prototype.forEach.call(document.querySelectorAll('[data-confirm-btn]'), function (b) { b.addEventListener('click', function (e) { if (!window.confirm(b.getAttribute('data-confirm-btn'))) { e.preventDefault(); } }); });
    Array.prototype.forEach.call(document.querySelectorAll('[data-once]'), function (b) { if (b.form) { b.form.addEventListener('submit', function () { b.disabled = true; }); } });
})(window, document);
