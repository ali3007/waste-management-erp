<?php
/**
 * Chat actions (Phase 2b): message listing/shaping, edit, delete, delete-for-me, reactions, pinning,
 * announcements, forwarding, reader lists, search, group name/photo.
 * Same rules as chat.php: the acting user id is explicit and every function re-checks everything.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const COMMS_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

function comms_now_ms(): int
{
    return (int)Database::fetchValue('SELECT FLOOR(UNIX_TIMESTAMP(NOW(3)) * 1000)');
}

function comms_touch_message(int $id): void
{
    Database::execute('UPDATE comms_messages SET changed_ms = :t WHERE id = :i', ['t' => comms_now_ms(), 'i' => $id]);
}

/** Within the edit/delete window (minutes since sending)? */
function comms_in_edit_window(int $createdTs): bool
{
    $min = (int)comms_setting('comms_edit_window_min');
    return $min > 0 && (time() - $createdTs) <= $min * 60;
}

/** May $me change (edit/delete) this message? Own message inside the window, or a moderator any time. */
function comms_can_modify(int $me, array $msg): bool
{
    if ($msg['deleted_at'] !== null) {
        return false;
    }
    if ((int)$msg['sender_id'] === $me) {
        return comms_in_edit_window((int)$msg['ts']) && comms_actor_can($me, 'comms.send_message');
    }
    return comms_actor_can($me, 'comms.message_moderate');
}

/** A message this member may see right now (window, not hidden for them), or null. */
function comms_visible_message(int $me, int $msgId): ?array
{
    $row = Database::fetch(
        'SELECT g.*, UNIX_TIMESTAMP(g.created_at) AS ts, m.role AS my_role, c.type AS conv_type
           FROM comms_messages g
           JOIN comms_members m ON m.conversation_id = g.conversation_id AND m.user_id = :u AND m.left_at IS NULL
           JOIN comms_conversations c ON c.id = g.conversation_id
          WHERE g.id = :i AND g.id > m.joined_after_msg_id
            AND NOT EXISTS (SELECT 1 FROM comms_message_hidden h WHERE h.message_id = g.id AND h.user_id = :u2)',
        ['u' => $me, 'i' => $msgId, 'u2' => $me]
    );
    return $row;
}

/* ------------------------------------------------------------------ *
 | Listing                                                             |
 * ------------------------------------------------------------------ */

/**
 * Messages the member may see. $after: newer than id (polling; ascending). $before: older than id
 * (scroll-back). $changedSince: messages edited/deleted/reacted/pinned since that server time (ms).
 * The window and hidden-for-me are enforced in SQL, never in the browser.
 */
function comms_fetch(int $me, int $convId, int $after = 0, int $before = 0, int $limit = 30, bool $ascending = false, ?int $changedSince = null): array
{
    $m = comms_membership($convId, $me);
    if ($m === null) {
        return comms_res(false, 'You are not a member of this conversation.');
    }
    $limit = max(1, min(100, $limit));
    $sql = 'SELECT g.id, g.sender_id, u.name AS sender_name, g.body, g.reply_to_id, UNIX_TIMESTAMP(g.created_at) AS ts,
                   g.edited_at, g.deleted_at, g.forwarded, g.announcement,
                   rp.body AS reply_body, rp.deleted_at AS reply_deleted, ru.name AS reply_name
              FROM comms_messages g
              LEFT JOIN users u ON u.id = g.sender_id
              LEFT JOIN comms_messages rp ON rp.id = g.reply_to_id AND rp.conversation_id = g.conversation_id AND rp.id > :from_r
              LEFT JOIN users ru ON ru.id = rp.sender_id
             WHERE g.conversation_id = :c AND g.id > :from_m
               AND NOT EXISTS (SELECT 1 FROM comms_message_hidden h WHERE h.message_id = g.id AND h.user_id = :hu)';
    $p = ['from_r' => (int)$m['joined_after_msg_id'], 'c' => $convId, 'from_m' => (int)$m['joined_after_msg_id'], 'hu' => $me];
    if ($changedSince !== null) {
        $rows = Database::fetchAll($sql . ' AND g.changed_ms > :cs ORDER BY g.id ASC LIMIT ' . $limit, $p + ['cs' => $changedSince]);
    } elseif ($after > 0 || $ascending) {
        $rows = Database::fetchAll($sql . ' AND g.id > :after ORDER BY g.id ASC LIMIT ' . $limit, $p + ['after' => $after]);
    } else {
        if ($before > 0) {
            $sql .= ' AND g.id < :before';
            $p['before'] = $before;
        }
        $rows = array_reverse(Database::fetchAll($sql . ' ORDER BY g.id DESC LIMIT ' . $limit, $p));
    }
    $out = comms_shape_messages($me, $convId, $m, $rows);
    $older = false;
    if ($changedSince === null && $out) {
        $older = (bool)Database::exists('SELECT 1 FROM comms_messages WHERE conversation_id = :c AND id > :f AND id < :i', ['c' => $convId, 'f' => (int)$m['joined_after_msg_id'], 'i' => $out[0]['id']]);
    }
    return comms_res(true, '', ['messages' => $out, 'has_older' => $older, 'last_read' => (int)$m['last_read_msg_id'], 'now_ms' => comms_now_ms()]);
}

