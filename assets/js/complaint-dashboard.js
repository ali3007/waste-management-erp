/**
 * complaint-dashboard.js — live dashboard.
 *
 * One state object (the filters) drives everything: cards, six charts and
 * the listing are re-fetched from process/dashboard-data.php whenever a
 * filter changes or a chart/card is clicked. The URL is kept in sync, so
 * a filtered dashboard can be bookmarked or shared. No numbers live in
 * this file — everything is drawn from the server's JSON.
 */
(function (window, document) {
    'use strict';

    var Cmp = window.Cmp;
    var root = document.getElementById('cmp-dashboard');
    var form = document.getElementById('cmp-filter-form');
    if (!Cmp || !root || !form || !window.Chart) { return; }

    var FILTER_KEYS = ['q', 'range', 'date_from', 'date_to', 'region', 'district', 'tehsil', 'uc', 'beat', 'source', 'type', 'priority', 'status', 'supervisor', 'monitor', 'sla'];
    var CHIP_LABELS = { q: 'Search', date_from: 'From', date_to: 'To', region: 'Region', district: 'District', tehsil: 'Tehsil', uc: 'UC', beat: 'Beat', source: 'Source', type: 'Type', priority: 'Priority', status: 'Status', supervisor: 'Supervisor', monitor: 'Monitoring Officer', sla: 'SLA' };
    var url = root.getAttribute('data-url');
    var charts = {};
    var page = 1;
    var group = 'auto';
    var seq = 0;

    function css(name, fallback) {
        var v = window.getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return v || fallback;
    }
    var PALETTE = ['--chart-1', '--chart-2', '--chart-3', '--chart-4', '--chart-5', '--chart-6', '--chart-7', '--chart-8'];
    function color(i) { return css(PALETTE[i % PALETTE.length], '#17a06a'); }
    function alpha(c, a) { return /^#[0-9a-f]{6}$/i.test(c) ? c + a : c; }

    /* ---------------------------------------------------------------- *
     | Filters                                                           |
     * ---------------------------------------------------------------- */
    function readFilters() {
        var params = new URLSearchParams();
        FILTER_KEYS.forEach(function (k) {
            var el = form.elements[k];
            if (el && el.value !== '') { params.set(k, el.value); }
        });
        return params;
    }

    function setFilter(key, value) {
        var el = form.elements[key];
        if (!el) { return; }
        el.value = value;
    }

    function syncRangeButtons() {
        var range = form.elements.range.value;
        var hasDates = form.elements.date_from.value || form.elements.date_to.value;
        Cmp.qsa('[data-range]', form).forEach(function (b) {
            var k = b.getAttribute('data-range');
            b.classList.toggle('is-active', k === range && (k !== '' || !hasDates) || (k === 'custom' && !range && hasDates));
        });
    }

    function quickRange(kind) {
        var d = new Date(), pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var iso = function (x) { return x.getFullYear() + '-' + pad(x.getMonth() + 1) + '-' + pad(x.getDate()); };
        var today = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        if (kind === 'today') { return [iso(today), iso(today)]; }
        if (kind === 'week') {
            var mon = new Date(today); mon.setDate(today.getDate() - ((today.getDay() + 6) % 7));
            var sun = new Date(mon); sun.setDate(mon.getDate() + 6);
            return [iso(mon), iso(sun)];
        }
        if (kind === 'month') { return [iso(new Date(today.getFullYear(), today.getMonth(), 1)), iso(new Date(today.getFullYear(), today.getMonth() + 1, 0))]; }
        if (kind === 'last_month') { return [iso(new Date(today.getFullYear(), today.getMonth() - 1, 1)), iso(new Date(today.getFullYear(), today.getMonth(), 0))]; }
        return ['', ''];
    }

    function renderChips(active) {
        var box = Cmp.qs('[data-chips]', root);
        box.innerHTML = '';
        Object.keys(active).forEach(function (k) {
            if (k === 'range' || !CHIP_LABELS[k]) { return; }
            var el = form.elements[k];
            var label = el && el.tagName === 'SELECT' && el.selectedIndex > 0 ? el.options[el.selectedIndex].text : active[k];
            var chip = document.createElement('span');
            chip.className = 'cmp-chip';
            chip.appendChild(document.createTextNode(CHIP_LABELS[k] + ': ' + label));
            var x = document.createElement('button');
            x.type = 'button'; x.textContent = '×'; x.setAttribute('aria-label', 'Remove filter');
            x.addEventListener('click', function () {
                setFilter(k, '');
                if (k === 'date_from' || k === 'date_to') { setFilter('range', ''); }
                if (k === 'region') { ['district', 'tehsil', 'uc', 'beat'].forEach(function (c) { setFilter(c, ''); }); }
                if (k === 'district') { ['tehsil', 'uc', 'beat'].forEach(function (c) { setFilter(c, ''); }); }
                if (k === 'tehsil') { ['uc', 'beat'].forEach(function (c) { setFilter(c, ''); }); }
                if (k === 'uc') { setFilter('beat', ''); }
                page = 1; load();
            });
            chip.appendChild(x);
            box.appendChild(chip);
        });
    }

    /* ---------------------------------------------------------------- *
     | Charts                                                            |
     * ---------------------------------------------------------------- */
    function baseOptions() {
        var text = css('--text-muted', '#5d6f68');
        var grid = css('--border', 'rgba(0,0,0,.08)');
        return { responsive: true, maintainAspectRatio: false, animation: { duration: 250 }, plugins: { legend: { labels: { color: text, boxWidth: 12 } }, tooltip: { mode: 'nearest' } }, _text: text, _grid: grid };
    }

    function draw(id, config) {
        var canvas = document.getElementById(id);
        var box = canvas.parentNode;
        var empty = box.querySelector('.cmp-chart-empty');
        if (empty) { empty.remove(); }
        if (charts[id]) { charts[id].destroy(); delete charts[id]; }
        if (config === null) {
            canvas.style.display = 'none';
            var msg = document.createElement('div');
            msg.className = 'cmp-chart-empty';
            msg.textContent = 'No complaints to chart for these filters.';
            box.appendChild(msg);
            return;
        }
        canvas.style.display = '';
        charts[id] = new window.Chart(canvas.getContext('2d'), config);
    }

    function clickable(chart, handler) {
        chart.options.onClick = function (evt, elements) { if (elements.length) { handler(elements[0].index, elements[0].datasetIndex); } };
        chart.options.onHover = function (evt, elements) { evt.native.target.style.cursor = elements.length ? 'pointer' : 'default'; };
    }

    function applyDimension(key, value) {
        setFilter(key, String(value));
        if (key === 'uc') { /* keep ancestors as they are */ }
        page = 1;
        load();
    }

    function addDays(iso, n) {
        var d = new Date(iso + 'T00:00:00'); d.setDate(d.getDate() + n);
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }

    function renderCharts(data) {
        var o = baseOptions();
        var axes = { ticks: { color: o._text }, grid: { color: o._grid } };

        /* Trend */
        var t = data.trend;
        if (!t.values.length || t.values.every(function (v) { return v === 0; })) { draw('chart-trend', null); }
        else {
            var cfg = { type: t.group === 'day' && t.values.length > 40 ? 'line' : 'bar', data: { labels: t.labels, datasets: [{ label: 'Complaints', data: t.values, borderColor: color(0), backgroundColor: alpha(color(0), t.values.length > 40 ? '33' : 'cc'), fill: true, tension: .25, pointRadius: t.values.length > 60 ? 0 : 3, borderRadius: 4 }] },
                options: { responsive: o.responsive, maintainAspectRatio: false, animation: o.animation, plugins: { legend: { display: false } }, scales: { x: { ticks: { color: o._text, maxTicksLimit: 14, autoSkip: true }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: o._text, precision: 0 }, grid: { color: o._grid } } } } };
            draw('chart-trend', cfg);
            clickable(charts['chart-trend'], function (i) {
                var key = t.keys[i], from = key, to = key;
                if (t.group === 'week') { to = addDays(key, 6); }
                if (t.group === 'month') { var d = new Date(key + 'T00:00:00'); d.setMonth(d.getMonth() + 1); d.setDate(0); to = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
                setFilter('date_from', from); setFilter('date_to', to); setFilter('range', 'custom');
                page = 1; load();
            });
            charts['chart-trend'].update('none');
        }
        Cmp.qs('[data-trend-note]', root).textContent = t.note || '';

        /* Status doughnut */
        var st = data.by_status.filter(function (s) { return s.count > 0; });
        if (!st.length) { draw('chart-status', null); }
        else {
            draw('chart-status', { type: 'doughnut', data: { labels: st.map(function (s) { return s.label; }), datasets: [{ data: st.map(function (s) { return s.count; }), backgroundColor: st.map(function (s) { return s.color; }), borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom', labels: { color: o._text, boxWidth: 12 } } } } });
            clickable(charts['chart-status'], function (i) { applyDimension('status', st[i].slug); });
        }

        /* Source */
        var src = data.by_source;
        if (!src.length) { draw('chart-source', null); }
        else {
            draw('chart-source', { type: 'doughnut', data: { labels: src.map(function (s) { return s.label; }), datasets: [{ data: src.map(function (s) { return s.value; }), backgroundColor: src.map(function (s, i) { return color(i); }), borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '55%', plugins: { legend: { position: 'bottom', labels: { color: o._text, boxWidth: 12 } } } } });
            clickable(charts['chart-source'], function (i) { applyDimension('source', src[i].id); });
        }

        /* Type */
        var ty = data.by_type;
        if (!ty.length) { draw('chart-type', null); }
        else {
            draw('chart-type', { type: 'bar', data: { labels: ty.map(function (s) { return s.label; }), datasets: [{ label: 'Complaints', data: ty.map(function (s) { return s.value; }), backgroundColor: color(1), borderRadius: 4 }] },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { color: o._text, precision: 0 }, grid: { color: o._grid } }, y: { ticks: { color: o._text }, grid: { display: false } } } } });
            clickable(charts['chart-type'], function (i) { applyDimension('type', ty[i].id); });
        }

        /* UC */
        var uc = data.by_uc;
        Cmp.qs('[data-uc-note]', root).textContent = data.uc_total > uc.length ? '(top ' + uc.length + ' of ' + data.uc_total + ')' : '';
        if (!uc.length) { draw('chart-uc', null); }
        else {
            draw('chart-uc', { type: 'bar', data: { labels: uc.map(function (s) { return s.label; }), datasets: [{ label: 'Complaints', data: uc.map(function (s) { return s.value; }), backgroundColor: color(2), borderRadius: 4 }] },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { afterLabel: function (ctx) { return uc[ctx.dataIndex].sub; } } } }, scales: { x: { beginAtZero: true, ticks: { color: o._text, precision: 0 }, grid: { color: o._grid } }, y: { ticks: { color: o._text }, grid: { display: false } } } } });
            clickable(charts['chart-uc'], function (i) { applyDimension('uc', uc[i].id); });
        }

        /* UC × status (stacked) */
        var us = data.uc_status;
        if (!us.length) { draw('chart-ucstatus', null); }
        else {
            var series = [['pending', 'Pending', '#3a7bd5'], ['progress', 'In Progress', '#d9902b'], ['resolved', 'Resolved', '#17a06a'], ['reopened', 'Reopened', '#d6657f'], ['rejected', 'Rejected', '#8a948f']];
            draw('chart-ucstatus', { type: 'bar', data: { labels: us.map(function (s) { return s.label; }), datasets: series.map(function (s) { return { label: s[1], data: us.map(function (r) { return r[s[0]]; }), backgroundColor: s[2], borderRadius: 2 }; }) },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: o._text, boxWidth: 12 } } }, scales: { x: { stacked: true, ticks: { color: o._text }, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: { color: o._text, precision: 0 }, grid: { color: o._grid } } } } });
            clickable(charts['chart-ucstatus'], function (i) { applyDimension('uc', us[i].id); });
        }
    }

    /* ---------------------------------------------------------------- *
     | Cards + listing                                                   |
     * ---------------------------------------------------------------- */
    function renderCards(data) {
        Cmp.qs('[data-total]', root).textContent = Number(data.cards.total).toLocaleString();
        ['within', 'nearing', 'breached'].forEach(function (k) { Cmp.qs('[data-sla="' + k + '"]', root).textContent = Number(data.cards.sla[k]).toLocaleString(); });
        var box = Cmp.qs('[data-status-cards]', root);
        box.innerHTML = '';
        var active = form.elements.status.value;
        data.cards.status.forEach(function (s) {
            var card = document.createElement('div');
            card.className = 'card card--flat stat-card stat-card--status' + (active === s.slug ? ' is-active' : '');
            card.style.setProperty('--pill', s.color);
            card.setAttribute('role', 'button'); card.tabIndex = 0;
            var l = document.createElement('div'); l.className = 'stat__label'; l.textContent = s.label;
            var v = document.createElement('div'); v.className = 'stat__value'; v.textContent = Number(s.count).toLocaleString();
            card.appendChild(l); card.appendChild(v);
            var go = function () { setFilter('status', active === s.slug ? '' : s.slug); page = 1; load(); };
            card.addEventListener('click', go);
            card.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
            box.appendChild(card);
        });
    }

    function renderList(data) {
        Cmp.qs('[data-list]', root).innerHTML = data.list_html;
        Cmp.qs('[data-pager]', root).innerHTML = data.pager_html;
        Cmp.qs('[data-list-meta]', root).textContent = Number(data.total).toLocaleString() + ' complaint' + (data.total === 1 ? '' : 's') + ' · page ' + data.page;
        Cmp.qsa('[data-pager] a[data-page]', root).forEach(function (a) {
            a.addEventListener('click', function (e) { e.preventDefault(); page = parseInt(a.getAttribute('data-page'), 10); load(false); });
        });
    }

    /* ---------------------------------------------------------------- *
     | Load                                                              |
     * ---------------------------------------------------------------- */
    function load(rebuildAll) {
        var mine = ++seq;
        var params = readFilters();
        if (group !== 'auto') { params.set('group', group); }
        params.set('page', String(page));
        root.classList.add('cmp-loading');

        var shown = readFilters();
        window.history.replaceState(null, '', window.location.pathname + (shown.toString() ? '?' + shown.toString() : ''));
        var reportsLink = Cmp.qs('[data-reports-link]');
        if (reportsLink) { reportsLink.href = root.getAttribute('data-reports-url') + (shown.toString() ? '?' + shown.toString() : ''); }
        syncRangeButtons();

        window.fetch(url + '?' + params.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (mine !== seq) { return; }          // a newer request superseded this one
                root.classList.remove('cmp-loading');
                if (!data.ok) { window.alert(data.message || 'The dashboard could not be loaded.'); return; }
                renderChips(data.filters);
                renderCards(data);
                renderList(data);
                if (rebuildAll !== false) { renderCharts(data); }
            })
            .catch(function () { if (mine === seq) { root.classList.remove('cmp-loading'); } });
    }

    /* Wiring */
    form.addEventListener('submit', function (e) { e.preventDefault(); page = 1; load(); });
    Cmp.qsa('select, input[type=date]', form).forEach(function (el) {
        el.addEventListener('change', function () {
            if (el.name === 'date_from' || el.name === 'date_to') { setFilter('range', 'custom'); }
            page = 1;
            /* cascade children are refreshed by complaints.js; wait a tick so a reset child is read as empty */
            window.setTimeout(load, 60);
        });
    });
    Cmp.qs('[data-clear-filters]', form).addEventListener('click', function (e) {
        e.preventDefault();
        FILTER_KEYS.forEach(function (k) { if (form.elements[k]) { form.elements[k].value = ''; } });
        page = 1; load();
    });
    Cmp.qsa('[data-range]', form).forEach(function (b) {
        b.addEventListener('click', function () {
            var kind = b.getAttribute('data-range');
            if (kind === 'custom') { setFilter('range', 'custom'); form.elements.date_from.focus(); syncRangeButtons(); return; }
            var r = quickRange(kind);
            setFilter('range', kind); setFilter('date_from', r[0]); setFilter('date_to', r[1]);
            page = 1; load();
        });
    });
    Cmp.qsa('[data-group] button', root).forEach(function (b) {
        b.addEventListener('click', function () {
            group = b.getAttribute('data-g');
            Cmp.qsa('[data-group] button', root).forEach(function (x) { x.classList.toggle('is-active', x === b); });
            load();
        });
    });
    var themeObserver = new MutationObserver(function () { load(); });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    /* The server rendered the filter form from the URL, so just load. */
    load();
})(window, document);
