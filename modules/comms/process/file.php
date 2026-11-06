<?php
/**
 * Serves chat files and group photos. GET only. Every request re-checks: signed-in, comms.access, a
 * valid unexpired link made for THIS user and THIS file, active membership, the member's history
 * window, and that the message is neither deleted nor hidden for them. Files are never reachable by path.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

function comms_file_fail(int $status): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo match ($status) { 401 => 'Please sign in again.', 403 => 'This link is not valid or has expired.', default => 'Not found.' };
    exit;
}

if (!IS_INSTALLED || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    comms_file_fail(405);
}
if (!Auth::check()) {
    comms_file_fail(401);
}
$me = (int)Auth::id();
session_write_close();
if (!can('comms.access')) {
    comms_file_fail(403);
}
$token = (string)($_GET['t'] ?? '');
$download = ($_GET['dl'] ?? '') === '1';

if (isset($_GET['photo'])) {
    $conv = (int)$_GET['photo'];
    if (!comms_link_valid($me, 'p' . $conv, $token) || comms_membership($conv, $me) === null) {
        comms_file_fail(403);
    }
    $photo = Database::fetchValue('SELECT photo FROM comms_conversations WHERE id = :c', ['c' => $conv]);
    $path = is_string($photo) && preg_match('/^[0-9a-f]{32}$/', $photo) ? comms_storage_root() . '/photos/' . $photo : '';
    if ($path === '' || !is_file($path)) {
        comms_file_fail(404);
    }
    $info = @getimagesize($path);
    $mime = $info !== false ? (string)$info['mime'] : 'application/octet-stream';
    header('Content-Type: ' . (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $mime : 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=60');
    header('Content-Length: ' . (string)filesize($path));
    readfile($path);
    exit;
}

$auth = comms_authorize_attachment($me, (int)($_GET['id'] ?? 0), $token);
if (!$auth['ok']) {
    comms_file_fail($auth['status']);
}
$att = $auth['att'];
$path = comms_attachment_path($att);
if (!is_file($path) || !preg_match('/^[0-9a-f]{32}$/', (string)$att['stored_name'])) {
    comms_file_fail(404);
}
$size = (int)filesize($path);
$mime = (string)$att['mime'];
$inline = !$download && comms_inline_ok($mime);
$fname = comms_safe_filename((string)$att['original_name']);

header('Content-Type: ' . ($inline || $download ? $mime : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: private, no-store');
header('Accept-Ranges: bytes');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"" . str_replace('"', '', preg_replace('/[^\x20-\x7E]/', '_', $fname)) . "\"; filename*=UTF-8''" . rawurlencode($fname));

$start = 0;
$end = $size - 1;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {
        $start = max(0, $size - (int)$m[2]);
    } else {
        $start = (int)$m[1];
        $end = $m[2] !== '' ? min((int)$m[2], $size - 1) : $end;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . (string)($end - $start + 1));
$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, min(8192, $left));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $left -= strlen($chunk);
}
fclose($fh);