function comms_shape_messages(int $me, int $convId, array $m, array $rows): array
{
    if (!$rows) {
        return [];
    }
    $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    $in = implode(',', $ids);
    $atts = [];
    foreach (Database::fetchAll("SELECT id, message_id, kind, original_name, mime, size_bytes, duration_sec FROM comms_attachments WHERE message_id IN ($in)") as $a) {
        $atts[(int)$a['message_id']] = $a;
    }
    $react = [];
    foreach (Database::fetchAll("SELECT message_id, emoji, COUNT(*) AS n, MAX(user_id = $me) AS mine FROM comms_reactions WHERE message_id IN ($in) GROUP BY message_id, emoji ORDER BY MIN(created_at), emoji") as $x) {
        $react[(int)$x['message_id']][] = ['e' => $x['emoji'], 'n' => (int)$x['n'], 'mine' => (bool)$x['mine']];
    }
    $pins = array_flip(array_map('intval', array_column(Database::fetchAll("SELECT message_id FROM comms_pins WHERE conversation_id = $convId AND message_id IN ($in)"), 'message_id')));
    $isMgr = $m['type'] === 'group' ? comms_can_manage_group($me, $convId) : true;
    $isGroup = $m['type'] === 'group';
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $deleted = $r['deleted_at'] !== null;
        $a = !$deleted ? ($atts[$id] ?? null) : null;
        $reply = null;
        if ($r['reply_to_id'] !== null && $r['reply_name'] !== null) {
            $reply = ['name' => (string)$r['reply_name'], 'body' => $r['reply_deleted'] !== null ? 'Message deleted' : mb_substr((string)$r['reply_body'], 0, 120)];
        }
        $row = ['id' => $id, 'sender_id' => $r['sender_id'], 'deleted_at' => $r['deleted_at'], 'ts' => (int)$r['ts']];
        $mine = (int)$r['sender_id'] === $me;
        $canMod = comms_can_modify($me, $row);
        $out[] = [
            'id' => $id, 'mine' => $mine, 'sender' => (string)($r['sender_name'] ?? 'Former user'),
            'body' => $deleted ? '' : (string)$r['body'], 'deleted' => $deleted, 'edited' => $r['edited_at'] !== null && !$deleted,
            'forwarded' => (bool)$r['forwarded'], 'announcement' => (bool)$r['announcement'] && !$deleted,
            'ts' => (int)$r['ts'], 'time' => comms_fmt_ts((int)$r['ts']), 'day' => comms_fmt_ts((int)$r['ts'], 'd M Y'), 'reply' => $reply,
            'file' => $a === null ? null : [
                'id' => (int)$a['id'], 'name' => $a['original_name'], 'size' => (int)$a['size_bytes'], 'kind' => $a['kind'], 'mime' => $a['mime'],
                'dur' => $a['duration_sec'] !== null ? (int)$a['duration_sec'] : null, 'inline' => comms_inline_ok((string)$a['mime']),
                'image' => str_starts_with((string)$a['mime'], 'image/'), 'pdf' => $a['mime'] === 'application/pdf',
            ],
            'reactions' => $deleted ? [] : ($react[$id] ?? []), 'pinned' => isset($pins[$id]) && !$deleted,
            'can_edit' => $canMod,
            'can_delete' => $canMod, 'can_pin' => !$deleted && $isMgr,
            'can_announce' => !$deleted && $isGroup && $isMgr, 'can_readers' => !$deleted && ($mine || ($isGroup && $isMgr)) && $isGroup,
        ];
    }
    return $out;
}

