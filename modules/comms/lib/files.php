<?php
/**
 * Chat files and voice: content-based validation, private storage, quota, expiring download links.
 *
 * Files live in storage/comms/ (blocked from the web by .htaccess) under random names with no
 * extension. They are only ever served by process/file.php, which re-checks login, membership,
 * the member's history window and whether the message was deleted — on every single request.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const COMMS_LINK_TTL = 300;          // seconds a download/preview link stays valid
const COMMS_STORAGE_WARN = 0.90;     // warn at 90% full

function comms_storage_root(): string
{
    $dir = dirname(__DIR__, 3) . '/storage/comms';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

/** Map of extension => [mime, kind]. kind: image|pdf|office|text|archive. Unknown extensions are refused. */
function comms_file_types(): array
{
    return [
        'jpg' => ['image/jpeg', 'image'], 'jpeg' => ['image/jpeg', 'image'], 'png' => ['image/png', 'image'],
        'gif' => ['image/gif', 'image'], 'webp' => ['image/webp', 'image'],
        'pdf' => ['application/pdf', 'pdf'],
        'doc' => ['application/msword', 'ole'], 'xls' => ['application/vnd.ms-excel', 'ole'], 'ppt' => ['application/vnd.ms-powerpoint', 'ole'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'zip'],
        'zip' => ['application/zip', 'zip'],
        'txt' => ['text/plain', 'text'], 'csv' => ['text/csv', 'text'],
    ];
}

function comms_allowed_extensions(): array
{
    $list = array_filter(array_map('trim', explode(',', strtolower(comms_setting('comms_allowed_extensions')))));
    return array_values(array_diff($list, COMMS_FORBIDDEN_EXT));
}

function comms_storage_used(): int
{
    // A forwarded file shares one stored copy, so count each stored file once.
    return (int)Database::fetchValue('SELECT COALESCE(SUM(s), 0) FROM (SELECT MAX(size_bytes) AS s FROM comms_attachments GROUP BY stored_name UNION ALL SELECT MAX(size_bytes) AS s FROM comms_task_evidence GROUP BY stored_name) x');
}

function comms_storage_limit(): int
{
    return (int)comms_setting('comms_storage_limit_mb') * 1048576;
}

function comms_storage_state(): array
{
    $used = comms_storage_used();
    $limit = max(1, comms_storage_limit());
    return ['used' => $used, 'limit' => $limit, 'full' => $used >= $limit, 'warn' => $used >= $limit * COMMS_STORAGE_WARN];
}

/** Does the file's real content match its claimed type? */
function comms_content_matches(string $path, string $ext, string $kind): bool
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return false;
    }
    $head = (string)fread($fh, 4096);
    fclose($fh);
    switch ($kind) {
        case 'image':
            $info = @getimagesize($path);
            $want = ['jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'gif' => IMAGETYPE_GIF, 'webp' => IMAGETYPE_WEBP][$ext] ?? -1;
            if ($info === false || $info[2] !== $want) {
                return false;
            }
            // Refuse images that also carry server-side code (polyglots).
            $all = (string)file_get_contents($path, false, null, 0, 2097152);
            return !preg_match('/<\?(php|=)|<script/i', $all);
        case 'pdf':
            return str_starts_with($head, '%PDF-');
        case 'ole':
            return str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
        case 'zip':
            return str_starts_with($head, "PK\x03\x04");
        case 'text':
            return !str_contains($head, "\0") && (mb_check_encoding($head, 'UTF-8') || mb_check_encoding(substr($head, 0, -3), 'UTF-8'));
    }
    return false;
}

/** Audio signature -> [mime, ext] or null. Decided from the bytes, never from the browser's claim. */
function comms_voice_signature(string $path): ?array
{
    $head = (string)@file_get_contents($path, false, null, 0, 16);
    if (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
        return ['audio/webm', 'webm'];
    }
    if (str_starts_with($head, 'OggS')) {
        return ['audio/ogg', 'ogg'];
    }
    if (substr($head, 4, 4) === 'ftyp') {
        return ['audio/mp4', 'm4a'];
    }
    if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE') {
        return ['audio/wav', 'wav'];
    }
    return null;
}

function comms_safe_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F"<>|*?:\/\\\\]/u', '_', $name) ?? 'file';
    $name = trim($name, " .\t");
    if ($name === '') {
        $name = 'file';
    }
    return mb_substr($name, 0, 120);
}

/**
 * Validate and store an uploaded file, then post it as a message. $isUploaded=false is for tests only.
 * @return array result of comms_res()
 */
