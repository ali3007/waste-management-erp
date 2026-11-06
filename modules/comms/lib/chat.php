<?php
/**
 * Chat engine (Phase 2a): private chats, groups, messages, receipts, unread counts, presence.
 *
 * Every function takes the ACTING user id explicitly and re-checks permission and membership itself;
 * nothing trusts the browser. A member only ever sees messages inside their membership window
 * (id > joined_after_msg_id) and only while the membership is active (left_at IS NULL), so people
 * added later cannot read earlier history and removed people lose access immediately.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const COMMS_MAX_BODY = 4000;
const COMMS_ONLINE_SECONDS = 40;
const COMMS_TYPING_SECONDS = 6;

function comms_res(bool $ok, string $message = '', array $extra = []): array
{
    return ['ok' => $ok, 'message' => $message] + $extra;
}

/** Does this user hold the permission right now (live, from the DB, active account only)? */
function comms_actor_can(int $userId, string $permission): bool
{
    return comms_user_can($userId, $permission);
}

/** The ACTIVE membership row of a user in a conversation, or null. */
function comms_membership(int $convId, int $userId): ?array
{
    return Database::fetch(
        'SELECT m.*, c.type, c.name, c.last_message_id
           FROM comms_members m JOIN comms_conversations c ON c.id = m.conversation_id
          WHERE m.conversation_id = :c AND m.user_id = :u AND m.left_at IS NULL',
        ['c' => $convId, 'u' => $userId]
    );
}

function comms_is_uuid(string $s): bool
{
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}

function comms_clean_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $body) ?? '';
    return trim($body);
}

/** Display time in the central timezone. */
function comms_fmt_ts(int $ts, string $fmt = 'h:i A'): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(comms_tz())->format($fmt);
}

/* ------------------------------------------------------------------ *
 | Directory + conversations                                           |
 * ------------------------------------------------------------------ */

/** People the actor may message privately (active, hold comms.access + send_message). */
function comms_directory(int $me, string $search = ''): array
{
    if (!comms_actor_can($me, 'comms.chat_private')) {
        return [];
    }
    $rows = comms_eligible_users('comms.send_message', $search, 30);
    return array_values(array_filter($rows, static fn(array $r): bool => (int)$r['id'] !== $me));
}

function comms_private_open(int $me, int $other): array
{
    if (!comms_actor_can($me, 'comms.chat_private')) {
        return comms_res(false, 'You do not have permission to start private chats.');
    }
    if ($other === $me) {
        return comms_res(false, 'You cannot start a chat with yourself.');
    }
    if (!comms_actor_can($other, 'comms.send_message')) {
        return comms_res(false, 'That person is not available for chat.');
    }
    $key = min($me, $other) . ':' . max($me, $other);
    Database::beginTransaction();
    try {
        $id = Database::fetchValue('SELECT id FROM comms_conversations WHERE private_key = :k', ['k' => $key]);
        $created = false;
        if ($id === null) {
            $id = Database::insert('comms_conversations', ['type' => 'private', 'private_key' => $key, 'created_by' => $me]);
            foreach ([$me, $other] as $u) {
                Database::insert('comms_members', ['conversation_id' => $id, 'user_id' => $u, 'added_by' => $me]);
            }
            $created = true;
        }
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        // A simultaneous request may have created it first (unique key) — look it up again.
        $id = Database::fetchValue('SELECT id FROM comms_conversations WHERE private_key = :k', ['k' => $key]);
        if ($id === null) {
            log_event('comms', 'private_open failed', ['error' => $e->getMessage()]);
            return comms_res(false, 'Could not open the chat. Please try again.');
        }
        $created = false;
    }
    if ($created) {
        comms_audit_safe('chat.private_created', 'Private chat created', ['actor_id' => $me, 'subject_type' => 'conversation', 'subject_id' => (int)$id]);
    }
    return comms_res(true, '', ['conversation_id' => (int)$id]);
}

