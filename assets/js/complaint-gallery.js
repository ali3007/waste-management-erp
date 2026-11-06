/**
 * complaint-gallery.js — Before / After evidence galleries.
 *
 *   - lazy thumbnail grids (the markup uses loading="lazy")
 *   - a modal viewer: previous/next, keyboard, swipe, zoom, pan, full-size link
 *   - drag-and-drop reordering, saved to the server (this order is the order
 *     used by the PowerPoint)
 *   - folder / multi-file upload in small batches with progress
 *   - removing a picture
 *
 * The browser only ever loads thumbnails for the grid and the ~1600 px
 * preview for the viewer; the original is fetched only on request.
 */
(function (window, document) {
    'use strict';

    var Cmp = window.Cmp;
    var root = document.getElementById('complaint-root');
    if (!Cmp || !root) { return; }

    var complaintId = root.getAttribute('data-complaint-id');
    var uploadUrl = root.getAttribute('data-evidence-url');
    var orderUrl = root.getAttribute('data-order-url');
    var IMAGE_RE = /\.(jpe?g|png|webp)$/i;

    /* ---------------------------------------------------------------- *
     | Viewer                                                            |
     * ---------------------------------------------------------------- */
    var viewer = document.getElementById('viewer');
    var vImg = viewer.querySelector('[data-v-img]');
    var vStage = viewer.querySelector('[data-v-stage]');
    var state = { items: [], index: 0, scale: 1, x: 0, y: 0 };

    function apply() {
        vImg.style.transform = 'translate(' + state.x + 'px,' + state.y + 'px) scale(' + state.scale + ')';
        vStage.classList.toggle('is-zoomed', state.scale > 1);
    }
    function resetZoom() { state.scale = 1; state.x = 0; state.y = 0; apply(); }
    function zoom(delta) {
        state.scale = Math.max(1, Math.min(6, state.scale + delta));
        if (state.scale === 1) { state.x = 0; state.y = 0; }
        apply();
    }

    function show(index) {
        if (!state.items.length) { return; }
        state.index = (index + state.items.length) % state.items.length;
        var item = state.items[state.index];
        resetZoom();
        vImg.src = item.preview;
        vImg.alt = item.title;
        viewer.querySelector('[data-v-title]').textContent = item.title;
        viewer.querySelector('[data-v-counter]').textContent = (state.index + 1) + ' / ' + state.items.length;
        viewer.querySelector('[data-v-full]').href = item.full;
        /* Warm the neighbours so next/previous feel instant. */
        [state.index + 1, state.index - 1].forEach(function (i) {
            var n = state.items[(i + state.items.length) % state.items.length];
            if (n && n !== item) { (new Image()).src = n.preview; }
        });
    }

    function openViewer(gallery, figure) {
        var figures = Cmp.qsa('.cmp-thumb', gallery);
        var label = gallery.getAttribute('data-kind') === 'before' ? 'Before' : 'After';
        state.items = figures.map(function (fig, i) {
            return { preview: fig.getAttribute('data-preview'), full: fig.getAttribute('data-full'), title: label + ' picture ' + (i + 1) + ' — ' + fig.getAttribute('data-name') };
        });
        viewer.hidden = false;
        document.body.classList.add('cmp-modal-open');
        show(figures.indexOf(figure));
    }
    function closeViewer() {
        viewer.hidden = true;
        vImg.removeAttribute('src');
        if (!Cmp.qs('.cmp-modal:not([hidden])')) { document.body.classList.remove('cmp-modal-open'); }
    }

    viewer.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-v]');
        if (btn) {
            var act = btn.getAttribute('data-v');
            if (act === 'close') { closeViewer(); }
            else if (act === 'next') { show(state.index + 1); }
            else if (act === 'prev') { show(state.index - 1); }
            else if (act === 'zoom-in') { zoom(0.5); }
            else if (act === 'zoom-out') { zoom(-0.5); }
            else if (act === 'reset') { resetZoom(); }
            return;
        }
        if (e.target === vStage) { closeViewer(); }
    });
    document.addEventListener('keydown', function (e) {
        if (viewer.hidden) { return; }
        if (e.key === 'Escape') { closeViewer(); }
        else if (e.key === 'ArrowRight') { show(state.index + 1); }
        else if (e.key === 'ArrowLeft') { show(state.index - 1); }
        else if (e.key === '+' || e.key === '=') { zoom(0.5); }
        else if (e.key === '-') { zoom(-0.5); }
    });
    vStage.addEventListener('wheel', function (e) { e.preventDefault(); zoom(e.deltaY < 0 ? 0.35 : -0.35); }, { passive: false });
    vImg.addEventListener('dblclick', function () { if (state.scale > 1) { resetZoom(); } else { zoom(1.5); } });

    /* Pan when zoomed (mouse + touch); swipe to navigate when not zoomed. */
    var drag = null;
    function pointerDown(x, y) { drag = { x: x, y: y, ox: state.x, oy: state.y, sx: x, moved: false }; }
    function pointerMove(x, y) {
        if (!drag) { return; }
        if (state.scale > 1) { state.x = drag.ox + (x - drag.x); state.y = drag.oy + (y - drag.y); apply(); }
        if (Math.abs(x - drag.sx) > 6) { drag.moved = true; }
    }
    function pointerUp(x) {
        if (drag && state.scale === 1 && drag.moved) {
            var dx = x - drag.sx;
            if (dx < -60) { show(state.index + 1); } else if (dx > 60) { show(state.index - 1); }
        }
        drag = null;
    }
    vStage.addEventListener('mousedown', function (e) { pointerDown(e.clientX, e.clientY); });
    window.addEventListener('mousemove', function (e) { pointerMove(e.clientX, e.clientY); });
    window.addEventListener('mouseup', function (e) { pointerUp(e.clientX); });
    vStage.addEventListener('touchstart', function (e) { if (e.touches.length === 1) { pointerDown(e.touches[0].clientX, e.touches[0].clientY); } }, { passive: true });
    vStage.addEventListener('touchmove', function (e) { if (e.touches.length === 1) { pointerMove(e.touches[0].clientX, e.touches[0].clientY); } }, { passive: true });
    vStage.addEventListener('touchend', function (e) { pointerUp(e.changedTouches[0].clientX); });

    /* ---------------------------------------------------------------- *
     | Galleries                                                         |
     * ---------------------------------------------------------------- */
    function renumber(gallery) {
        var figs = Cmp.qsa('.cmp-thumb', gallery);
        figs.forEach(function (f, i) { Cmp.qs('[data-no]', f).textContent = i + 1; });
        Cmp.qs('[data-count]', gallery).textContent = figs.length;
        var empty = Cmp.qs('[data-empty]', gallery);
        if (empty) { empty.hidden = figs.length > 0; }
    }

    function makeThumb(item, kind, editable) {
        var fig = document.createElement('figure');
        fig.className = 'cmp-thumb';
        fig.setAttribute('data-id', item.id);
        fig.setAttribute('data-preview', item.preview);
        fig.setAttribute('data-full', item.full);
        fig.setAttribute('data-name', item.name);
        if (editable) { fig.draggable = true; }
        var img = document.createElement('img');
        img.loading = 'lazy'; img.decoding = 'async'; img.src = item.thumb; img.alt = '';
        var no = document.createElement('span');
        no.className = 'cmp-thumb__no'; no.setAttribute('data-no', '');
        fig.appendChild(img); fig.appendChild(no);
        if (editable) {
            var del = document.createElement('button');
            del.type = 'button'; del.className = 'cmp-thumb__del no-print'; del.setAttribute('data-delete', '');
            del.title = 'Remove this picture'; del.setAttribute('aria-label', 'Remove picture'); del.textContent = '×';
            fig.appendChild(del);
        }
        return fig;
    }

    function saveOrder(gallery) {
        var ids = Cmp.qsa('.cmp-thumb', gallery).map(function (f) { return f.getAttribute('data-id'); });
        var fd = new FormData();
        fd.append('complaint_id', complaintId);
        fd.append('kind', gallery.getAttribute('data-kind'));
        fd.append('op', 'reorder');
        fd.append('_csrf_token', Cmp.csrf());
        ids.forEach(function (id) { fd.append('ids[]', id); });
        Cmp.post(orderUrl, fd).then(function (d) {
            if (!d.ok) { window.alert(d.message || 'The new order could not be saved.'); }
        });
    }

    Cmp.qsa('[data-gallery]').forEach(function (gallery) {
        var grid = Cmp.qs('[data-grid]', gallery);
        var kind = gallery.getAttribute('data-kind');
        var editable = gallery.getAttribute('data-editable') === '1';
        var dragEl = null, timer = null;

        grid.addEventListener('click', function (e) {
            var del = e.target.closest('[data-delete]');
            var fig = e.target.closest('.cmp-thumb');
            if (!fig) { return; }
            if (del) {
                e.stopPropagation();
                if (!window.confirm('Remove this ' + kind + ' picture? It will no longer appear in the PowerPoint.')) { return; }
                var fd = new FormData();
                fd.append('complaint_id', complaintId); fd.append('kind', kind); fd.append('op', 'delete');
                fd.append('id', fig.getAttribute('data-id')); fd.append('_csrf_token', Cmp.csrf());
                Cmp.post(orderUrl, fd).then(function (d) {
                    if (d.ok) { fig.remove(); renumber(gallery); } else { window.alert(d.message || 'The picture could not be removed.'); }
                });
                return;
            }
            openViewer(gallery, fig);
        });

        if (editable) {
            grid.addEventListener('dragstart', function (e) {
                var fig = e.target.closest('.cmp-thumb');
                if (!fig) { return; }
                dragEl = fig;
                fig.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', fig.getAttribute('data-id')); } catch (err) { /* old Edge */ }
            });
            grid.addEventListener('dragover', function (e) {
                if (!dragEl) { return; }
                e.preventDefault();
                var over = e.target.closest('.cmp-thumb');
                if (!over || over === dragEl) { return; }
                var rect = over.getBoundingClientRect();
                var after = (e.clientX - rect.left) > rect.width / 2 || (e.clientY - rect.top) > rect.height * 0.75;
                grid.insertBefore(dragEl, after ? over.nextSibling : over);
            });
            grid.addEventListener('dragend', function () {
                if (!dragEl) { return; }
                dragEl.classList.remove('is-dragging');
                dragEl = null;
                renumber(gallery);
                window.clearTimeout(timer);
                timer = window.setTimeout(function () { saveOrder(gallery); }, 350);
            });
        }

        /* Upload buttons (folder or individual files). */
        Cmp.qsa('input[data-upload]', gallery).forEach(function (input) {
            input.addEventListener('change', function () {
                var files = Array.prototype.slice.call(input.files).filter(function (f) { return IMAGE_RE.test(f.name); });
                var skipped = input.files.length - files.length;
                input.value = '';
                if (!files.length) {
                    window.alert('No JPG, PNG or WEBP pictures were found in your selection.');
                    return;
                }
                files.sort(function (a, b) {
                    return (a.webkitRelativePath || a.name).localeCompare(b.webkitRelativePath || b.name, undefined, { numeric: true, sensitivity: 'base' });
                });
                var progress = Cmp.qs('[data-upload-progress]', gallery);
                var bar = Cmp.qs('[data-progress-bar]', gallery);
                var text = Cmp.qs('[data-progress-text]', gallery);
                progress.hidden = false;
                bar.style.width = '0%';
                text.textContent = 'Uploading 0 of ' + files.length + '…';

                Cmp.uploadBatches(uploadUrl, { complaint_id: complaintId, kind: kind }, files, function (done, total, items) {
                    bar.style.width = Math.round(done / total * 100) + '%';
                    text.textContent = 'Uploaded ' + done + ' of ' + total + '…';
                    items.forEach(function (item) { grid.appendChild(makeThumb(item, kind, editable)); });
                    renumber(gallery);
                }).then(function (r) {
                    text.textContent = 'Done — ' + r.items.length + ' picture' + (r.items.length === 1 ? '' : 's') + ' added.' + (skipped ? ' ' + skipped + ' non-picture file(s) were ignored.' : '');
                    window.setTimeout(function () { progress.hidden = true; }, 3500);
                    if (r.errors.length) {
                        window.alert(r.errors.length + ' picture(s) were not uploaded:\n\n' + r.errors.slice(0, 10).map(function (e) { return e.name + ': ' + e.message; }).join('\n') + (r.errors.length > 10 ? '\n…' : ''));
                    }
                });
            });
        });
    });
})(window, document);
