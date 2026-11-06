/**
 * notifications.js — the top-bar notification bell.
 * Polls a small JSON endpoint (60 s, and when the tab becomes visible) for
 * the signed-in user's own unread count; the dropdown lists the latest
 * items. Text is inserted with textContent, never as HTML.
 */
(function (window, document) {
    'use strict';
    var bell = document.querySelector('[data-notif-bell]');
    if (!bell) { return; }

    var url = bell.getAttribute('data-url');
    var csrf = bell.getAttribute('data-csrf');
    var btn = bell.querySelector('.icon-btn');
    var count = bell.querySelector('.notif-bell__count');
    var panel = bell.querySelector('.notif-bell__panel');
    var list = bell.querySelector('[data-notif-list]');
    var timer = null;

    function render(data) {
        var n = data.unread || 0;
        count.textContent = n > 99 ? '99+' : String(n);
        count.hidden = n === 0;
        list.innerHTML = '';
        if (!data.items || !data.items.length) {
            var p = document.createElement('p');
            p.className = 'notif-bell__empty';
            p.textContent = 'No notifications yet.';
            list.appendChild(p);
            return;
        }
        data.items.forEach(function (it) {
            var a = document.createElement('a');
            a.className = 'notif-bell__item' + (it.read ? '' : ' is-unread');
            a.href = it.url;
            var t = document.createElement('strong'); t.textContent = it.title;
            var m = document.createElement('span'); m.textContent = it.message;
            var s = document.createElement('small'); s.textContent = it.ago;
            a.appendChild(t); a.appendChild(m); a.appendChild(s);
            list.appendChild(a);
        });
    }

    function request(method, body) {
        var opts = { method: method, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf } };
        if (body) { opts.body = body; }
        return window.fetch(url, opts).then(function (r) { return r.json(); }).then(render).catch(function () {});
    }
    function refresh() { return request('GET'); }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = panel.hidden;
        panel.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { refresh(); }
    });
    document.addEventListener('click', function (e) { if (!bell.contains(e.target)) { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { panel.hidden = true; } });
    bell.querySelector('[data-notif-all]').addEventListener('click', function () {
        var fd = new FormData(); fd.append('action', 'all'); fd.append('_csrf_token', csrf);
        request('POST', fd);
    });

    function schedule() { window.clearTimeout(timer); timer = window.setTimeout(function () { refresh().then(schedule); }, 60000); }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); } });
    refresh().then(schedule);
})(window, document);
