/**
 * hr.js — Human Resources module.
 *
 * Cascading selects (Region → District → Tehsil, Staff Pool → Staff
 * Type) that don't reload the page — unlike the Sites browser's filter
 * dropdowns, this sits inside a long Add/Edit Employee form, and a page
 * reload per selection would lose every other field the person typed.
 * The full active dataset is embedded as JSON by the page itself
 * (window.HR_CASCADE_DATA) since administrative hierarchies are small
 * enough to ship inline rather than round-tripping to the server.
 */
(function (window, document) {
    'use strict';

    /**
     * Wire a parent <select> to repopulate a child <select> from a flat
     * list of {id, name, <parentKey>} rows. Keeps the child's current
     * value selected if it's still valid for the new parent (used when
     * pre-filling an edit form before the person has touched anything).
     */
    function bindCascade(parentEl, childEl, rows, parentKey, placeholder) {
        if (!parentEl || !childEl) { return; }

        function repopulate(preserveValue) {
            var parentId = parentEl.value;

            /* Nothing picked yet — leave the server-rendered "choose the
               parent first" placeholder alone rather than replacing it
               with a less specific one on every page load. */
            if (parentId === '') {
                childEl.disabled = true;
                return;
            }

            var keep = preserveValue ? childEl.value : '';
            var matches = rows.filter(function (row) { return String(row[parentKey]) === String(parentId); });

            childEl.innerHTML = '';
            var placeholderOpt = document.createElement('option');
            placeholderOpt.value = '';
            placeholderOpt.textContent = placeholder;
            childEl.appendChild(placeholderOpt);

            matches.forEach(function (row) {
                var opt = document.createElement('option');
                opt.value = row.id;
                opt.textContent = row.name;
                if (String(row.id) === String(keep)) { opt.selected = true; }
                childEl.appendChild(opt);
            });

            childEl.disabled = matches.length === 0;
        }

        parentEl.addEventListener('change', function () { repopulate(false); });
        repopulate(true); // initial fill, preserving a pre-filled edit value
    }

    /**
     * AJAX-driven cascade for the Assignment screen's Region→District→
     * Tehsil→UC→Beat chain. Unlike bindCascade above, nothing is
     * embedded inline here — a country-wide UC/Beat list can run into
     * the thousands, so each level is fetched only once its parent is
     * actually picked, via modules/hr/process/cascade.php.
     */
    function bindAjaxCascade(parentEl, childEl, level, placeholder) {
        if (!parentEl || !childEl) { return; }

        function setOptions(items, keepValue) {
            childEl.innerHTML = '';
            var placeholderOpt = document.createElement('option');
            placeholderOpt.value = '';
            placeholderOpt.textContent = placeholder;
            childEl.appendChild(placeholderOpt);

            items.forEach(function (item) {
                var opt = document.createElement('option');
                opt.value = item.id;
                opt.textContent = item.name;
                if (keepValue && String(item.id) === String(keepValue)) { opt.selected = true; }
                childEl.appendChild(opt);
            });

            childEl.disabled = items.length === 0;
            childEl.dispatchEvent(new Event('change')); // let the next level in the chain react
        }

        parentEl.addEventListener('change', function () {
            var parentId = parentEl.value;
            if (parentId === '') {
                setOptions([], null);
                return;
            }

            childEl.disabled = true;
            fetch('process/cascade.php?level=' + encodeURIComponent(level) + '&parent=' + encodeURIComponent(parentId), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) { return response.json(); })
                .then(function (data) { setOptions((data && data.items) || [], null); })
                .catch(function () { setOptions([], null); });
        });
    }

    function init() {
        var data = window.HR_CASCADE_DATA;
        if (!data) { return; }

        bindCascade(document.getElementById('region_id'), document.getElementById('district_id'), data.districts || [], 'region_id', 'Choose a district…');
        bindCascade(document.getElementById('district_id'), document.getElementById('tehsil_id'), data.tehsils || [], 'district_id', 'Choose a tehsil…');
        bindCascade(document.getElementById('staff_pool_id'), document.getElementById('staff_type_id'), data.types || [], 'staff_pool_id', 'Choose a staff type…');
    }

    function initAssignment() {
        if (!window.HR_ASSIGNMENT_MODE) { return; }

        bindAjaxCascade(document.getElementById('assign_region_id'), document.getElementById('assign_district_id'), 'district', 'Choose a district…');
        bindAjaxCascade(document.getElementById('assign_district_id'), document.getElementById('assign_tehsil_id'), 'tehsil', 'Choose a tehsil…');
        bindAjaxCascade(document.getElementById('assign_tehsil_id'), document.getElementById('assign_uc_id'), 'uc', 'Choose a UC…');

        var beatSelect = document.getElementById('assign_beat_id');
        if (beatSelect) {
            bindAjaxCascade(document.getElementById('assign_uc_id'), beatSelect, 'beat', 'Choose a Beat (optional)…');
        }
    }

    /**
     * Filter panels (All Employees, Reports): a <select data-autosubmit>
     * submits its form on change. data-reset="a,b" first clears the named
     * dependent selects, so changing Region never leaves a stale District.
     */
    function initFilters() {
        var selects = document.querySelectorAll('select[data-autosubmit]');
        Array.prototype.forEach.call(selects, function (select) {
            select.addEventListener('change', function () {
                var form = select.form;
                if (!form) { return; }
                var reset = select.getAttribute('data-reset');
                if (reset) {
                    reset.split(',').forEach(function (name) {
                        var target = form.elements[name.trim()];
                        if (target) { target.value = ''; }
                    });
                }
                form.submit();
            });
        });
    }

    function boot() { init(); initAssignment(); initFilters(); }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window, document);