function comms_group_create(int $me, string $name, array $memberIds): array
{
    if (!comms_actor_can($me, 'comms.group_create')) {
        return comms_res(false, 'You do not have permission to create groups.');
    }
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || mb_strlen($name) > 80) {
        return comms_res(false, 'Enter a group name of up to 80 characters.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn(int $i): bool => $i > 0 && $i !== $me)));
    if (count($ids) > 200) {
        return comms_res(false, 'A group can start with at most 200 members.');
    }
    foreach ($ids as $u) {
        if (!comms_actor_can($u, 'comms.send_message')) {
            return comms_res(false, 'One of the selected people is not available for chat.');
        }
    }
    Database::beginTransaction();
    try {
        $id = Database::insert('comms_conversations', ['type' => 'group', 'name' => $name, 'created_by' => $me]);
        Database::insert('comms_members', ['conversation_id' => $id, 'user_id' => $me, 'role' => 'manager', 'added_by' => $me]);
        foreach ($ids as $u) {
            Database::insert('comms_members', ['conversation_id' => $id, 'user_id' => $u, 'added_by' => $me]);
        }
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        log_event('comms', 'group_create failed', ['error' => $e->getMessage()]);
        return comms_res(false, 'Could not create the group. Please try again.');
    }
    comms_audit_safe('group.created', 'Group created', ['actor_id' => $me, 'subject_type' => 'conversation', 'subject_id' => (int)$id, 'new' => ['name' => $name, 'members' => $ids]]);
    return comms_res(true, '', ['conversation_id' => (int)$id]);
}

/** May $me manage this group? Needs comms.group_manage AND to be a manager member of it. */
function comms_can_manage_group(int $me, int $convId): bool
{
    $m = comms_membership($convId, $me);
    return $m !== null && $m['type'] === 'group' && $m['role'] === 'manager' && comms_actor_can($me, 'comms.group_manage');
}

function comms_group_add(int $me, int $convId, int $userId): array
{
    if (!comms_can_manage_group($me, $convId)) {
        return comms_res(false, 'You cannot manage this group.');
    }
    if (!comms_actor_can($userId, 'comms.send_message')) {
        return comms_res(false, 'That person is not available for chat.');
    }
    Database::beginTransaction();
    try {
        // Lock the conversation row so "current last message" cannot move while we record the boundary.
        $last = (int)Database::fetchValue('SELECT last_message_id FROM comms_conversations WHERE id = :c FOR UPDATE', ['c' => $convId]);
        if (comms_membership($convId, $userId) !== null) {
            Database::rollBack();
            return comms_res(false, 'That person is already a member.');
        }
        Database::insert('comms_members', [
            'conversation_id' => $convId, 'user_id' => $userId, 'added_by' => $me,
            'joined_after_msg_id' => $last, 'last_read_msg_id' => $last, 'last_delivered_msg_id' => $last,
        ]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        log_event('comms', 'group_add failed', ['error' => $e->getMessage()]);
        return comms_res(false, 'Could not add the member.');
    }
    comms_audit_safe('group.member_added', 'Group member added', ['actor_id' => $me, 'subject_type' => 'conversation', 'subject_id' => $convId, 'new' => ['user_id' => $userId, 'from_message' => $last]]);
    return comms_res(true);
}

function comms_group_remove(int $me, int $convId, int $userId): array
{
    if (!comms_can_manage_group($me, $convId)) {
        return comms_res(false, 'You cannot manage this group.');
    }
    if ($userId === $me) {
        return comms_res(false, 'Managers cannot remove themselves.');
    }
    Database::beginTransaction();
    try {
        $last = (int)Database::fetchValue('SELECT last_message_id FROM comms_conversations WHERE id = :c FOR UPDATE', ['c' => $convId]);
        $n = Database::execute(
            'UPDATE comms_members SET left_at = NOW(), left_after_msg_id = :l WHERE conversation_id = :c AND user_id = :u AND left_at IS NULL',
            ['l' => $last, 'c' => $convId, 'u' => $userId]
        );
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        return comms_res(false, 'Could not remove the member.');
    }
    if ($n === 0) {
        return comms_res(false, 'That person is not a member.');
    }
    comms_audit_safe('group.member_removed', 'Group member removed', ['actor_id' => $me, 'subject_type' => 'conversation', 'subject_id' => $convId, 'new' => ['user_id' => $userId, 'last_visible_message' => $last]]);
    return comms_res(true);
}

/* ------------------------------------------------------------------ *
 | Messages                                                            |
 * ------------------------------------------------------------------ */

function comms_send(int $me, int $convId, string $body, string $uuid, int $replyTo = 0, bool $allowEmpty = false): array
{
    if (!comms_actor_can($me, 'comms.send_message')) {
        return comms_res(false, 'You do not have permission to send messages.');
    }
    if (!comms_is_uuid($uuid)) {
        return comms_res(false, 'Invalid message identifier.');
    }
    $body = comms_clean_body($body);
    if ($body === '' && !$allowEmpty) {
        return comms_res(false, 'Type a message first.');
    }
    if (mb_strlen($body) > COMMS_MAX_BODY) {
        return comms_res(false, 'That message is too long (limit ' . COMMS_MAX_BODY . ' characters).');
    }
    $m = comms_membership($convId, $me);
    if ($m === null) {
        return comms_res(false, 'You are not a member of this conversation.');
    }
    // Idempotency: a retry of the same message returns the stored one instead of creating a duplicate.
    $dup = Database::fetch('SELECT id, conversation_id FROM comms_messages WHERE sender_id = :s AND client_uuid = :u', ['s' => $me, 'u' => $uuid]);
    if ($dup !== null) {
        return comms_res(true, '', ['id' => (int)$dup['id'], 'duplicate' => true, 'conversation_id' => (int)$dup['conversation_id']]);
    }
    if ($m['type'] === 'private') {
        $other = (int)Database::fetchValue('SELECT user_id FROM comms_members WHERE conversation_id = :c AND user_id <> :u', ['c' => $convId, 'u' => $me]);
        if (!comms_actor_can($other, 'comms.send_message')) {
            return comms_res(false, 'This person is no longer available for chat.');
        }
    }
    $reply = null;
    if ($replyTo > 0) {
        // Only a message this member can see in this conversation may be replied to.
        $ok = Database::exists(
            'SELECT 1 FROM comms_messages WHERE id = :r AND conversation_id = :c AND id > :from',
            ['r' => $replyTo, 'c' => $convId, 'from' => (int)$m['joined_after_msg_id']]
        );
        $reply = $ok ? $replyTo : null;
    }
    try {
        Database::beginTransaction();
        $id = Database::insert('comms_messages', ['conversation_id' => $convId, 'sender_id' => $me, 'client_uuid' => strtolower($uuid), 'body' => $body, 'reply_to_id' => $reply, 'changed_ms' => comms_now_ms()]);
        Database::execute('UPDATE comms_conversations SET last_message_id = :i, last_message_at = NOW() WHERE id = :c', ['i' => $id, 'c' => $convId]);
        Database::execute('UPDATE comms_members SET last_read_msg_id = GREATEST(last_read_msg_id, :i), last_delivered_msg_id = GREATEST(last_delivered_msg_id, :i2) WHERE id = :m', ['i' => $id, 'i2' => $id, 'm' => (int)$m['id']]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        // Lost a race with an identical retry: return the winner.
        $dup = Database::fetch('SELECT id, conversation_id FROM comms_messages WHERE sender_id = :s AND client_uuid = :u', ['s' => $me, 'u' => $uuid]);
        if ($dup !== null) {
            return comms_res(true, '', ['id' => (int)$dup['id'], 'duplicate' => true, 'conversation_id' => (int)$dup['conversation_id']]);
        }
        log_event('comms', 'send failed', ['error' => $e->getMessage()]);
        return comms_res(false, 'The message could not be saved. Please retry.');
    }
    return comms_res(true, '', ['id' => (int)$id, 'duplicate' => false, 'conversation_id' => $convId]);
}

function comms_mark_read(int $me, int $convId, int $upTo): bool
{
    $m = comms_membership($convId, $me);
    if ($m === null) {
        return false;
    }
    // Clamp to this conversation's real last message: a forged id cannot mark the future as read.
    $upTo = min($upTo, (int)$m['last_message_id']);
    if ($upTo <= 0) {
        return false;
    }
    Database::execute(
        'UPDATE comms_members SET last_read_msg_id = GREATEST(last_read_msg_id, :r), last_delivered_msg_id = GREATEST(last_delivered_msg_id, :r2) WHERE id = :m',
        ['r' => $upTo, 'r2' => $upTo, 'm' => (int)$m['id']]
    );
    return true;
}

/** Everything delivered to this user's open client counts as delivered (called on each poll). */
function comms_mark_delivered(int $me): void
{
    Database::execute(
        'UPDATE comms_members m JOIN comms_conversations c ON c.id = m.conversation_id
            SET m.last_delivered_msg_id = c.last_message_id
          WHERE m.user_id = :u AND m.left_at IS NULL AND m.last_delivered_msg_id < c.last_message_id AND (m.left_after_msg_id IS NULL)',
        ['u' => $me]
    );
}

/** Conversation list with unread counts, one query. */
function comms_conversations(int $me): array
{
    $rows = Database::fetchAll(
        "SELECT c.id, c.type, c.name, c.photo, c.last_message_id, UNIX_TIMESTAMP(c.last_message_at) AS last_ts, m.last_read_msg_id,
                (SELECT COUNT(*) FROM comms_messages x WHERE x.conversation_id = c.id AND x.id > GREATEST(m.last_read_msg_id, m.joined_after_msg_id)
                    AND (x.sender_id IS NULL OR x.sender_id <> :me)) AS unread,
                (SELECT COUNT(*) FROM comms_members mm WHERE mm.conversation_id = c.id AND mm.left_at IS NULL) AS member_count,
                (SELECT u.id FROM comms_members mm JOIN users u ON u.id = mm.user_id WHERE c.type = 'private' AND mm.conversation_id = c.id AND mm.user_id <> :me2 LIMIT 1) AS other_id,
                (SELECT u.name FROM comms_members mm JOIN users u ON u.id = mm.user_id WHERE c.type = 'private' AND mm.conversation_id = c.id AND mm.user_id <> :me3 LIMIT 1) AS other_name,
                (SELECT IF(g.deleted_at IS NOT NULL, 'Message deleted', IF(g.body = '', 'Attachment', SUBSTRING(g.body, 1, 80))) FROM comms_messages g WHERE g.conversation_id = c.id AND g.id > m.joined_after_msg_id ORDER BY g.id DESC LIMIT 1) AS preview
           FROM comms_members m JOIN comms_conversations c ON c.id = m.conversation_id
          WHERE m.user_id = :me4 AND m.left_at IS NULL
          ORDER BY COALESCE(c.last_message_at, c.created_at) DESC, c.id DESC LIMIT 200",
        ['me' => $me, 'me2' => $me, 'me3' => $me, 'me4' => $me]
    );
    $now = time();
    $presence = [];
    foreach ($rows as $r) {
        if ($r['other_id'] !== null) {
            $presence[(int)$r['other_id']] = true;
        }
    }
    $seen = $presence ? Database::fetchAll('SELECT user_id, UNIX_TIMESTAMP(last_seen_at) AS ts FROM comms_presence WHERE user_id IN (' . implode(',', array_map('intval', array_keys($presence))) . ')') : [];
    $seenMap = [];
    foreach ($seen as $s) {
        $seenMap[(int)$s['user_id']] = (int)$s['ts'];
    }
    $out = [];
    foreach ($rows as $r) {
        $private = $r['type'] === 'private';
        $ts = $private ? ($seenMap[(int)$r['other_id']] ?? 0) : 0;
        $out[] = [
            'id' => (int)$r['id'], 'type' => $r['type'], 'photo' => $r['photo'] !== null, 'title' => $private ? (string)($r['other_name'] ?? 'Former user') : (string)$r['name'],
            'unread' => (int)$r['unread'], 'members' => (int)$r['member_count'], 'preview' => (string)($r['preview'] ?? ''),
            'time' => $r['last_ts'] ? comms_fmt_ts((int)$r['last_ts']) : '',
            'online' => $private && $ts > 0 && ($now - $ts) <= COMMS_ONLINE_SECONDS,
            'last_seen' => $private && $ts > 0 ? comms_fmt_ts($ts, 'd M, h:i A') : '',
        ];
    }
    return $out;
}

/** Receipt state of the sender's own messages (private: sent/delivered/read; group: read count). */
function comms_receipts(int $me, int $convId, array $messageIds): array
{
    $m = comms_membership($convId, $me);
    if ($m === null || !$messageIds) {
        return [];
    }
    $others = Database::fetchAll(
        'SELECT user_id, last_read_msg_id, last_delivered_msg_id, joined_after_msg_id FROM comms_members WHERE conversation_id = :c AND left_at IS NULL AND user_id <> :u',
        ['c' => $convId, 'u' => $me]
    );
    $out = [];
    foreach ($messageIds as $id) {
        $id = (int)$id;
        $read = $deliv = 0;
        foreach ($others as $o) {
            if ($id <= (int)$o['joined_after_msg_id']) {
                continue;
            }
            $read += $id <= (int)$o['last_read_msg_id'] ? 1 : 0;
            $deliv += $id <= (int)$o['last_delivered_msg_id'] ? 1 : 0;
        }
        $total = count($others);
        $state = ($total > 0 && $read === $total) ? 'read' : (($total > 0 && $deliv === $total) ? 'delivered' : 'sent');
        $out[$id] = ['state' => $state, 'read' => $read, 'total' => $total];
    }
    return $out;
}

function comms_touch_presence(int $me): void
{
    Database::execute('INSERT INTO comms_presence (user_id, last_seen_at) VALUES (:u, NOW()) ON DUPLICATE KEY UPDATE last_seen_at = NOW()', ['u' => $me]);
}

function comms_set_typing(int $me, int $convId): void
{
    if (comms_membership($convId, $me) !== null) {
        Database::execute('INSERT INTO comms_presence (user_id, last_seen_at, typing_conversation_id, typing_at) VALUES (:u, NOW(), :c, NOW())
                           ON DUPLICATE KEY UPDATE last_seen_at = NOW(), typing_conversation_id = :c2, typing_at = NOW()', ['u' => $me, 'c' => $convId, 'c2' => $convId]);
    }
}

function comms_typing_names(int $me, int $convId): array
{
    if (comms_membership($convId, $me) === null) {
        return [];
    }
    $rows = Database::fetchAll(
        'SELECT u.name FROM comms_presence p JOIN users u ON u.id = p.user_id
           JOIN comms_members m ON m.user_id = p.user_id AND m.conversation_id = :c AND m.left_at IS NULL
          WHERE p.typing_conversation_id = :c2 AND p.user_id <> :me AND p.typing_at > (NOW() - INTERVAL ' . COMMS_TYPING_SECONDS . ' SECOND)',
        ['c' => $convId, 'c2' => $convId, 'me' => $me]
    );
    return array_column($rows, 'name');
}

function comms_unread_total(int $me): int
{
    return array_sum(array_column(comms_conversations($me), 'unread'));
}

/** Members of a conversation the actor belongs to (current members only). */
function comms_members_list(int $me, int $convId): array
{
    $m = comms_membership($convId, $me);
    if ($m === null) {
        return comms_res(false, 'You are not a member of this conversation.');
    }
    $rows = Database::fetchAll(
        'SELECT u.id, u.name, mm.role FROM comms_members mm JOIN users u ON u.id = mm.user_id
          WHERE mm.conversation_id = :c AND mm.left_at IS NULL ORDER BY mm.role = \'manager\' DESC, u.name ASC',
        ['c' => $convId]
    );
    return comms_res(true, '', [
        'members' => array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'name' => $r['name'], 'manager' => $r['role'] === 'manager'], $rows),
        'can_manage' => comms_can_manage_group($me, $convId),
        'type' => $m['type'],
    ]);
}
