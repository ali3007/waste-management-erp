<?php
/**
 * Deletes one stored "other document" (file + record). Employee pictures
 * are removed through Edit Employee.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('employee.doc_delete');

if (!is_post()) {
    redirect('modules/hr/employees.php');
}

$docId = (int)post('document_id');
$employeeId = (int)post('employee_id');
$backTo = 'modules/hr/employee-view.php?id=' . $employeeId . '#documents';
Security::requireCsrf($backTo);

$doc = Database::fetch(
    'SELECT d.*, e.name AS employee_name FROM employee_documents d JOIN employees e ON e.id = d.employee_id WHERE d.id = :d AND d.employee_id = :e',
    ['d' => $docId, 'e' => $employeeId]
);
if ($doc === null) {
    flash('error', 'Record not found.');
    redirect($backTo);
}
hr_require_employee_scope($employeeId);

try {
    Database::execute('DELETE FROM employee_documents WHERE id = :id', ['id' => $docId]);
    hr_delete_stored_file((string)$doc['file_name']);
    hr_log('employee.document_deleted', $employeeId, 'Document "' . $doc['original_name'] . '" deleted for ' . $doc['employee_name']);
} catch (Throwable $e) {
    flash('error', Database::friendlyError($e));
    redirect($backTo);
}

flash('success', 'Document deleted.');
redirect($backTo);