/** Pinned messages (newest first, up to 5) for the pane header. */
function comms_pins_list(int $me, int $convId): array
{
    $m = comms_membership($convId, $me);
    if ($m === null) {
        return [];
    }
    $rows = Database::fetchAll(
        'SELECT g.id, SUBSTRING(g.body, 1, 90) AS body, (SELECT original_name FROM comms_attachments a WHERE a.message_id = g.id LIMIT 1) AS fname
           FROM comms_pins p JOIN comms_messages g ON g.id = p.message_id
          WHERE p.conversation_id = :c AND g.id > :f AND g.deleted_at IS NULL
          ORDER BY p.pinned_at DESC LIMIT 5',
        ['c' => $convId, 'f' => (int)$m['joined_after_msg_id']]
    );
    return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'text' => $r['body'] !== '' ? (string)$r['body'] : ('Attachment: ' . ($r['fname'] ?? ''))], $rows);
}

/* ------------------------------------------------------------------ *
 | Edit / delete / hide                                                |
 * ------------------------------------------------------------------ */

function comms_edit(int $me, int $msgId, string $body): array
{
    $msg = comms_visible_message($me, $msgId);
    if ($msg === null) {
        return comms_res(false, 'Message not found.');
    }
    if (!comms_can_modify($me, $msg)) {
        return comms_res(false, (int)$msg['sender_id'] === $me ? 'The time limit for editing this message has passed.' : 'You cannot edit this message.');
    }
    $body = comms_clean_body($body);
    $hasFile = Database::exists('SELECT 1 FROM comms_attachments WHERE message_id = :m', ['m' => $msgId]);
    if ($body === '' && !$hasFile) {
        return comms_res(false, 'A message cannot be empty. Delete it instead.');
    }
    if (mb_strlen($body) > COMMS_MAX_BODY) {
        return comms_res(false, 'That message is too long.');
    }
    if ($body === (string)$msg['body']) {
        return comms_res(true);
    }
    Database::beginTransaction();
    try {
        Database::insert('comms_message_edits', ['message_id' => $msgId, 'old_body' => (string)$msg['body'], 'edited_by' => $me]);
        Database::execute('UPDATE comms_messages SET body = :b, edited_at = NOW(), changed_ms = :t WHERE id = :i', ['b' => $body, 't' => comms_now_ms(), 'i' => $msgId]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        return comms_res(false, 'Could not save the edit.');
    }
    $moderated = (int)$msg['sender_id'] !== $me;
    comms_audit_safe($moderated ? 'chat.message_moderated_edit' : 'chat.message_edited', 'Message edited', ['actor_id' => $me, 'subject_type' => 'message', 'subject_id' => $msgId, 'new' => ['conversation' => (int)$msg['conversation_id'], 'moderated' => $moderated]]);
    return comms_res(true);
}

/** Delete for everyone: the text stays in the database (history) but is never shown to anyone again. */
function comms_delete(int $me, int $msgId): array
{
    $msg = comms_visible_message($me, $msgId);
    if ($msg === null) {
        return comms_res(false, 'Message not found.');
    }
    if (!comms_can_modify($me, $msg)) {
        return comms_res(false, (int)$msg['sender_id'] === $me ? 'The time limit for deleting this message has passed. You can still delete it for yourself.' : 'You cannot delete this message.');
    }
    Database::execute('UPDATE comms_messages SET deleted_at = NOW(), deleted_by = :u, changed_ms = :t WHERE id = :i', ['u' => $me, 't' => comms_now_ms(), 'i' => $msgId]);
    Database::execute('DELETE FROM comms_pins WHERE message_id = :i', ['i' => $msgId]);
    $moderated = (int)$msg['sender_id'] !== $me;
    comms_audit_safe($moderated ? 'chat.message_moderated_delete' : 'chat.message_deleted', 'Message deleted', ['actor_id' => $me, 'subject_type' => 'message', 'subject_id' => $msgId, 'new' => ['conversation' => (int)$msg['conversation_id'], 'moderated' => $moderated]]);
    return comms_res(true);
}

/** Delete for me: hides it only from this user; others are unaffected. */
function comms_hide(int $me, int $msgId): array
{
    if (comms_visible_message($me, $msgId) === null) {
        return comms_res(false, 'Message not found.');
    }
    Database::execute('INSERT IGNORE INTO comms_message_hidden (message_id, user_id) VALUES (:m, :u)', ['m' => $msgId, 'u' => $me]);
    return comms_res(true);
}

/* ------------------------------------------------------------------ *
 | Reactions, pins, announcements, readers                             |
 * ------------------------------------------------------------------ */

function comms_react(int $me, int $msgId, string $emoji): array
{
    if (!comms_actor_can($me, 'comms.send_message')) {
        return comms_res(false, 'You do not have permission to react.');
    }
    $msg = comms_visible_message($me, $msgId);
    if ($msg === null || $msg['deleted_at'] !== null) {
        return comms_res(false, 'Message not found.');
    }
    if ($emoji !== '' && !in_array($emoji, COMMS_REACTIONS, true)) {
        return comms_res(false, 'That reaction is not available.');
    }
    $cur = Database::fetchValue('SELECT emoji FROM comms_reactions WHERE message_id = :m AND user_id = :u', ['m' => $msgId, 'u' => $me]);
    if ($emoji === '' || $cur === $emoji) {
        Database::execute('DELETE FROM comms_reactions WHERE message_id = :m AND user_id = :u', ['m' => $msgId, 'u' => $me]);
    } else {
        Database::execute('INSERT INTO comms_reactions (message_id, user_id, emoji) VALUES (:m, :u, :e) ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), created_at = NOW()', ['m' => $msgId, 'u' => $me, 'e' => $emoji]);
    }
    comms_touch_message($msgId);
    return comms_res(true);
}

function comms_pin(int $me, int $msgId, bool $pin): array
{
    $msg = comms_visible_message($me, $msgId);
    if ($msg === null || $msg['deleted_at'] !== null) {
        return comms_res(false, 'Message not found.');
    }
    $conv = (int)$msg['conversation_id'];
    if ($msg['conv_type'] === 'group' && !comms_can_manage_group($me, $conv)) {
        return comms_res(false, 'Only group managers can pin messages.');
    }
    if ($pin) {
        Database::execute('INSERT IGNORE INTO comms_pins (conversation_id, message_id, pinned_by) VALUES (:c, :m, :u)', ['c' => $conv, 'm' => $msgId, 'u' => $me]);
    } else {
        Database::execute('DELETE FROM comms_pins WHERE conversation_id = :c AND message_id = :m', ['c' => $conv, 'm' => $msgId]);
    }
    comms_touch_message($msgId);
    comms_audit_safe($pin ? 'chat.pinned' : 'chat.unpinned', $pin ? 'Message pinned' : 'Message unpinned', ['actor_id' => $me, 'subject_type' => 'message', 'subject_id' => $msgId]);
    return comms_res(true);
}

function comms_announce(int $me, int $msgId, bool $on): array
{
    $msg = comms_visible_message($me, $msgId);
    if ($msg === null || $msg['deleted_at'] !== null) {
        return comms_res(false, 'Message not found.');
    }
    $conv = (int)$msg['conversation_id'];
    if ($msg['conv_type'] !== 'group' || !comms_can_manage_group($me, $conv)) {
        return comms_res(false, 'Only group managers can post announcements.');
    }
    Database::execute('UPDATE comms_messages SET announcement = :a, changed_ms = :t WHERE id = :i', ['a' => $on ? 1 : 0, 't' => comms_now_ms(), 'i' => $msgId]);
    comms_audit_safe($on ? 'group.announcement_set' : 'group.announcement_cleared', 'Group announcement changed', ['actor_id' => $me, 'subject_type' => 'message', 'subject_id' => $msgId]);
    return comms_res(true);
}

/** Who has read a group message. Allowed for the sender and group managers. */
function comms_readers(int $me, int $msgId): array
{
    $msg = comms_visible_message($me, $msgId);
    if ($msg === null || $msg['deleted_at'] !== null || $msg['conv_type'] !== 'group') {
        return comms_res(false, 'Not available for this message.');
    }
    $conv = (int)$msg['conversation_id'];
    if ((int)$msg['sender_id'] !== $me && !comms_can_manage_group($me, $conv)) {
        return comms_res(false, 'Only the sender or a group manager can see who has read this message.');
    }
    $rows = Database::fetchAll(
        'SELECT u.name, (m.last_read_msg_id >= :i) AS has_read FROM comms_members m JOIN users u ON u.id = m.user_id
          WHERE m.conversation_id = :c AND m.left_at IS NULL AND m.joined_after_msg_id < :i2 AND m.user_id <> :s ORDER BY u.name',
        ['i' => $msgId, 'c' => $conv, 'i2' => $msgId, 's' => (int)$msg['sender_id']]
    );
    return comms_res(true, '', [
        'read' => array_values(array_column(array_filter($rows, static fn($r) => (bool)$r['has_read']), 'name')),
        'unread' => array_values(array_column(array_filter($rows, static fn($r) => !(bool)$r['has_read']), 'name')),
    ]);
}

/* ------------------------------------------------------------------ *
 | Forwarding                                                          |
 * ------------------------------------------------------------------ */

/** Forward a message the actor can see into up to 5 conversations they may post in. */
function comms_forward(int $me, int $msgId, array $targetConvIds): array
{
    $msg = comms_visible_message($me, $msgId);
    if ($msg === null || $msg['deleted_at'] !== null) {
        return comms_res(false, 'Message not found.');
    }
    $targets = array_values(array_unique(array_filter(array_map('intval', $targetConvIds))));
    if (!$targets || count($targets) > 5) {
        return comms_res(false, 'Choose between 1 and 5 conversations.');
    }
    $att = Database::fetch('SELECT * FROM comms_attachments WHERE message_id = :m', ['m' => $msgId]);
    $need = $att === null ? 'comms.send_message' : ($att['kind'] === 'voice' ? 'comms.send_voice' : 'comms.send_file');
    if (!comms_actor_can($me, 'comms.send_message') || !comms_actor_can($me, $need)) {
        return comms_res(false, 'You do not have permission to forward this kind of message.');
    }
    $sent = [];
    $errors = [];
    foreach ($targets as $conv) {
        $m = comms_membership($conv, $me);
        if ($m === null) {
            $errors[] = 'You are not a member of one of the chosen conversations.';
            continue;
        }
        if ($m['type'] === 'private') {
            $other = (int)Database::fetchValue('SELECT user_id FROM comms_members WHERE conversation_id = :c AND user_id <> :u', ['c' => $conv, 'u' => $me]);
            if (!comms_actor_can($other, 'comms.send_message')) {
                $errors[] = 'One recipient is no longer available for chat.';
                continue;
            }
        }
        $uuid = sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0x8000, 0xbfff), random_int(0, 0xffffffffffff));
        $r = comms_send($me, $conv, (string)$msg['body'], $uuid, 0, $att !== null);
        if (!$r['ok']) {
            $errors[] = $r['message'];
            continue;
        }
        Database::execute('UPDATE comms_messages SET forwarded = 1 WHERE id = :i', ['i' => $r['id']]);
        if ($att !== null) {
            Database::insert('comms_attachments', [
                'message_id' => $r['id'], 'conversation_id' => $conv, 'uploader_id' => $me, 'kind' => $att['kind'], 'original_name' => $att['original_name'],
                'stored_name' => $att['stored_name'], 'mime' => $att['mime'], 'size_bytes' => $att['size_bytes'], 'sha256' => $att['sha256'], 'duration_sec' => $att['duration_sec'],
            ]);
        }
        $sent[] = $conv;
    }
    if ($sent) {
        comms_audit_safe('chat.forwarded', 'Message forwarded', ['actor_id' => $me, 'subject_type' => 'message', 'subject_id' => $msgId, 'new' => ['to' => $sent]]);
    }
    return comms_res((bool)$sent, $sent ? '' : implode(' ', array_unique($errors)), ['sent' => $sent, 'errors' => array_values(array_unique($errors))]);
}

