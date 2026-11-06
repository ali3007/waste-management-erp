<?php
/**
 * Adds one more "other document" to an existing employee from their
 * profile page. (Pictures are replaced through Edit Employee.)
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('employee.doc_create');

if (!is_post()) {
    redirect('modules/hr/employees.php');
}

$employeeId = (int)post('employee_id');
$backTo = 'modules/hr/employee-view.php?id=' . $employeeId . '#documents';
Security::requireCsrf($backTo);

$employee = $employeeId > 0 ? Database::fetch('SELECT id, name FROM employees WHERE id = :id', ['id' => $employeeId]) : null;
if ($employee === null) {
    flash('error', 'Record not found.');
    redirect('modules/hr/employees.php');
}
hr_require_employee_scope($employeeId);

$files = hr_files_list('document');
if ($files === []) {
    flash('error', 'Choose a file to upload.');
    redirect($backTo);
}
if (count($files) > 20) {
    flash('error', 'Upload at most 20 documents at a time.');
    redirect($backTo);
}

$title = Security::cleanText(post('title'), 150);
$ok = 0;
$problems = [];

foreach ($files as $file) {
    $result = hr_store_upload($file, 'doc', $employeeId);
    if (!$result['ok']) {
        $problems[] = hr_clean_original_name((string)($file['name'] ?? 'file')) . ': ' . $result['message'];
        continue;
    }
    Database::insert('employee_documents', [
        'employee_id' => $employeeId, 'file_name' => $result['file'], 'original_name' => $result['original'],
        'title' => $title !== '' && count($files) === 1 ? $title : mb_substr(pathinfo($result['original'], PATHINFO_FILENAME), 0, 150),
        'mime_type' => $result['mime'], 'file_size' => $result['size'], 'uploaded_by' => Auth::id(),
    ]);
    hr_log('employee.document_uploaded', $employeeId, 'Document "' . $result['original'] . '" uploaded for ' . $employee['name']);
    $ok++;
}

if ($ok > 0) {
    flash('success', $ok === 1 ? 'Document uploaded successfully.' : $ok . ' documents uploaded successfully.');
}
if ($problems) {
    flash($ok > 0 ? 'warning' : 'error', implode(' ', $problems));
}
redirect($backTo);
