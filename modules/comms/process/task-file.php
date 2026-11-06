<?php
/**
 * Serves task-completion evidence. GET only. Every request re-checks: signed in, comms.access, an unexpired link made for THIS user and
 * THIS file, task participation, and the evidence rule (submitter / reviewer, or another participant holding comms.evidence_view).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

function comms_evidence_fail(int $status): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo match ($status) { 401 => 'Please sign in again.', 403 => 'This link is not valid, has expired, or you do not have access.', default => 'Not found.' };
    exit;
}

if (!IS_INSTALLED || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    comms_evidence_fail(405);
}
if (!Auth::check()) {
    comms_evidence_fail(401);
}
$me = (int)Auth::id();
session_write_close();
if (!can('comms.access')) {
    comms_evidence_fail(403);
}
$auth = comms_authorize_evidence($me, (int)($_GET['id'] ?? 0), (string)($_GET['t'] ?? ''));
if (!$auth['ok']) {
    comms_evidence_fail($auth['status']);
}
$ev = $auth['ev'];
$path = comms_evidence_path($ev);
if (!is_file($path) || !preg_match('/^[0-9a-f]{32}$/', (string)$ev['stored_name'])) {
    comms_evidence_fail(404);
}
$size = (int)filesize($path);
$mime = (string)$ev['mime'];
$inline = ($_GET['dl'] ?? '') !== '1' && comms_inline_ok($mime);
$fname = comms_safe_filename((string)$ev['original_name']);
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: private, no-store');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename=\"" . str_replace('"', '', preg_replace('/[^\x20-\x7E]/', '_', $fname)) . "\"; filename*=UTF-8''" . rawurlencode($fname));
header('Content-Length: ' . (string)$size);
readfile($path);