/* ------------------------------------------------------------------ *
 | Search                                                              |
 * ------------------------------------------------------------------ */

/** Search only inside conversations the actor belongs to and only within their history window. */
function comms_search(int $me, string $term, array $f = []): array
{
    $term = trim($term);
    if (mb_strlen($term) < 2) {
        return comms_res(false, 'Type at least 2 characters to search.');
    }
    $like = '%' . addcslashes($term, '%_\\') . '%';
    $where = 'm.user_id = :me AND m.left_at IS NULL AND g.id > m.joined_after_msg_id AND g.deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM comms_message_hidden h WHERE h.message_id = g.id AND h.user_id = :me2)
              AND (g.body LIKE :q1 OR EXISTS (SELECT 1 FROM comms_attachments a WHERE a.message_id = g.id AND a.original_name LIKE :q2))';
    $p = ['me' => $me, 'me2' => $me, 'q1' => $like, 'q2' => $like];
    if (!empty($f['conversation_id'])) {
        $where .= ' AND g.conversation_id = :cv';
        $p['cv'] = (int)$f['conversation_id'];
    }
    if (!empty($f['sender_id'])) {
        $where .= ' AND g.sender_id = :sd';
        $p['sd'] = (int)$f['sender_id'];
    }
    foreach (['from' => ['>=', '00:00:00'], 'to' => ['<=', '23:59:59']] as $k => [$op, $time]) {
        if (!empty($f[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f[$k])) {
            $ts = (new DateTimeImmutable($f[$k] . ' ' . $time, comms_tz()))->getTimestamp();
            $where .= " AND UNIX_TIMESTAMP(g.created_at) $op :t$k";
            $p["t$k"] = $ts;
        }
    }
    $rows = Database::fetchAll(
        "SELECT g.id, g.conversation_id, g.body, UNIX_TIMESTAMP(g.created_at) AS ts, su.name AS sender, c.type, c.name AS gname,
                (SELECT u2.name FROM comms_members m2 JOIN users u2 ON u2.id = m2.user_id WHERE m2.conversation_id = c.id AND m2.user_id <> :me3 LIMIT 1) AS other,
                (SELECT a.original_name FROM comms_attachments a WHERE a.message_id = g.id AND a.original_name LIKE :q3 LIMIT 1) AS fname
           FROM comms_messages g
           JOIN comms_members m ON m.conversation_id = g.conversation_id
           JOIN comms_conversations c ON c.id = g.conversation_id
           LEFT JOIN users su ON su.id = g.sender_id
          WHERE $where ORDER BY g.id DESC LIMIT 40",
        $p + ['me3' => $me, 'q3' => $like]
    );
    $out = [];
    foreach ($rows as $r) {
        $pos = mb_stripos((string)$r['body'], $term);
        $snippet = $pos === false ? '' : mb_substr((string)$r['body'], max(0, $pos - 30), 120);
        $out[] = [
            'id' => (int)$r['id'], 'conversation_id' => (int)$r['conversation_id'], 'conversation' => $r['type'] === 'private' ? (string)($r['other'] ?? '') : (string)$r['gname'],
            'sender' => (string)($r['sender'] ?? ''), 'snippet' => $snippet !== '' ? $snippet : ('File: ' . (string)$r['fname']), 'when' => comms_fmt_ts((int)$r['ts'], 'd M Y, h:i A'),
        ];
    }
    return comms_res(true, '', ['results' => $out]);
}

/* ------------------------------------------------------------------ *
 | Group name and photo                                                |
 * ------------------------------------------------------------------ */

function comms_group_rename(int $me, int $convId, string $name): array
{
    if (!comms_can_manage_group($me, $convId)) {
        return comms_res(false, 'You cannot manage this group.');
    }
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || mb_strlen($name) > 80) {
        return comms_res(false, 'Enter a group name of up to 80 characters.');
    }
    $old = (string)Database::fetchValue('SELECT name FROM comms_conversations WHERE id = :c', ['c' => $convId]);
    Database::execute('UPDATE comms_conversations SET name = :n WHERE id = :c', ['n' => $name, 'c' => $convId]);
    comms_audit_safe('group.renamed', 'Group renamed', ['actor_id' => $me, 'subject_type' => 'conversation', 'subject_id' => $convId, 'old' => ['name' => $old], 'new' => ['name' => $name]]);
    return comms_res(true);
}

