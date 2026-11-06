/**
 * transport-charts.js — Transport dashboard charts (Chart.js, already vendored).
 * Hover for exact values, click a legend entry to hide it, click a slice or
 * bar to open the matching vehicle list. Data arrives as JSON from the server;
 * nothing is hard-coded here.
 */
(function (window, document) {
    'use strict';
    var node = document.getElementById('trn-data');
    if (!node || !window.Chart) { return; }

    var state = JSON.parse(node.textContent);
    var cfg = state.config;
    var charts = {};
    var FALLBACK = ['#17a06a', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#ec4899', '#64748b'];

    function css(name, fb) { var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim(); return v || fb; }
    function color(i) { return css('--chart-' + ((i % 8) + 1), FALLBACK[i % 8]); }
    function text() { return css('--text-muted', '#5f6e69'); }
    function grid() { return css('--border', 'rgba(0,0,0,.08)'); }
    function money(v) { return (cfg.currency ? cfg.currency + ' ' : '') + Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 }); }

    var drill = { by_type: 'type', by_status: 'status', by_region: 'region', by_district: 'district', by_tehsil: 'tehsil', by_uc: 'uc' };

    function href(key, item) {
        if (!cfg.canVehicles) { return null; }
        var base = cfg.vehiclesUrl + (cfg.query || '');
        var sep = base.indexOf('?') === -1 ? '?' : '&';
        if (drill[key] && item.id) { return base + sep + drill[key] + '=' + item.id; }
        if (key === 'deployment') { return base + sep + 'deployed=' + (item.label === 'Deployed' ? 'yes' : 'no'); }
        if (key === 'drivers') { return base + sep + 'has_driver=' + (item.label === 'With a driver' ? 'yes' : 'no'); }
        return null;
    }

    function empty(canvas, on) {
        var box = canvas.parentNode; var el = box.querySelector('.trn-chart-empty');
        if (on && !el) { el = document.createElement('div'); el.className = 'trn-chart-empty'; el.textContent = 'No data for the current filters.'; box.appendChild(el); }
        if (!on && el) { el.remove(); }
        canvas.style.visibility = on ? 'hidden' : 'visible';
    }

    function make(key, type, items, opts) {
        var canvas = document.getElementById('ch-' + key); if (!canvas) { return; }
        var has = items.some(function (i) { return i.value > 0; });
        empty(canvas, !has);
        if (charts[key]) { charts[key].destroy(); }
        if (!has) { return; }
        var labels = items.map(function (i) { return i.label; });
        var values = items.map(function (i) { return i.value; });
        var colors = items.map(function (_, i) { return color(i); });
        var isDonut = type === 'doughnut';
        var horizontal = type === 'bar-h';
        charts[key] = new Chart(canvas, {
            type: isDonut ? 'doughnut' : (type === 'line' ? 'line' : 'bar'),
            data: { labels: labels, datasets: [{
                label: opts.label || 'Vehicles', data: values,
                backgroundColor: isDonut ? colors : (type === 'line' ? color(0) + '33' : color(opts.c || 0)),
                borderColor: type === 'line' ? color(opts.c || 0) : 'transparent', borderWidth: type === 'line' ? 2 : 0,
                fill: type === 'line', tension: .3, borderRadius: isDonut || type === 'line' ? 0 : 6, maxBarThickness: 38
            }] },
            options: {
                responsive: true, maintainAspectRatio: false, indexAxis: horizontal ? 'y' : 'x',
                cutout: isDonut ? '62%' : undefined,
                plugins: {
                    legend: { display: isDonut, position: 'bottom', labels: { color: text(), boxWidth: 12 } },
                    tooltip: { callbacks: { label: function (c) { var v = c.parsed.y !== undefined && !horizontal ? c.parsed.y : (horizontal ? c.parsed.x : c.parsed); return ' ' + (opts.money ? money(v) : Number(v).toLocaleString()) + (opts.suffix || ''); } } }
                },
                scales: isDonut ? {} : {
                    x: { ticks: { color: text() }, grid: { display: horizontal, color: grid() }, beginAtZero: true },
                    y: { ticks: { color: text() }, grid: { display: !horizontal, color: grid() }, beginAtZero: true }
                },
                onClick: function (e, els) { if (els.length) { var u = href(key, items[els[0].index]); if (u) { window.location.href = u; } } },
                onHover: function (e, els) { e.native.target.style.cursor = (els.length && href(key, items[els[0].index])) ? 'pointer' : 'default'; }
            }
        });
    }

    function draw() {
        var c = state.charts;
        make('by_type', 'doughnut', c.by_type, {});
        make('by_status', 'doughnut', c.by_status, {});
        make('by_region', 'bar-h', c.by_region, { c: 1 });
        make('by_district', 'bar-h', c.by_district, { c: 2 });
        make('by_tehsil', 'bar-h', c.by_tehsil, { c: 3 });
        make('by_uc', 'bar-h', c.by_uc, { c: 4 });
        make('deployment', 'doughnut', c.deployment, {});
        make('drivers', 'doughnut', c.drivers, {});
        make('maintenance_cost', 'bar', c.maintenance_cost, { label: 'Maintenance cost', money: true, c: 2 });
        make('fuel_cost', 'line', c.fuel_cost, { label: 'Fuel cost', money: true, c: 0 });
        make('fuel_litres', 'bar', c.fuel_litres, { label: 'Fuel', suffix: ' ' + cfg.volume, c: 1 });
    }
    draw();

    /* Refresh: same filters, fresh numbers, no page reload. */
    var btn = document.getElementById('trn-refresh');
    if (btn) {
        btn.addEventListener('click', function () {
            var url = cfg.dataUrl + window.location.search;
            btn.disabled = true; document.body.classList.add('trn-loading');
            fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.ok) { return; }
                    state.charts = d.charts; draw();
                    var set = function (id, v) { var el = document.getElementById(id); if (el) { el.textContent = v; } };
                    set('trn-tot-maint', money(d.totals.maintenance_cost)); set('trn-tot-fuel', money(d.totals.fuel_cost));
                    set('trn-tot-litres', Number(d.totals.fuel_litres).toLocaleString(undefined, { maximumFractionDigits: 1 }) + ' ' + cfg.volume);
                    var vals = document.querySelectorAll('#trn-cards .stat__value');
                    var order = ['total', 'active', 'assigned', 'available', 'maintenance', 'out_of_service', 'inactive', 'docs_expiring'];
                    order.forEach(function (k, i) { if (vals[i]) { vals[i].textContent = Number(d.cards[k]).toLocaleString(); } });
                }).catch(function () {}).then(function () { btn.disabled = false; document.body.classList.remove('trn-loading'); });
        });
    }
})(window, document);
