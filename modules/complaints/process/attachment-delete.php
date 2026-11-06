<?php
/** Remove one normal attachment (file + record) and note it in the timeline. */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
$complaintId = (int)post('complaint_id');
$backTo = 'modules/complaints/complaint-view.php?id=' . $complaintId . '#attachments';
if (!is_post()) {
    redirect($backTo);
}
Auth::requireLogin();
Security::requireCsrf($backTo);

$complaint = cmp_require_access($complaintId, 'complaint.view');
cmp_require_action($complaint, 'attach');

$row = Database::fetch('SELECT * FROM complaint_attachments WHERE id = :i AND complaint_id = :c', ['i' => (int)post('id'), 'c' => $complaintId]);
if ($row === null) {
    flash('error', 'Attachment not found.');
    redirect($backTo);
}
Database::execute('DELETE FROM complaint_attachments WHERE id = :id', ['id' => (int)$row['id']]);
$path = cmp_file_path($complaintId, (string)$row['file_name']);
if ($path !== null) {
    @unlink($path);
}
cmp_timeline($complaintId, 'attachment_removed', 'Attachment removed', (string)$row['original_name'], null);
flash('success', 'Attachment removed.');
redirect($backTo);