function comms_group_photo(int $me, int $convId, string $tmp, string $origName, bool $isUploaded = true): array
{
    if (!comms_can_manage_group($me, $convId)) {
        return comms_res(false, 'You cannot manage this group.');
    }
    if ($isUploaded && !is_uploaded_file($tmp)) {
        return comms_res(false, 'The upload was not valid.');
    }
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $size = (int)@filesize($tmp);
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || $size <= 0 || $size > 2097152 || !comms_content_matches($tmp, $ext, 'image')) {
        return comms_res(false, 'Choose a JPG, PNG or WebP picture up to 2 MB.');
    }
    $dir = comms_storage_root() . '/photos';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $stored = bin2hex(random_bytes(16));
    if (!($isUploaded ? @move_uploaded_file($tmp, "$dir/$stored") : @copy($tmp, "$dir/$stored"))) {
        return comms_res(false, 'The picture could not be stored.');
    }
    $old = Database::fetchValue('SELECT photo FROM comms_conversations WHERE id = :c', ['c' => $convId]);
    Database::execute('UPDATE comms_conversations SET photo = :p WHERE id = :c', ['p' => $stored, 'c' => $convId]);
    if (is_string($old) && preg_match('/^[0-9a-f]{32}$/', $old)) {
        @unlink("$dir/$old");
    }
    comms_audit_safe('group.photo_changed', 'Group photo changed', ['actor_id' => $me, 'subject_type' => 'conversation', 'subject_id' => $convId]);
    return comms_res(true);
}