function comms_send_file(int $me, int $convId, string $tmpPath, string $origName, int $clientSize, string $uuid, string $caption = '', bool $isUploaded = true, int $replyTo = 0): array
{
    if (!comms_actor_can($me, 'comms.send_file')) {
        return comms_res(false, 'You do not have permission to send files.');
    }
    if (comms_membership($convId, $me) === null) {
        return comms_res(false, 'You are not a member of this conversation.');
    }
    if ($isUploaded && !is_uploaded_file($tmpPath)) {
        return comms_res(false, 'The upload was not valid. Please try again.');
    }
    $name = comms_safe_filename($origName);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $types = comms_file_types();
    if ($ext === '' || in_array($ext, COMMS_FORBIDDEN_EXT, true) || !isset($types[$ext]) || !in_array($ext, comms_allowed_extensions(), true)) {
        return comms_res(false, 'This file type is not allowed. Allowed: ' . implode(', ', array_intersect(comms_allowed_extensions(), array_keys($types))) . '.');
    }
    $size = (int)@filesize($tmpPath);
    $max = (int)comms_setting('comms_max_file_mb') * 1048576;
    if ($size <= 0) {
        return comms_res(false, 'The file is empty.');
    }
    if ($size > $max) {
        return comms_res(false, 'This file is larger than the ' . comms_setting('comms_max_file_mb') . ' MB limit.');
    }
    if (!comms_content_matches($tmpPath, $ext, $types[$ext][1])) {
        return comms_res(false, 'The file content does not match its type, so it was refused.');
    }
    return comms_store_and_post($me, $convId, $tmpPath, $name, $types[$ext][0], 'file', $size, null, $uuid, $caption, $isUploaded, $replyTo);
}

function comms_send_voice(int $me, int $convId, string $tmpPath, int $durationSec, string $uuid, bool $isUploaded = true, int $replyTo = 0): array
{
    if (!comms_actor_can($me, 'comms.send_voice')) {
        return comms_res(false, 'You do not have permission to send voice messages.');
    }
    if (comms_membership($convId, $me) === null) {
        return comms_res(false, 'You are not a member of this conversation.');
    }
    if ($isUploaded && !is_uploaded_file($tmpPath)) {
        return comms_res(false, 'The upload was not valid. Please try again.');
    }
    $maxSec = (int)comms_setting('comms_voice_max_sec');
    if ($durationSec < 1 || $durationSec > $maxSec + 1) {
        return comms_res(false, 'Voice messages can be at most ' . $maxSec . ' seconds.');
    }
    $size = (int)@filesize($tmpPath);
    if ($size <= 0 || $size > (int)comms_setting('comms_max_file_mb') * 1048576 || $size > ($durationSec + 1) * 65536) {
        return comms_res(false, 'The recording is too large.');
    }
    $sig = comms_voice_signature($tmpPath);
    if ($sig === null) {
        return comms_res(false, 'This is not a recognised audio recording.');
    }
    return comms_store_and_post($me, $convId, $tmpPath, 'Voice message ' . comms_now()->format('Y-m-d H.i') . '.' . $sig[1], $sig[0], 'voice', $size, $durationSec, $uuid, '', $isUploaded, $replyTo);
}

function comms_store_and_post(int $me, int $convId, string $tmp, string $name, string $mime, string $kind, int $size, ?int $dur, string $uuid, string $caption, bool $isUploaded, int $replyTo): array
{
    if (!comms_is_uuid($uuid)) {
        return comms_res(false, 'Invalid message identifier.');
    }
    // A retry of the same upload must not store a second copy.
    $dup = Database::fetch('SELECT id FROM comms_messages WHERE sender_id = :s AND client_uuid = :u', ['s' => $me, 'u' => $uuid]);
    if ($dup !== null) {
        return comms_res(true, '', ['id' => (int)$dup['id'], 'duplicate' => true, 'conversation_id' => $convId]);
    }
    $state = comms_storage_state();
    if ($state['used'] + $size > $state['limit']) {
        return comms_res(false, 'Storage is full. Ask an administrator to free space or raise the storage limit.');
    }
    $caption = comms_clean_body($caption);
    if (mb_strlen($caption) > COMMS_MAX_BODY) {
        return comms_res(false, 'The caption is too long.');
    }
    $stored = bin2hex(random_bytes(16));
    $dir = comms_storage_root() . '/files/' . substr($stored, 0, 2);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        log_event('comms', 'Cannot create storage directory', ['dir' => $dir]);
        return comms_res(false, 'The file could not be stored. Please contact the administrator.');
    }
    $dest = $dir . '/' . $stored;
    $moved = $isUploaded ? @move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
    if (!$moved) {
        return comms_res(false, 'The file could not be stored. Please try again.');
    }
    @chmod($dest, 0640);
    $sha = hash_file('sha256', $dest);

    $r = comms_send($me, $convId, $caption, $uuid, $replyTo, true);
    if (!$r['ok'] || !empty($r['duplicate'])) {
        @unlink($dest);
        return $r;
    }
    try {
        Database::insert('comms_attachments', [
            'message_id' => $r['id'], 'conversation_id' => $convId, 'uploader_id' => $me, 'kind' => $kind, 'original_name' => $name,
            'stored_name' => $stored, 'mime' => $mime, 'size_bytes' => $size, 'sha256' => $sha, 'duration_sec' => $dur,
        ]);
    } catch (Throwable $e) {
        Database::execute('DELETE FROM comms_messages WHERE id = :i', ['i' => $r['id']]);
        @unlink($dest);
        log_event('comms', 'attachment insert failed', ['error' => $e->getMessage()]);
        return comms_res(false, 'The file could not be saved. Please try again.');
    }
    comms_audit_safe($kind === 'voice' ? 'chat.voice_sent' : 'chat.file_sent', ucfirst($kind) . ' sent', ['actor_id' => $me, 'subject_type' => 'message', 'subject_id' => (int)$r['id'], 'new' => ['conversation' => $convId, 'name' => $name, 'bytes' => $size, 'sha256' => $sha]]);
    return $r;
}

