/**
 * comms-chat.js — Communication › Chat (text, files, voice, reactions, forward, search, pins).
 * AJAX polling only (interval comes from the server setting); no WebSocket, no external service.
 * All text from the server is inserted with textContent. Unsent text survives a refresh; a retried
 * send reuses its uuid so the server can never store it twice. Files/recordings wait in memory.
 */
(function (window, document) {
    'use strict';
    var root = document.querySelector('[data-comms-chat]');
    if (!root) { return; }

    var URL_ = root.getAttribute('data-url'), CSRF = root.getAttribute('data-csrf'), ME = parseInt(root.getAttribute('data-me'), 10);
    var interval = parseInt(root.getAttribute('data-interval'), 10) * 1000;
    var MAXMB = parseInt(root.getAttribute('data-max-mb'), 10), VOICE_MAX = parseInt(root.getAttribute('data-voice-max'), 10);
    var EXTS = (root.getAttribute('data-exts') || '').split(',').filter(Boolean);
    var EMOJI = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
    var q = function (sel, ctx) { return (ctx || root).querySelector(sel); };
    var D = function (sel) { return document.querySelector(sel); };
    var listEl = q('[data-comms-list]'), resultsEl = q('[data-comms-results]'), conn = q('[data-comms-conn]'), pane = q('[data-comms-pane]');
    var placeholder = q('[data-comms-placeholder]'), msgsEl = q('[data-comms-msgs]');
    var titleEl = q('[data-comms-title]'), subEl = q('[data-comms-sub]'), typingEl = q('[data-comms-typing]');
    var form = q('[data-comms-form]'), input = q('[data-comms-input]'), avatar = q('[data-comms-avatar]');
    var replyBar = q('[data-comms-replybar]'), replyText = q('[data-comms-replytext]'), pinsEl = q('[data-comms-pins]');
    var membersBtn = q('[data-comms-members]'), storageEl = q('[data-comms-storage]');
    var fileInput = q('[data-comms-file]'), attachBtn = q('[data-comms-attach]'), micBtn = q('[data-comms-mic]');

    var state = { conv: 0, lastId: 0, firstId: 0, hasOlder: false, seen: {}, convs: [], timer: null, busy: false, replyTo: 0, tracked: {},
                  loadingOlder: false, lastTyping: 0, lastReadSent: 0, failures: 0, since: null, isGroup: false, storageFull: false, menu: null };
    var KEY_P = 'comms.pending.' + ME, KEY_D = 'comms.draft.' + ME + '.';
    var uploads = [];   // files/voice waiting to be sent (memory only)

    function load(k, d) { try { var v = window.localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } }
    function save(k, v) { try { window.localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
    function drop(k) { try { window.localStorage.removeItem(k); } catch (e) {} }
    var pending = load(KEY_P, []);

    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
    function btn(label, cls, fn) { var b = el('button', cls || 'comms-link', label); b.type = 'button'; if (fn) { b.addEventListener('click', fn); } return b; }
    function uuid() {
        var b = new Uint8Array(16);
        if (window.crypto && window.crypto.getRandomValues) { window.crypto.getRandomValues(b); } else { for (var i = 0; i < 16; i++) { b[i] = Math.floor(Math.random() * 256); } }
        b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128;
        var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
        return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
    }
    function fmtSize(n) { return n < 1024 ? n + ' B' : (n < 1048576 ? Math.round(n / 1024) + ' KB' : (n / 1048576).toFixed(1) + ' MB'); }
    function fmtDur(s) { return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }
    function post(data) {
        var isForm = typeof FormData !== 'undefined' && data instanceof FormData;
        var headers = { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF };
        if (!isForm) { headers['Content-Type'] = 'application/x-www-form-urlencoded'; }
        return fetch(URL_, { method: 'POST', credentials: 'same-origin', headers: headers, body: isForm ? data : new URLSearchParams(data).toString() })
            .then(function (r) {
                if (r.status === 401) { setConn('Session expired — sign in again.', 'bad'); }
                return r.json().then(function (j) { j._status = r.status; return j; }, function () { return { ok: false, message: 'Unexpected server reply (' + r.status + ').', _status: r.status }; });
            });
    }
    function setConn(text, kind) { conn.textContent = text; conn.className = 'comms-conn' + (kind ? ' comms-conn--' + kind : ''); }
    function modal(id, open) { var m = document.getElementById(id); if (m) { m.classList.toggle('is-open', open); } }

    /* ---------- conversation list ---------- */
    function renderList(convs) {
        state.convs = convs;
        listEl.textContent = '';
        if (!convs.length) { listEl.appendChild(el('p', 'comms-muted', 'No conversations yet.')); return; }
        convs.forEach(function (c) {
            var b = el('button', 'comms-conv' + (c.id === state.conv ? ' is-active' : '')); b.type = 'button';
            var top = el('span', 'comms-conv__top'), name = el('strong', '', c.title);
            if (c.type === 'private') { name.insertBefore(el('i', 'comms-dot' + (c.online ? ' is-on' : '')), name.firstChild); }
            top.appendChild(name); top.appendChild(el('small', '', c.time)); b.appendChild(top);
            var bot = el('span', 'comms-conv__bot'); bot.appendChild(el('span', 'comms-conv__preview', c.preview));
            if (c.unread > 0) { bot.appendChild(el('span', 'badge badge--active comms-unread', c.unread > 99 ? '99+' : String(c.unread))); }
            b.appendChild(bot); b.addEventListener('click', function () { openConv(c.id); }); listEl.appendChild(b);
        });
        var total = convs.reduce(function (n, c) { return n + c.unread; }, 0);
        document.title = (total > 0 ? '(' + total + ') ' : '') + document.title.replace(/^\(\d+\)\s*/, '');
    }
    function convById(id) { for (var i = 0; i < state.convs.length; i++) { if (state.convs[i].id === id) { return state.convs[i]; } } return null; }

    /* ---------- message bubble ---------- */
    function openMenu(m, anchor) {
        closeMenu();
        var menu = el('div', 'comms-menu');
        var row = el('div', 'comms-menu__emoji');
        if (!m.deleted) { EMOJI.forEach(function (e) { row.appendChild(btn(e, 'comms-emoji', function () { closeMenu(); post({ action: 'react', message_id: m.id, emoji: e }).then(function () { pollSoon(0); }); })); }); menu.appendChild(row); }
        var add = function (label, fn) { menu.appendChild(btn(label, 'comms-menu__item', function () { closeMenu(); fn(); })); };
        if (!m.deleted) {
            add('Reply', function () { state.replyTo = m.id; replyText.textContent = 'Replying to ' + m.sender + ': ' + (m.body || (m.file ? m.file.name : '')).slice(0, 60); replyBar.hidden = false; input.focus(); });
            add('Forward', function () { openForward(m); });
            if (m.can_edit) { add('Edit', function () { editInline(m); }); }
            if (m.can_pin) { add(m.pinned ? 'Unpin' : 'Pin', function () { post({ action: 'pin', message_id: m.id, on: m.pinned ? '0' : '1' }).then(function (j) { if (!j.ok) { window.alert(j.message); } pollSoon(0); }); }); }
            if (m.can_announce) { add(m.announcement ? 'Remove announcement' : 'Make announcement', function () { post({ action: 'announce', message_id: m.id, on: m.announcement ? '0' : '1' }).then(function (j) { if (!j.ok) { window.alert(j.message); } pollSoon(0); }); }); }
            if (m.can_readers) { add('Who has read this', function () { openReaders(m); }); }
            if (m.can_delete) { add('Delete for everyone', function () { if (window.confirm('Delete this message for everyone?')) { post({ action: 'delete', message_id: m.id }).then(function (j) { if (!j.ok) { window.alert(j.message); } pollSoon(0); }); } }); }
        }
        add('Delete for me', function () { if (window.confirm('Remove this message from your view only?')) { post({ action: 'hide', message_id: m.id }).then(function () { var n = msgsEl.querySelector('.comms-msg[data-id="' + m.id + '"]'); if (n) { n.remove(); } }); } });
        anchor.parentNode.appendChild(menu); state.menu = menu;
    }
    function closeMenu() { if (state.menu) { state.menu.remove(); state.menu = null; } }
    document.addEventListener('click', function (e) { if (state.menu && !state.menu.contains(e.target) && !(e.target.getAttribute && e.target.getAttribute('data-menu-btn'))) { closeMenu(); } });

    function fileLink(attId, download) { return post({ action: 'file_link', attachment_id: attId, download: download ? '1' : '0' }); }
    function openPreview(f) {
        fileLink(f.id, false).then(function (j) {
            if (!j.ok) { window.alert(j.message || 'This file is not available.'); return; }
            var box = q('[data-comms-preview]', document); box.textContent = '';
            if (f.image) { var im = el('img', 'comms-preview__img'); im.alt = f.name; im.src = j.url; box.appendChild(im); }
            else if (f.pdf) { var fr = el('iframe', 'comms-preview__frame'); fr.src = j.url; fr.title = f.name; box.appendChild(fr); }
            else if (f.kind === 'voice') { var au = el('audio'); au.controls = true; au.src = j.url; box.appendChild(au); }
            var dl = q('[data-comms-preview-dl]', document); dl.onclick = function () { downloadFile(f); };
            modal('comms-modal-preview', true);
        });
    }
    function downloadFile(f) { fileLink(f.id, true).then(function (j) { if (j.ok) { window.location.href = j.url; } else { window.alert(j.message || 'This file is not available.'); } }); }

    function bubble(m, opts) {
        opts = opts || {};
        var cls = 'comms-msg' + (m.mine ? ' is-mine' : '') + (m.deleted ? ' is-deleted' : '') + (m.announcement ? ' is-announce' : '');
        var wrap = el('div', cls); wrap.setAttribute('data-id', m.id || '');
        if (m.announcement) { wrap.appendChild(el('div', 'comms-msg__tag', 'Announcement')); }
        if (m.forwarded) { wrap.appendChild(el('div', 'comms-msg__tag', 'Forwarded')); }
        if (!m.mine && state.isGroup) { wrap.appendChild(el('div', 'comms-msg__name', m.sender)); }
        if (m.reply) { var r = el('div', 'comms-msg__quote'); r.appendChild(el('strong', '', m.reply.name)); r.appendChild(el('span', '', m.reply.body)); wrap.appendChild(r); }
        if (m.deleted) { wrap.appendChild(el('div', 'comms-msg__body comms-muted', 'This message was deleted')); }
        else {
            if (m.file) {
                var f = m.file, chip = el('div', 'comms-file');
                if (f.kind === 'voice') {
                    chip.appendChild(btn('Play voice message · ' + fmtDur(f.dur || 0), 'btn btn--ghost', function () { openPreview(f); }));
                } else {
                    var info = el('div', 'comms-file__info'); info.appendChild(el('strong', '', f.name)); info.appendChild(el('small', '', fmtSize(f.size))); chip.appendChild(info);
                    if (f.inline) { chip.appendChild(btn('Preview', 'comms-link', function () { openPreview(f); })); }
                    chip.appendChild(btn('Download', 'comms-link', function () { downloadFile(f); }));
                }
                wrap.appendChild(chip);
            }
            if (m.body) { wrap.appendChild(el('div', 'comms-msg__body', m.body)); }
            if (m.reactions && m.reactions.length) {
                var rx = el('div', 'comms-react');
                m.reactions.forEach(function (x) {
                    var b = btn(x.e + ' ' + x.n, 'comms-react__item' + (x.mine ? ' is-mine' : ''), function () { post({ action: 'react', message_id: m.id, emoji: x.e }).then(function () { pollSoon(0); }); });
                    rx.appendChild(b);
                });
                wrap.appendChild(rx);
            }
        }
        var meta = el('div', 'comms-msg__meta'); meta.appendChild(el('span', '', m.time));
        if (m.edited) { meta.appendChild(el('span', '', 'edited')); }
        if (m.pinned) { meta.appendChild(el('span', '', 'pinned')); }
        if (m.mine && !m.deleted) { var st = el('span', 'comms-msg__state', opts.state || 'Sent'); st.setAttribute('data-state', ''); meta.appendChild(st); }
        if (!opts.pending && m.id) { var mb = btn('⋯', 'comms-link', function () { openMenu(m, mb); }); mb.setAttribute('data-menu-btn', '1'); mb.setAttribute('aria-label', 'Message actions'); meta.appendChild(mb); }
        wrap.appendChild(meta);
        wrap._m = m;
        return wrap;
    }
    function replaceBubble(m) {
        var old = msgsEl.querySelector('.comms-msg[data-id="' + m.id + '"]');
        if (!old) { return; }
        var stOld = old.querySelector('[data-state]');
        var fresh = bubble(m, { state: stOld ? stOld.textContent : 'Sent' });
        var stNew = fresh.querySelector('[data-state]');
        if (stOld && stNew) { stNew.className = stOld.className; }
        old.parentNode.replaceChild(fresh, old);
    }
    function editInline(m) {
        var node = msgsEl.querySelector('.comms-msg[data-id="' + m.id + '"]'); if (!node) { return; }
        var body = node.querySelector('.comms-msg__body') || el('div', 'comms-msg__body'); if (!body.parentNode) { node.insertBefore(body, node.querySelector('.comms-msg__meta')); }
        var ta = el('textarea', 'input'); ta.value = m.body; ta.rows = 3; ta.maxLength = 4000;
        var bar = el('div'), err = el('div', 'comms-error'); err.hidden = true;
        bar.appendChild(btn('Save', 'btn btn--primary', function () {
            post({ action: 'edit', message_id: m.id, body: ta.value }).then(function (j) { if (!j.ok) { err.textContent = j.message; err.hidden = false; return; } pollSoon(0); replaceBubble(m); });
        }));
        bar.appendChild(btn('Cancel', 'btn btn--ghost', function () { replaceBubble(m); }));
        body.textContent = ''; body.appendChild(ta); body.appendChild(err); body.appendChild(bar); ta.focus();
    }

    function dayDivider(text) { return el('div', 'comms-day', text); }
    function lastDay() { var d = msgsEl.querySelectorAll('.comms-day'); return d.length ? d[d.length - 1].textContent : ''; }
    function pendingAnchor() { return msgsEl.querySelector('.comms-msg.is-pending'); }
    function appendMessages(list, lastRead) {
        var nearBottom = msgsEl.scrollHeight - msgsEl.scrollTop - msgsEl.clientHeight < 80;
        var dividerDone = !!msgsEl.querySelector('.comms-newdiv');
        list.forEach(function (m) {
            if (state.seen[m.id]) { return; }
            state.seen[m.id] = true;
            if (m.day !== lastDay()) { msgsEl.insertBefore(dayDivider(m.day), pendingAnchor()); }
            if (!dividerDone && !m.mine && lastRead !== null && m.id > lastRead) { msgsEl.insertBefore(el('div', 'comms-newdiv', 'New messages'), pendingAnchor()); dividerDone = true; }
            msgsEl.insertBefore(bubble(m), pendingAnchor());
            if (m.id > state.lastId) { state.lastId = m.id; }
            if (!state.firstId || m.id < state.firstId) { state.firstId = m.id; }
            if (m.mine) { state.tracked[m.id] = true; }
        });
        if (nearBottom || lastRead === null) { msgsEl.scrollTop = msgsEl.scrollHeight; }
    }

    /* ---------- open a conversation ---------- */
    function openConv(id, aroundId) {
        state.conv = id; state.lastId = 0; state.firstId = 0; state.seen = {}; state.tracked = {}; state.replyTo = 0; state.since = null; replyBar.hidden = true;
        var c = convById(id); state.isGroup = !!(c && c.type === 'group');
        placeholder.hidden = true; pane.hidden = false; root.classList.add('is-open');
        titleEl.textContent = c ? c.title : ''; subEl.textContent = ''; membersBtn.hidden = !state.isGroup; pinsEl.hidden = true;
        msgsEl.textContent = ''; input.value = load(KEY_D + id, '') || '';
        loadAvatar(c); renderList(state.convs);
        var req = aroundId ? { action: 'fetch', conversation_id: id, asc: '1', after: Math.max(0, aroundId - 16), limit: 40 } : { action: 'fetch', conversation_id: id, limit: 30 };
        post(req).then(function (j) {
            if (!j.ok || state.conv !== id) { if (!j.ok) { msgsEl.appendChild(el('p', 'comms-muted', j.message || 'Could not load messages.')); } return; }
            state.hasOlder = j.has_older; state.since = j.now_ms;
            appendMessages(j.messages, aroundId ? null : j.last_read);
            if (aroundId) { jumpTo(aroundId); } else { msgsEl.scrollTop = msgsEl.scrollHeight; }
            renderPending(); markRead(); pollSoon(0);
        }).catch(function () { setConn('Offline — retrying…', 'bad'); });
        updateSub();
    }
    function loadAvatar(c, force) {
        avatar.hidden = true;
        if (c && c.type === 'group' && (c.photo || force)) { post({ action: 'photo_link', conversation_id: c.id }).then(function (j) { if (j.ok && state.conv === c.id) { avatar.src = j.url + '&r=' + Date.now(); avatar.hidden = false; } }); }
    }
    function jumpTo(id) {
        var n = msgsEl.querySelector('.comms-msg[data-id="' + id + '"]');
        if (n) { n.scrollIntoView({ block: 'center' }); n.classList.add('is-flash'); setTimeout(function () { n.classList.remove('is-flash'); }, 2000); return; }
        openConv(state.conv, id);
    }
    function updateSub() {
        var c = convById(state.conv); if (!c) { return; }
        titleEl.textContent = c.title;
        subEl.textContent = c.type === 'group' ? c.members + ' members' : (c.online ? 'Online' : (c.last_seen ? 'Last seen ' + c.last_seen : 'Offline'));
    }
    function markRead() {
        if (!state.conv || !state.lastId || document.hidden || state.lastReadSent >= state.lastId) { return; }
        state.lastReadSent = state.lastId; post({ action: 'read', conversation_id: state.conv, up_to: state.lastId });
    }
    function renderPins(pins) {
        pinsEl.textContent = '';
        if (!pins || !pins.length) { pinsEl.hidden = true; return; }
        pinsEl.hidden = false;
        pins.slice(0, 3).forEach(function (p) { pinsEl.appendChild(btn('Pinned: ' + p.text, 'comms-pin', function () { jumpTo(p.id); })); });
    }

    msgsEl.addEventListener('scroll', function () {
        if (msgsEl.scrollTop > 40 || !state.hasOlder || state.loadingOlder || !state.conv) { return; }
        state.loadingOlder = true;
        var id = state.conv, h = msgsEl.scrollHeight;
        post({ action: 'fetch', conversation_id: id, before: state.firstId, limit: 30 }).then(function (j) {
            state.loadingOlder = false;
            if (!j.ok || state.conv !== id) { return; }
            state.hasOlder = j.has_older;
            var frag = document.createDocumentFragment(), first = msgsEl.firstChild, day = '';
            j.messages.forEach(function (m) {
                if (state.seen[m.id]) { return; }
                state.seen[m.id] = true;
                if (m.day !== day) { frag.appendChild(dayDivider(m.day)); day = m.day; }
                frag.appendChild(bubble(m));
                if (!state.firstId || m.id < state.firstId) { state.firstId = m.id; }
                if (m.mine) { state.tracked[m.id] = true; }
            });
            msgsEl.insertBefore(frag, first); msgsEl.scrollTop = msgsEl.scrollHeight - h;
        }).catch(function () { state.loadingOlder = false; });
    });

    /* ---------- sending: text queue (persisted) + uploads (memory) ---------- */
    function pendingNode(label, stateText, failed, onRetry, onDiscard) {
        var node = bubble({ mine: true, body: label, time: '', sender: 'You', reply: null }, { pending: true, state: stateText });
        node.classList.add('is-pending');
        if (failed) {
            node.classList.add('is-failed'); node.appendChild(el('div', 'comms-error', failed));
            node.appendChild(btn('Retry', 'comms-link', onRetry)); node.appendChild(btn('Discard', 'comms-link', onDiscard));
        }
        return node;
    }
    function renderPending() {
        Array.prototype.slice.call(msgsEl.querySelectorAll('.comms-msg.is-pending')).forEach(function (n) { n.remove(); });
        pending.filter(function (p) { return p.conv === state.conv; }).forEach(function (p) {
            var node = pendingNode(p.body, p.failed ? 'Not sent' : 'Pending…', p.failed,
                function () { p.failed = ''; save(KEY_P, pending); renderPending(); flush(); },
                function () { pending = pending.filter(function (x) { return x !== p; }); save(KEY_P, pending); renderPending(); });
            node.setAttribute('data-uuid', p.uuid); msgsEl.appendChild(node);
        });
        uploads.filter(function (u) { return u.conv === state.conv; }).forEach(function (u) {
            var label = (u.kind === 'voice' ? 'Voice message (' + fmtDur(u.dur) + ')' : u.file.name + ' (' + fmtSize(u.file.size) + ')') + (u.body ? ' — ' + u.body : '');
            var node = pendingNode(label, u.failed ? 'Not sent' : 'Uploading…', u.failed,
                function () { u.failed = ''; renderPending(); flush(); },
                function () { uploads = uploads.filter(function (x) { return x !== u; }); renderPending(); });
            msgsEl.appendChild(node);
        });
        msgsEl.scrollTop = msgsEl.scrollHeight;
    }
    var flushing = false;
    function afterSend(ok) { if (ok) { renderPending(); pollSoon(0); } flushing = false; flush(); }
    function flush() {
        if (flushing) { return; }
        var t = pending.filter(function (p) { return !p.failed; })[0];
        if (t) {
            flushing = true;
            post({ action: 'send', conversation_id: t.conv, body: t.body, uuid: t.uuid, reply_to: t.replyTo || 0 }).then(function (j) {
                if (j.ok) { pending = pending.filter(function (x) { return x !== t; }); save(KEY_P, pending); afterSend(true); }
                else if (j._status === 419 || j._status === 401) { flushing = false; setConn(j.message, 'bad'); }
                else { t.failed = j.message || 'The message could not be sent.'; save(KEY_P, pending); renderPending(); afterSend(false); }
            }).catch(function () { flushing = false; setConn('Offline — your message will be sent when the connection returns.', 'bad'); });
            return;
        }
        var u = uploads.filter(function (x) { return !x.failed; })[0];
        if (u) {
            flushing = true;
            var fd = new FormData(); fd.append('action', u.kind === 'voice' ? 'send_voice' : 'send_file'); fd.append('conversation_id', u.conv); fd.append('uuid', u.uuid); fd.append('reply_to', u.replyTo || 0);
            if (u.kind === 'voice') { fd.append('duration', u.dur); fd.append('file', u.file, u.file.name || 'voice.webm'); } else { fd.append('body', u.body || ''); fd.append('file', u.file, u.file.name); }
            post(fd).then(function (j) {
                if (j.ok) { uploads = uploads.filter(function (x) { return x !== u; }); afterSend(true); }
                else if (j._status === 419 || j._status === 401) { flushing = false; setConn(j.message, 'bad'); }
                else { u.failed = j.message || 'The upload failed.'; renderPending(); afterSend(false); }
            }).catch(function () { flushing = false; setConn('Offline — the upload will resume when the connection returns.', 'bad'); });
        }
    }
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var body = input.value.replace(/\s+$/, '');
        if (!body.trim() || !state.conv) { return; }
        pending.push({ uuid: uuid(), conv: state.conv, body: body, replyTo: state.replyTo, failed: '' }); save(KEY_P, pending);
        input.value = ''; drop(KEY_D + state.conv); state.replyTo = 0; replyBar.hidden = true; autosize(); renderPending(); flush();
    });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); if (form.requestSubmit) { form.requestSubmit(); } else { form.dispatchEvent(new Event('submit', { cancelable: true })); } } });
    function autosize() { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 140) + 'px'; }
    input.addEventListener('input', function () {
        autosize();
        if (state.conv) { if (input.value) { save(KEY_D + state.conv, input.value); } else { drop(KEY_D + state.conv); } }
        var now = Date.now();
        if (state.conv && input.value && now - state.lastTyping > 3000) { state.lastTyping = now; post({ action: 'typing', conversation_id: state.conv }).catch(function () {}); }
    });
    q('[data-comms-replycancel]').addEventListener('click', function () { state.replyTo = 0; replyBar.hidden = true; });
    q('[data-comms-back]').addEventListener('click', function () { root.classList.remove('is-open'); });

    /* ---------- attach a file ---------- */
    function queueUpload(item) { item.uuid = uuid(); item.conv = state.conv; item.replyTo = state.replyTo; item.failed = ''; state.replyTo = 0; replyBar.hidden = true; uploads.push(item); renderPending(); flush(); }
    if (attachBtn) {
        attachBtn.addEventListener('click', function () { if (state.storageFull) { window.alert('Storage is full. Files cannot be sent until an administrator frees space.'); return; } fileInput.click(); });
        fileInput.addEventListener('change', function () {
            var f = fileInput.files && fileInput.files[0]; fileInput.value = '';
            if (!f || !state.conv) { return; }
            var ext = (f.name.split('.').pop() || '').toLowerCase();
            if (EXTS.indexOf(ext) < 0) { window.alert('This file type is not allowed. Allowed: ' + EXTS.join(', ') + '.'); return; }
            if (f.size > MAXMB * 1048576) { window.alert('This file is larger than the ' + MAXMB + ' MB limit.'); return; }
            if (f.size === 0) { window.alert('The file is empty.'); return; }
            queueUpload({ kind: 'file', file: f, body: input.value.trim() }); if (input.value.trim()) { input.value = ''; drop(KEY_D + state.conv); autosize(); }
        });
    }

    /* ---------- voice recording ---------- */
    var rec = { stream: null, mr: null, chunks: [], t0: 0, timer: null, blob: null, dur: 0 };
    var recBox = q('[data-comms-rec]'), recTime = q('[data-comms-rec-time]'), recAudio = q('[data-comms-rec-audio]');
    var recStop = q('[data-comms-rec-stop]'), recSend = q('[data-comms-rec-send]'), recCancel = q('[data-comms-rec-cancel]');
    function recReset() {
        clearInterval(rec.timer); if (rec.mr && rec.mr.state === 'recording') { try { rec.mr.onstop = null; rec.mr.stop(); } catch (e) {} }
        if (rec.stream) { rec.stream.getTracks().forEach(function (t) { t.stop(); }); }
        rec = { stream: null, mr: null, chunks: [], t0: 0, timer: null, blob: null, dur: 0 };
        recBox.hidden = true; recAudio.hidden = true; recAudio.removeAttribute('src'); recSend.hidden = true; recStop.hidden = false; recTime.textContent = '0:00';
    }
    function recFinish() {
        clearInterval(rec.timer);
        if (rec.stream) { rec.stream.getTracks().forEach(function (t) { t.stop(); }); }
        rec.dur = Math.max(1, Math.round((Date.now() - rec.t0) / 1000));
        rec.blob = new Blob(rec.chunks, { type: (rec.mr && rec.mr.mimeType) || 'audio/webm' });
        recStop.hidden = true; recSend.hidden = false; recAudio.hidden = false; recAudio.src = URL.createObjectURL(rec.blob); recTime.textContent = fmtDur(rec.dur);
    }
    if (micBtn) {
        micBtn.addEventListener('click', function () {
            if (!state.conv) { return; }
            if (state.storageFull) { window.alert('Storage is full. Voice messages cannot be sent until an administrator frees space.'); return; }
            if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof window.MediaRecorder === 'undefined') {
                window.alert('Voice recording needs a secure connection (https://) and a supported browser. Ask the administrator to enable HTTPS — see the setup guide.'); return;
            }
            recReset();
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                rec.stream = stream;
                var type = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'].filter(function (t) { return window.MediaRecorder.isTypeSupported && window.MediaRecorder.isTypeSupported(t); })[0];
                rec.mr = type ? new window.MediaRecorder(stream, { mimeType: type }) : new window.MediaRecorder(stream);
                rec.mr.ondataavailable = function (e) { if (e.data && e.data.size) { rec.chunks.push(e.data); } };
                rec.mr.onstop = recFinish;
                rec.mr.start(); rec.t0 = Date.now(); recBox.hidden = false;
                rec.timer = setInterval(function () {
                    var s = Math.round((Date.now() - rec.t0) / 1000); recTime.textContent = fmtDur(s);
                    if (s >= VOICE_MAX && rec.mr && rec.mr.state === 'recording') { rec.mr.stop(); }
                }, 250);
            }).catch(function (err) {
                var n = err && err.name;
                window.alert(n === 'NotAllowedError' || n === 'SecurityError' ? 'Microphone access was blocked. Allow the microphone for this site in your browser settings and try again.' : (n === 'NotFoundError' ? 'No microphone was found on this device.' : 'The microphone could not be started.'));
            });
        });
        recStop.addEventListener('click', function () { if (rec.mr && rec.mr.state === 'recording') { rec.mr.stop(); } });
        recCancel.addEventListener('click', recReset);
        recSend.addEventListener('click', function () {
            if (!rec.blob) { return; }
            var ext = /ogg/.test(rec.blob.type) ? 'ogg' : (/mp4/.test(rec.blob.type) ? 'm4a' : 'webm');
            var file = rec.blob; try { file = new File([rec.blob], 'voice.' + ext, { type: rec.blob.type }); } catch (e) { file.name = 'voice.' + ext; }
            var dur = rec.dur; recReset(); queueUpload({ kind: 'voice', file: file, dur: dur });
        });
    }

    /* ---------- polling ---------- */
    function applyReceipts(r) {
        Object.keys(r || {}).forEach(function (id) {
            var n = msgsEl.querySelector('.comms-msg[data-id="' + id + '"] [data-state]'); if (!n) { return; }
            var x = r[id];
            n.textContent = x.state === 'read' ? 'Read' : (x.state === 'delivered' ? 'Delivered' : 'Sent');
            if (state.isGroup && x.total > 0) { n.textContent += ' · ' + x.read + '/' + x.total; }
            n.className = 'comms-msg__state' + (x.state === 'read' ? ' is-read' : ''); n.setAttribute('data-state', x.state);
            if (x.state === 'read') { delete state.tracked[id]; }
        });
    }
    function applyStorage(s) {
        state.storageFull = !!(s && s.full);
        storageEl.hidden = !(s && (s.full || s.warn));
        storageEl.textContent = s && s.full ? 'Storage is full. Files and voice messages cannot be sent until an administrator frees space.' : 'Storage is almost full.';
        if (attachBtn) { attachBtn.disabled = state.storageFull; } if (micBtn) { micBtn.disabled = state.storageFull; }
    }
    function poll() {
        if (state.busy) { return; }
        state.busy = true;
        var data = { action: 'poll' };
        if (state.conv) { data.conversation_id = state.conv; data.after = state.lastId; data.track = Object.keys(state.tracked).slice(-100).join(','); if (state.since !== null) { data.since = Math.max(0, state.since - 3000); } }
        post(data).then(function (j) {
            state.busy = false;
            if (!j.ok) { setConn(j.message || 'Connection problem.', 'bad'); schedule(); return; }
            state.failures = 0; setConn('Connected', 'ok');
            if (j.interval) { interval = j.interval * 1000; }
            renderList(j.conversations); applyStorage(j.storage);
            if (j.lost_access) { state.conv = 0; pane.hidden = true; placeholder.hidden = false; root.classList.remove('is-open'); }
            if (state.conv) {
                if (j.messages && j.messages.length) { appendMessages(j.messages, null); markRead(); }
                (j.updates || []).forEach(function (m) { if (state.seen[m.id]) { replaceBubble(m); } });
                if (j.now_ms) { state.since = j.now_ms; }
                renderPins(j.pins); applyReceipts(j.receipts);
                typingEl.textContent = j.typing && j.typing.length ? j.typing.join(', ') + (j.typing.length > 1 ? ' are typing…' : ' is typing…') : '';
                updateSub();
            }
            flush(); schedule();
        }).catch(function () { state.busy = false; state.failures++; setConn('Offline — retrying…', 'bad'); schedule(); });
    }
    function schedule() { clearTimeout(state.timer); state.timer = setTimeout(poll, interval * (document.hidden ? 3 : 1) * (state.failures > 0 ? Math.min(6, state.failures + 1) : 1)); }
    function pollSoon(ms) { clearTimeout(state.timer); state.timer = setTimeout(poll, ms); }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { markRead(); pollSoon(0); } });
    window.addEventListener('online', function () { pollSoon(0); });
    window.addEventListener('offline', function () { setConn('Offline — messages will wait and send when you reconnect.', 'bad'); });

    /* ---------- search ---------- */
    var searchEl = q('[data-comms-search]'), searchT = null;
    searchEl.addEventListener('input', function () {
        clearTimeout(searchT);
        var term = searchEl.value.trim();
        if (term.length < 2) { resultsEl.hidden = true; listEl.hidden = false; return; }
        searchT = setTimeout(function () {
            post({ action: 'search', q: term }).then(function (j) {
                resultsEl.textContent = ''; resultsEl.hidden = false; listEl.hidden = true;
                if (!j.ok || !j.results.length) { resultsEl.appendChild(el('p', 'comms-muted', j.ok ? 'No matches.' : j.message)); return; }
                j.results.forEach(function (r) {
                    var b = el('button', 'comms-conv'); b.type = 'button';
                    var top = el('span', 'comms-conv__top'); top.appendChild(el('strong', '', r.conversation)); top.appendChild(el('small', '', r.when)); b.appendChild(top);
                    b.appendChild(el('span', 'comms-conv__preview', (r.sender ? r.sender + ': ' : '') + r.snippet));
                    b.addEventListener('click', function () { searchEl.value = ''; resultsEl.hidden = true; listEl.hidden = false; openConv(r.conversation_id, r.id); });
                    resultsEl.appendChild(b);
                });
            });
        }, 300);
    });

    /* ---------- forward / readers ---------- */
    var fwd = { msg: null, picks: {} };
    function openForward(m) {
        fwd = { msg: m, picks: {} };
        var box = q('[data-comms-forward-list]', document); box.textContent = ''; q('[data-comms-forward-error]', document).hidden = true;
        state.convs.forEach(function (c) {
            var row = el('button', 'comms-person'); row.type = 'button'; row.appendChild(el('strong', '', c.title)); row.appendChild(el('small', '', c.type === 'group' ? 'Group' : 'Private chat'));
            row.addEventListener('click', function () {
                if (fwd.picks[c.id]) { delete fwd.picks[c.id]; row.classList.remove('is-selected'); }
                else if (Object.keys(fwd.picks).length < 5) { fwd.picks[c.id] = true; row.classList.add('is-selected'); }
            });
            box.appendChild(row);
        });
        modal('comms-modal-forward', true);
    }
    q('[data-comms-forward-go]', document).addEventListener('click', function () {
        var err = q('[data-comms-forward-error]', document); err.hidden = true;
        if (!fwd.msg || !Object.keys(fwd.picks).length) { err.textContent = 'Choose at least one conversation.'; err.hidden = false; return; }
        post({ action: 'forward', message_id: fwd.msg.id, targets: Object.keys(fwd.picks).join(',') }).then(function (j) {
            if (!j.ok) { err.textContent = j.message || 'Could not forward.'; err.hidden = false; return; }
            if (j.errors && j.errors.length) { err.textContent = 'Forwarded, but: ' + j.errors.join(' '); err.hidden = false; } else { modal('comms-modal-forward', false); }
            pollSoon(0);
        });
    });
    function openReaders(m) {
        var box = q('[data-comms-readers]', document); box.textContent = 'Loading…'; modal('comms-modal-readers', true);
        post({ action: 'readers', message_id: m.id }).then(function (j) {
            box.textContent = '';
            if (!j.ok) { box.appendChild(el('p', 'comms-error', j.message)); return; }
            box.appendChild(el('strong', '', 'Read (' + j.read.length + ')')); box.appendChild(el('p', 'comms-muted', j.read.join(', ') || 'No one yet'));
            box.appendChild(el('strong', '', 'Not read yet (' + j.unread.length + ')')); box.appendChild(el('p', 'comms-muted', j.unread.join(', ') || 'No one'));
        });
    }

    /* ---------- directory + modals (new chat / group / members) ---------- */
    var picks = { group: {} };
    function loadDir(kind, term, onPick, selectedMap) {
        var box = q('[data-comms-dir="' + kind + '"]', document);
        post({ action: 'directory', q: term || '' }).then(function (j) {
            box.textContent = '';
            if (!j.ok || !j.people.length) { box.appendChild(el('p', 'comms-muted', 'No one found.')); return; }
            j.people.forEach(function (p) {
                var row = el('button', 'comms-person' + (selectedMap && selectedMap[p.id] ? ' is-selected' : '')); row.type = 'button';
                row.appendChild(el('strong', '', p.name)); row.appendChild(el('small', '', p.role + (p.code ? ' · ' + p.code : '')));
                row.addEventListener('click', function () { onPick(p, row); }); box.appendChild(row);
            });
        });
    }
    function wireSearch(kind, fn) { var inp = q('[data-comms-dir-q="' + kind + '"]', document), t = null; inp.addEventListener('input', function () { clearTimeout(t); t = setTimeout(fn, 250); }); }
    function openAndShow(convId) { post({ action: 'poll' }).then(function (pj) { if (pj.ok) { renderList(pj.conversations); } openConv(convId); }); }
    var np = q('[data-comms-new-private]');
    if (np) { np.addEventListener('click', function () { modal('comms-modal-private', true); privateDir(); }); wireSearch('private', privateDir); }
    function privateDir() {
        loadDir('private', q('[data-comms-dir-q="private"]', document).value, function (p) {
            post({ action: 'open_private', user_id: p.id }).then(function (j) { if (!j.ok) { window.alert(j.message); return; } modal('comms-modal-private', false); openAndShow(j.conversation_id); });
        });
    }
    var ng = q('[data-comms-new-group]');
    if (ng) {
        ng.addEventListener('click', function () { picks.group = {}; countGroup(); q('[data-comms-group-name]', document).value = ''; modal('comms-modal-group', true); groupDir(); });
        wireSearch('group', groupDir);
        q('[data-comms-group-create]', document).addEventListener('click', function () {
            var err = q('[data-comms-group-error]', document); err.hidden = true;
            post({ action: 'create_group', name: q('[data-comms-group-name]', document).value, members: Object.keys(picks.group).join(',') }).then(function (j) {
                if (!j.ok) { err.textContent = j.message; err.hidden = false; return; } modal('comms-modal-group', false); openAndShow(j.conversation_id);
            });
        });
    }
    function countGroup() { q('[data-comms-group-count]', document).textContent = Object.keys(picks.group).length + ' selected'; }
    function groupDir() {
        loadDir('group', q('[data-comms-dir-q="group"]', document).value, function (p, row) {
            if (picks.group[p.id]) { delete picks.group[p.id]; row.classList.remove('is-selected'); } else { picks.group[p.id] = true; row.classList.add('is-selected'); } countGroup();
        }, picks.group);
    }
    membersBtn.addEventListener('click', function () { modal('comms-modal-members', true); loadMembers(); });
    wireSearch('add', function () { addDir(); });
    function loadMembers() {
        var box = q('[data-comms-memberlist]', document), err = q('[data-comms-members-error]', document), addWrap = q('[data-comms-addwrap]', document), mw = q('[data-comms-managewrap]', document);
        err.hidden = true;
        post({ action: 'members', conversation_id: state.conv }).then(function (j) {
            box.textContent = '';
            if (!j.ok) { err.textContent = j.message; err.hidden = false; return; }
            j.members.forEach(function (m) {
                var row = el('div', 'comms-person comms-person--row'); row.appendChild(el('strong', '', m.name + (m.manager ? ' (manager)' : '')));
                if (j.can_manage && !m.manager) {
                    row.appendChild(btn('Remove', 'comms-link', function () {
                        if (!window.confirm('Remove ' + m.name + ' from this group? They will lose access to its messages.')) { return; }
                        post({ action: 'group_remove', conversation_id: state.conv, user_id: m.id }).then(function (r) { if (!r.ok) { err.textContent = r.message; err.hidden = false; } loadMembers(); pollSoon(0); });
                    }));
                }
                box.appendChild(row);
            });
            addWrap.hidden = !j.can_manage; mw.hidden = !j.can_manage;
            if (j.can_manage) { q('[data-comms-rename]', document).value = titleEl.textContent; addDir(); }
        });
    }
    function addDir() {
        loadDir('add', q('[data-comms-dir-q="add"]', document).value, function (p) {
            post({ action: 'group_add', conversation_id: state.conv, user_id: p.id }).then(function (r) {
                var err = q('[data-comms-members-error]', document); if (!r.ok) { err.textContent = r.message; err.hidden = false; } else { err.hidden = true; } loadMembers(); pollSoon(0);
            });
        });
    }
    q('[data-comms-rename-go]', document).addEventListener('click', function () {
        var err = q('[data-comms-members-error]', document);
        post({ action: 'rename', conversation_id: state.conv, name: q('[data-comms-rename]', document).value }).then(function (j) { if (!j.ok) { err.textContent = j.message; err.hidden = false; } else { err.hidden = true; pollSoon(0); } });
    });
    var photoIn = q('[data-comms-photo]', document);
    q('[data-comms-photo-go]', document).addEventListener('click', function () { photoIn.click(); });
    photoIn.addEventListener('change', function () {
        var f = photoIn.files && photoIn.files[0]; photoIn.value = ''; if (!f) { return; }
        var fd = new FormData(); fd.append('action', 'group_photo'); fd.append('conversation_id', state.conv); fd.append('file', f, f.name);
        post(fd).then(function (j) { var err = q('[data-comms-members-error]', document); if (!j.ok) { err.textContent = j.message; err.hidden = false; } else { err.hidden = true; loadAvatar(convById(state.conv), true); pollSoon(0); } });
    });

    poll();
})(window, document);
