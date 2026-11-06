<?php
/**
 * Chat AJAX endpoint (one dispatcher). POST only, CSRF-protected, session closed before heavy work so
 * polling never blocks the user's other tabs. Every action re-checks permission and membership inside
 * lib/chat.php.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    json_response(['ok' => false, 'message' => 'The system is not installed.'], 503);
}
if (!is_post()) {
    json_response(['ok' => false, 'message' => 'Invalid request.'], 405);
}
if (!Auth::check()) {
    json_response(['ok' => false, 'message' => 'Your session has expired. Please sign in again.'], 401);
}
if (!can('comms.access')) {
    json_response(['ok' => false, 'message' => 'You do not have access to Communication.'], 403);
}
if (!Security::verifyCsrf()) {
    json_response(['ok' => false, 'message' => 'Your session expired. Reload the page and try again.'], 419);
}
// A file bigger than PHP's post_max_size arrives with an empty $_POST/$_FILES. Checked only AFTER login,
// access and CSRF (the token travels in a header), so signed-out callers still get 401.
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    json_response(['ok' => false, 'message' => 'The upload is larger than this server allows (PHP limit ' . ini_get('post_max_size') . '). Ask the administrator to raise post_max_size and upload_max_filesize in php.ini.'], 413);
}
$me = (int)Auth::id();
session_write_close(); // release the session lock; everything needed is already read

try {
    $action = post('action');
    switch ($action) {
        case 'directory':
            $rows = comms_directory($me, mb_substr(post('q'), 0, 60));
            json_response(['ok' => true, 'people' => array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'name' => $r['name'], 'role' => $r['role'], 'code' => $r['employee_code']], $rows)]);

        case 'open_private':
            json_response(comms_private_open($me, (int)post('user_id')));

        case 'create_group':
            $ids = array_filter(explode(',', post('members')), 'strlen');
            json_response(comms_group_create($me, post('name'), $ids));

        case 'group_add':
            json_response(comms_group_add($me, (int)post('conversation_id'), (int)post('user_id')));

        case 'group_remove':
            json_response(comms_group_remove($me, (int)post('conversation_id'), (int)post('user_id')));

        case 'members':
            $r = comms_members_list($me, (int)post('conversation_id'));
            json_response($r, $r['ok'] ? 200 : 403);

        case 'send_file':
            $f = $_FILES['file'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $code = is_array($f) ? (int)$f['error'] : UPLOAD_ERR_NO_FILE;
                $msg = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The file is larger than this server allows (PHP limit ' . ini_get('upload_max_filesize') . ').' : 'No file was received. Please try again.';
                json_response(['ok' => false, 'message' => $msg], 422);
            }
            $r = comms_send_file($me, (int)post('conversation_id'), (string)$f['tmp_name'], (string)$f['name'], (int)$f['size'], post('uuid'), (string)($_POST['body'] ?? ''), true, (int)post('reply_to'));
            json_response($r, $r['ok'] ? 200 : 422);

        case 'send_voice':
            $f = $_FILES['file'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                json_response(['ok' => false, 'message' => 'The recording was not received. Please try again.'], 422);
            }
            $r = comms_send_voice($me, (int)post('conversation_id'), (string)$f['tmp_name'], (int)post('duration'), post('uuid'), true, (int)post('reply_to'));
            json_response($r, $r['ok'] ? 200 : 422);

        case 'file_link':
            $a = Database::fetch('SELECT a.id, a.message_id FROM comms_attachments a WHERE a.id = :i', ['i' => (int)post('attachment_id')]);
            $auth = $a ? comms_authorize_attachment($me, (int)$a['id'], comms_link_token($me, 'a' . (int)$a['id'])) : ['ok' => false];
            if (!$auth['ok']) {
                json_response(['ok' => false, 'message' => 'This file is not available.'], 404);
            }
            json_response(['ok' => true, 'url' => comms_file_url($me, (int)$a['id'], post('download') === '1'), 'ttl' => COMMS_LINK_TTL]);

        case 'photo_link':
            if (comms_membership((int)post('conversation_id'), $me) === null) {
                json_response(['ok' => false, 'message' => 'Not available.'], 403);
            }
            json_response(['ok' => true, 'url' => base_url('modules/comms/process/file.php') . '?photo=' . (int)post('conversation_id') . '&t=' . comms_link_token($me, 'p' . (int)post('conversation_id'))]);

        case 'group_photo':
            $f = $_FILES['file'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                json_response(['ok' => false, 'message' => 'No picture received.'], 422);
            }
            $r = comms_group_photo($me, (int)post('conversation_id'), (string)$f['tmp_name'], (string)$f['name']);
            json_response($r, $r['ok'] ? 200 : 422);

        case 'rename':
            json_response(comms_group_rename($me, (int)post('conversation_id'), post('name')));

        case 'edit':
            $r = comms_edit($me, (int)post('message_id'), (string)($_POST['body'] ?? ''));
            json_response($r, $r['ok'] ? 200 : 422);

        case 'delete':
            $r = comms_delete($me, (int)post('message_id'));
            json_response($r, $r['ok'] ? 200 : 422);

        case 'hide':
            json_response(comms_hide($me, (int)post('message_id')));

        case 'react':
            json_response(comms_react($me, (int)post('message_id'), (string)($_POST['emoji'] ?? '')));

        case 'pin':
            json_response(comms_pin($me, (int)post('message_id'), post('on') !== '0'));

        case 'announce':
            json_response(comms_announce($me, (int)post('message_id'), post('on') !== '0'));

        case 'readers':
            json_response(comms_readers($me, (int)post('message_id')));

        case 'forward':
            json_response(comms_forward($me, (int)post('message_id'), array_filter(explode(',', post('targets')), 'strlen')));

        case 'search':
            json_response(comms_search($me, (string)($_POST['q'] ?? ''), ['conversation_id' => (int)post('conversation_id'), 'sender_id' => (int)post('sender_id'), 'from' => post('from'), 'to' => post('to')]));

        case 'send':
            $r = comms_send($me, (int)post('conversation_id'), (string)($_POST['body'] ?? ''), post('uuid'), (int)post('reply_to'));
            json_response($r, $r['ok'] ? 200 : 422);

        case 'fetch':
            $r = comms_fetch($me, (int)post('conversation_id'), (int)post('after'), (int)post('before'), (int)post('limit') ?: 30, post('asc') === '1');
            json_response($r, $r['ok'] ? 200 : 403);

        case 'read':
            json_response(['ok' => comms_mark_read($me, (int)post('conversation_id'), (int)post('up_to'))]);

        case 'typing':
            comms_set_typing($me, (int)post('conversation_id'));
            json_response(['ok' => true]);

        case 'poll':
            comms_touch_presence($me);
            comms_run_scheduled();
            comms_mark_delivered($me);
            $convs = comms_conversations($me);
            $out = ['ok' => true, 'conversations' => $convs, 'unread' => array_sum(array_column($convs, 'unread')), 'interval' => max(2, min(60, (int)comms_setting('comms_poll_interval_sec')))];
            $active = (int)post('conversation_id');
            if ($active > 0) {
                $polled = post('after') !== '';
                $f = $polled ? comms_fetch($me, $active, max(0, (int)post('after')), 0, 100, true) : comms_fetch($me, $active, 0, 0, 1);
                if ($f['ok']) {
                    $out['messages'] = $polled ? $f['messages'] : [];
                    $mine = array_map(static fn(string $i): int => (int)$i, array_filter(explode(',', post('track')), 'strlen'));
                    if (post('since') !== '') {
                        $u = comms_fetch($me, $active, 0, 0, 100, false, max(0, (int)post('since')));
                        $out['updates'] = $u['ok'] ? $u['messages'] : [];
                    }
                    $out['now_ms'] = $f['now_ms'];
                    $out['pins'] = comms_pins_list($me, $active);
                    $out['receipts'] = comms_receipts($me, $active, array_slice($mine, 0, 100));
                    $out['typing'] = comms_typing_names($me, $active);
                } else {
                    $out['lost_access'] = true;
                }
            }
            $st = comms_storage_state();
            $out['storage'] = ['full' => $st['full'], 'warn' => $st['warn']];
            json_response($out);

        default:
            json_response(['ok' => false, 'message' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    log_event('comms', 'chat endpoint error', ['action' => post('action'), 'error' => $e->getMessage()]);
    json_response(['ok' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