/* ---------------- expiring links ---------------- */

function comms_link_secret(): string
{
    $s = Database::fetchValue("SELECT setting_value FROM system_settings WHERE setting_key = 'comms_link_secret'");
    if (!is_string($s) || strlen($s) < 32) {
        Database::execute("INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_group) VALUES ('comms_link_secret', :v, 'comms')", ['v' => bin2hex(random_bytes(32))]);
        $s = (string)Database::fetchValue("SELECT setting_value FROM system_settings WHERE setting_key = 'comms_link_secret'");
    }
    return $s;
}

/** token = expiry.signature; bound to the user, the object and the expiry. */
function comms_link_token(int $userId, string $object, ?int $now = null): string
{
    $exp = ($now ?? time()) + COMMS_LINK_TTL;
    return $exp . '.' . hash_hmac('sha256', $userId . '|' . $object . '|' . $exp, comms_link_secret());
}

function comms_link_valid(int $userId, string $object, string $token, ?int $now = null): bool
{
    if (!preg_match('/^(\d{9,11})\.([0-9a-f]{64})$/', $token, $m)) {
        return false;
    }
    if ((int)$m[1] < ($now ?? time())) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $userId . '|' . $object . '|' . $m[1], comms_link_secret()), $m[2]);
}

function comms_file_url(int $userId, int $attachmentId, bool $download): string
{
    return base_url('modules/comms/process/file.php') . '?id=' . $attachmentId . '&t=' . comms_link_token($userId, 'a' . $attachmentId) . ($download ? '&dl=1' : '');
}

/**
 * Everything the file endpoint must verify, in one place (also unit-tested).
 * @return array{ok:bool,status:int,att?:array}
 */
function comms_authorize_attachment(int $me, int $attId, string $token): array
{
    if (!comms_link_valid($me, 'a' . $attId, $token)) {
        return ['ok' => false, 'status' => 403];
    }
    $att = Database::fetch(
        'SELECT a.*, g.deleted_at, g.conversation_id AS conv FROM comms_attachments a JOIN comms_messages g ON g.id = a.message_id WHERE a.id = :i',
        ['i' => $attId]
    );
    if ($att === null) {
        return ['ok' => false, 'status' => 404];
    }
    $m = comms_membership((int)$att['conv'], $me);
    if ($m === null || (int)$att['message_id'] <= (int)$m['joined_after_msg_id'] || !comms_actor_can($me, 'comms.access')) {
        return ['ok' => false, 'status' => 403];
    }
    if ($att['deleted_at'] !== null || Database::exists('SELECT 1 FROM comms_message_hidden WHERE message_id = :m AND user_id = :u', ['m' => $att['message_id'], 'u' => $me])) {
        return ['ok' => false, 'status' => 404];
    }
    return ['ok' => true, 'status' => 200, 'att' => $att];
}

function comms_attachment_path(array $att): string
{
    return comms_storage_root() . '/files/' . substr((string)$att['stored_name'], 0, 2) . '/' . $att['stored_name'];
}

/** Types the browser may render inline; everything else is always a download. */
function comms_inline_ok(string $mime): bool
{
    return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'audio/webm', 'audio/ogg', 'audio/mp4', 'audio/wav'], true);
}
