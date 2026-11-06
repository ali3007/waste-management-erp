<?php
/**
 * Add normal attachments (PDF, DOC/DOCX, XLS/XLSX, JPG, PNG) to a complaint.
 * Works for the create form's queued upload (AJAX) and the detail page.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
if (!is_post()) {
    redirect('modules/complaints/index.php');
}
Auth::requireLogin();

$ajax = is_ajax();
$complaintId = (int)post('complaint_id');
$backTo = 'modules/complaints/complaint-view.php?id=' . $complaintId . '#attachments';
if (cmp_request_too_large()) {
    $msg = cmp_too_large_message();
    $ajax ? json_response(['ok' => false, 'message' => $msg], 413) : (flash('error', $msg) ?: redirect('modules/complaints/index.php'));
}
if (!Security::verifyCsrf()) {
    $ajax ? json_response(['ok' => false, 'message' => 'Your session expired. Reload the page and try again.'], 419) : Security::requireCsrf($backTo);
}

$complaint = cmp_require_access($complaintId, 'complaint.view');
cmp_require_action($complaint, 'attach');

$files = cmp_files_list('files');
if (!$files) {
    $msg = 'Choose a file to upload.';
    $ajax ? json_response(['ok' => false, 'message' => $msg], 422) : (flash('error', $msg) ?: redirect($backTo));
}

$added = [];
$errors = [];
foreach (array_slice($files, 0, 20) as $file) {
    $r = cmp_add_attachment($complaintId, $file);
    if ($r['ok']) {
        $added[] = cmp_clean_filename((string)$file['name']);
    } else {
        $errors[] = cmp_clean_filename((string)($file['name'] ?? 'file')) . ': ' . $r['message'];
    }
}
if ($added) {
    cmp_timeline($complaintId, 'attachment_added', 'Attachment added', null, implode(', ', $added));
}

if ($ajax) {
    json_response(['ok' => $added !== [] || $errors === [], 'added' => count($added), 'errors' => $errors]);
}
if ($added) {
    flash('success', count($added) === 1 ? 'Attachment uploaded.' : count($added) . ' attachments uploaded.');
}
if ($errors) {
    flash($added ? 'warning' : 'error', implode(' ', $errors));
}
redirect($backTo);
