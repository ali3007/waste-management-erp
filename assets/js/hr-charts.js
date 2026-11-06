/**
 * hr-charts.js — tiny, dependency-free SVG charts for the HR dashboard.
 *
 * No library and no network: works offline on XAMPP, inherits the active
 * light/dark theme through CSS variables, and redraws when the container
 * is resized. Two chart types, chosen by data-chart on the element:
 *
 *   donut  data-series='[{"label":"Field Staff","value":120}, …]'
 *   bars   data-series='[{"label":"UC 23","value":9,"group":"Chiniot"}, …]'
 *
 * Optional: data-center="Employees" (donut caption),
 *           data-empty="message shown when every value is 0",
 *           data-colors="active,inactive" (semantic colours for status).
 */
(function (window, document) {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';
    var PALETTE = ['--chart-1', '--chart-2', '--chart-3', '--chart-4', '--chart-5', '--chart-6', '--chart-7', '--chart-8'];

    function cssVar(name, fallback) {
        var v = window.getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return v || fallback;
    }

    function colorFor(index, semantic) {
        if (semantic && semantic[index] === 'active') { return cssVar('--success', '#17a06a'); }
        if (semantic && semantic[index] === 'inactive') { return cssVar('--danger', '#d64550'); }
        return cssVar(PALETTE[index % PALETTE.length], '#17a06a');
    }

    function el(name, attrs, text) {
        var node = document.createElementNS(NS, name);
        Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function fmt(n) { return Number(n).toLocaleString(); }

    function parse(container) {
        try {
            var data = JSON.parse(container.getAttribute('data-series') || '[]');
            return Array.isArray(data) ? data.map(function (d) {
                return { label: String(d.label), value: Math.max(0, Number(d.value) || 0), group: d.group ? String(d.group) : '' };
            }) : [];
        } catch (e) { return []; }
    }

    function empty(container, message) {
        container.innerHTML = '';
        var p = document.createElement('div');
        p.className = 'chart-empty';
        p.textContent = message || 'Nothing to show yet.';
        container.appendChild(p);
    }

    /* ---------------------------------------------------------------- *
     | Donut                                                             |
     * ---------------------------------------------------------------- */
    function donut(container) {
        var data = parse(container);
        var total = data.reduce(function (s, d) { return s + d.value; }, 0);
        if (total === 0) { return empty(container, container.getAttribute('data-empty')); }

        var semantic = (container.getAttribute('data-colors') || '').split(',').filter(Boolean);
        var size = 220, cx = size / 2, cy = size / 2, r = 82, stroke = 30;
        var svg = el('svg', { viewBox: '0 0 ' + size + ' ' + size, role: 'img', 'aria-label': 'Donut chart', style: 'max-width:260px;margin:0 auto' });

        svg.appendChild(el('circle', { cx: cx, cy: cy, r: r, fill: 'none', 'stroke-width': stroke, class: 'chart__track', stroke: cssVar('--surface-sunken', '#e4e9e7') }));

        var circumference = 2 * Math.PI * r;
        var offset = 0;
        data.forEach(function (d, i) {
            if (d.value === 0) { return; }
            var len = d.value / total * circumference;
            var seg = el('circle', {
                cx: cx, cy: cy, r: r, fill: 'none', stroke: colorFor(i, semantic), 'stroke-width': stroke,
                'stroke-dasharray': Math.max(0, len - (data.length > 1 ? 2 : 0)) + ' ' + circumference,
                'stroke-dashoffset': -offset,
                transform: 'rotate(-90 ' + cx + ' ' + cy + ')'
            });
            seg.appendChild(el('title', {}, d.label + ': ' + fmt(d.value) + ' (' + Math.round(d.value / total * 100) + '%)'));
            svg.appendChild(seg);
            offset += len;
        });

        svg.appendChild(el('text', { x: cx, y: cy + 4, 'text-anchor': 'middle', 'font-size': 30, class: 'chart__center' }, fmt(total)));
        svg.appendChild(el('text', { x: cx, y: cy + 24, 'text-anchor': 'middle', 'font-size': 11 }, container.getAttribute('data-center') || 'Total'));

        container.innerHTML = '';
        container.appendChild(svg);

        var legend = document.createElement('div');
        legend.className = 'chart-legend';
        data.forEach(function (d, i) {
            var item = document.createElement('span');
            var dot = document.createElement('i');
            dot.style.background = colorFor(i, semantic);
            item.appendChild(dot);
            item.appendChild(document.createTextNode(d.label + ' — ' + fmt(d.value) + ' (' + Math.round(d.value / total * 100) + '%)'));
            legend.appendChild(item);
        });
        container.appendChild(legend);
    }

    /* ---------------------------------------------------------------- *
     | Horizontal bars                                                   |
     * ---------------------------------------------------------------- */
    function bars(container) {
        var data = parse(container);
        var max = data.reduce(function (m, d) { return Math.max(m, d.value); }, 0);
        if (data.length === 0 || max === 0) { return empty(container, container.getAttribute('data-empty')); }

        var width = Math.max(300, container.clientWidth || 520);
        var narrow = width < 460;
        var rowH = 30, gap = 8, top = 4;
        var labelW = narrow ? Math.min(110, width * 0.34) : Math.min(190, width * 0.3);
        var valueW = 48;
        var barX = labelW + 8;
        var barW = Math.max(60, width - barX - valueW);
        var height = top + data.length * (rowH + gap);
        var maxChars = Math.floor(labelW / 6.4);

        var groups = {};
        data.forEach(function (d) { if (d.group) { groups[d.group] = true; } });
        var groupNames = Object.keys(groups);

        var svg = el('svg', { viewBox: '0 0 ' + width + ' ' + height, role: 'img', 'aria-label': 'Bar chart' });

        data.forEach(function (d, i) {
            var y = top + i * (rowH + gap);
            var color = colorFor(d.group ? groupNames.indexOf(d.group) : 0, null);
            var label = d.label.length > maxChars ? d.label.slice(0, maxChars - 1) + '…' : d.label;

            var row = el('g', {});
            row.appendChild(el('title', {}, d.label + (d.group ? ' (' + d.group + ')' : '') + ': ' + fmt(d.value)));
            row.appendChild(el('text', { x: labelW, y: y + rowH / 2 + 4, 'text-anchor': 'end', 'font-size': 12 }, label));
            row.appendChild(el('rect', { x: barX, y: y + 4, width: barW, height: rowH - 8, rx: 6, class: 'chart__track' }));
            var w = d.value === 0 ? 0 : Math.max(4, d.value / max * barW);
            row.appendChild(el('rect', { x: barX, y: y + 4, width: w, height: rowH - 8, rx: 6, fill: color }));
            row.appendChild(el('text', { x: barX + barW + 8, y: y + rowH / 2 + 4, 'font-size': 12, class: 'chart__value' }, fmt(d.value)));
            svg.appendChild(row);
        });

        container.innerHTML = '';
        container.appendChild(svg);

        if (groupNames.length > 1) {
            var legend = document.createElement('div');
            legend.className = 'chart-legend';
            groupNames.forEach(function (g, i) {
                var item = document.createElement('span');
                var dot = document.createElement('i');
                dot.style.background = colorFor(i, null);
                item.appendChild(dot);
                item.appendChild(document.createTextNode(g));
                legend.appendChild(item);
            });
            container.appendChild(legend);
        }
    }

    function drawAll() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-chart]'), function (node) {
            var type = node.getAttribute('data-chart');
            if (type === 'donut') { donut(node); }
            else if (type === 'bars') { bars(node); }
        });
    }

    var timer = null;
    function redraw() {
        window.clearTimeout(timer);
        timer = window.setTimeout(drawAll, 120);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', drawAll);
    } else {
        drawAll();
    }
    window.addEventListener('resize', redraw);

    /* Theme switches change the CSS variables the charts read. */
    new MutationObserver(redraw).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
})(window, document);
