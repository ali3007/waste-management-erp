<?php
/**
 * Upload Before or After pictures — one small batch per request.
 *
 * The browser sends a chosen folder/file list in batches (assets/js/
 * complaint-gallery.js), so 100 pictures are never limited by PHP's
 * max_file_uploads / post_max_size. kind is chosen by the USER pressing
 * "Upload Before Pictures" or "Upload After Pictures" — the system never
 * guesses. New pictures are appended to the end of that kind's sequence.
 * JSON in / JSON out.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    json_response(['ok' => false, 'message' => 'Not installed.'], 503);
}
if (!is_post()) {
    json_response(['ok' => false, 'message' => 'Invalid request.'], 405);
}
Auth::requireLogin();
if (cmp_request_too_large()) {
    json_response(['ok' => false, 'message' => cmp_too_large_message()], 413);
}
if (!Security::verifyCsrf()) {
    json_response(['ok' => false, 'message' => 'Your session expired. Reload the page and try again.'], 419);
}

$complaintId = (int)post('complaint_id');
$kind = post('kind');
if (!in_array($kind, ['before', 'after'], true)) {
    json_response(['ok' => false, 'message' => 'Invalid evidence type.'], 422);
}

$complaint = cmp_require_access($complaintId, 'complaint.view');
cmp_require_action($complaint, $kind === 'before' ? 'evidence_before' : 'evidence_after');

$files = cmp_files_list('files');
if (!$files) {
    json_response(['ok' => false, 'message' => 'No pictures were received.'], 422);
}
if (count($files) > 25) {
    json_response(['ok' => false, 'message' => 'Too many pictures in one request.'], 422);
}

$items = [];
$errors = [];
foreach ($files as $file) {
    $r = cmp_add_evidence($complaintId, $kind, $file);
    if ($r['ok']) {
        $items[] = $r['item'];
    } else {
        $errors[] = ['name' => cmp_clean_filename((string)($file['name'] ?? 'file')), 'message' => $r['message']];
    }
}

if ($items) {
    $count = count($items);
    $title = ($kind === 'before' ? 'Before' : 'After') . ' evidence added';
    cmp_timeline($complaintId, $kind . '_added', $title, null, $count . ' picture' . ($count === 1 ? '' : 's'), null);
    Database::execute('UPDATE complaints SET updated_at = :n WHERE id = :id', ['n' => cmp_now(), 'id' => $complaintId]);
}

json_response(['ok' => $items !== [] || $errors === [], 'items' => $items, 'errors' => $errors, 'csrf' => Security::csrfToken()]);
