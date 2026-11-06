<?php
/**
 * Gatekeeper for private complaint files (evidence thumbnails, previews,
 * originals and normal attachments).
 *
 *   ?c=<complaint id>&t=thumb|preview|full|attachment&i=<file id>[&dl=1]
 *
 * Files live in storage/complaints/, which the web server refuses to serve.
 * Every request is checked for a signed-in user, complaint.view, and the
 * user's UC scope over THAT complaint, so a guessed or edited id returns
 * 403/404 instead of a picture. Immutable files are cached privately by
 * the browser (ETag + max-age) so a gallery of 100 thumbnails is only
 * fetched once.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    http_response_code(503);
    exit('Not installed.');
}

$complaintId = (int)query('c');
$type = query('t');
$fileId = (int)query('i');
$download = query('dl') === '1';

$complaint = cmp_require_access($complaintId, 'complaint.view');

$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('File not found.');
};

if ($fileId <= 0 || !in_array($type, ['thumb', 'preview', 'full', 'attachment'], true)) {
    $notFound();
}

if ($type === 'attachment') {
    $row = Database::fetch('SELECT * FROM complaint_attachments WHERE id = :i AND complaint_id = :c', ['i' => $fileId, 'c' => $complaintId]);
    if ($row === null) {
        $notFound();
    }
    $stored = (string)$row['file_name'];
    $downloadName = (string)$row['original_name'];
} else {
    $row = Database::fetch('SELECT * FROM complaint_evidence WHERE id = :i AND complaint_id = :c', ['i' => $fileId, 'c' => $complaintId]);
    if ($row === null) {
        $notFound();
    }
    $stored = (string)match ($type) { 'thumb' => $row['thumb_name'], 'preview' => $row['preview_name'], default => $row['file_name'] };
    $downloadName = (string)$row['original_name'];
}

$path = cmp_file_path($complaintId, $stored);
if ($path === null) {
    $notFound();
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = CMP_ATTACH_MIME[$ext] ?? ($ext === 'webp' ? 'image/webp' : 'application/octet-stream');
$inlineOk = in_array($ext, ['jpg', 'png', 'webp', 'pdf'], true) && !$download;

if ($download) {
    log_event('complaints', 'File downloaded', ['user_id' => Auth::id(), 'complaint_id' => $complaintId, 'type' => $type, 'file_id' => $fileId]);
}

$etag = '"' . md5($stored . '|' . (string)filemtime($path) . '|' . (string)filesize($path)) . '"';
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();   // don't hold the session lock while streaming
}
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('ETag: ' . $etag);
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
$safeName = str_replace(['"', '\\', "\r", "\n"], '_', $downloadName);
header('Content-Disposition: ' . ($inlineOk ? 'inline' : 'attachment') . '; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));

readfile($path);
exit;
