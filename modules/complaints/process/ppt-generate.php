<?php
/**
 * Build and download the PowerPoint for one complaint.
 *
 * Needs complaint.ppt and access to the complaint (UC scope). The deck is
 * built from a temporary COPY of the chosen template (the original is never
 * modified), streamed to the user and the temporary file is removed.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ppt.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

$id = (int)post('id');
$back = 'modules/complaints/ppt-preview.php?id=' . $id;
if (!is_post()) {
    redirect($back);
}
Auth::requireLogin();
Security::requireCsrf($back);

$c = cmp_require_access($id, 'complaint.ppt');
if ($c['deleted_at'] !== null) {
    flash('error', 'A deleted complaint cannot be exported.');
    redirect('modules/complaints/complaint-view.php?id=' . $id);
}

$template = Database::fetch("SELECT * FROM ppt_templates WHERE id = :id AND status = 'active'", ['id' => (int)post('template_id')]);
if ($template === null) {
    flash('error', 'Choose an active template.');
    redirect($back);
}

$before = cmp_evidence_list($id, 'before');
$after = cmp_evidence_list($id, 'after');
$userId = (int)Auth::id();

try {
    $result = cmp_ppt_generate($template, $c, $before, $after);
} catch (Throwable $e) {
    log_event('complaints', 'PPT generation failed', ['complaint_id' => $id, 'template_id' => (int)$template['id'], 'error' => $e->getMessage()]);
    flash('error', 'The presentation could not be generated. ' . (APP_ENV === 'development' ? $e->getMessage() : 'Check the log for details.'));
    redirect($back);
}

cmp_timeline($id, 'ppt', 'PowerPoint generated', null, $result['slides'] . ' slides', 'Template: ' . $template['name'] . ' · ' . count($before) . ' Before / ' . count($after) . ' After', $userId ?: null);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
$name = 'Complaint-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$c['complaint_number']) . '.pptx';
header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
header('Content-Length: ' . (string)filesize($result['path']));
header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Cache-Control: private, no-store');
readfile($result['path']);
@unlink($result['path']);
exit;
