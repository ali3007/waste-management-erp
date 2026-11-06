<?php
/** Download the ORIGINAL uploaded template (managers only). */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ppt.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('complaint.ppt_templates');

$row = Database::fetch('SELECT * FROM ppt_templates WHERE id = :id', ['id' => (int)query('id')]);
$path = $row ? CMP_TEMPLATE_DIR . '/' . $row['file_name'] : '';
if ($row === null || !preg_match('/^[A-Za-z0-9._-]+$/', (string)$row['file_name']) || !is_file($path)) {
    flash('error', 'Template not found.');
    redirect('modules/complaints/ppt-templates.php');
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
$name = cmp_clean_filename((string)$row['original_name']);
header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '_', $name) . '"');
header('Cache-Control: private, no-store');
readfile($path);
exit;
