<?php
/**
 * Gatekeeper for private employee files (photo, CNIC picture, documents).
 *
 * The files live in storage/employee-docs/, which the web server refuses
 * to serve, so this script is the ONLY way to reach them. Every request
 * is checked for: a signed-in user, the matching permission, and the
 * user's area-based scope over that employee — a guessed or edited id in
 * the URL gets a 403/404, never a file.
 *
 *   ?e=<employee id>&k=photo|cnic|doc[&d=<document id>][&dl=1]
 *
 * Viewing needs employee.doc_view (the employee PICTURE only needs
 * employee.view — it is part of the profile header); dl=1 forces a
 * download and needs employee.doc_download.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    http_response_code(503);
    exit('Not installed.');
}

Auth::requireLogin();

$employeeId = (int)query('e');
$kind = query('k');
$docId = (int)query('d');
$download = query('dl') === '1';

$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Record not found.');
};

if ($employeeId <= 0 || !in_array($kind, ['photo', 'cnic', 'doc'], true)) {
    $notFound();
}

$employee = Database::fetch('SELECT id, employee_code, cnic_picture, employee_picture FROM employees WHERE id = :id', ['id' => $employeeId]);
if ($employee === null) {
    $notFound();
}

/* Permission first, then area scope. */
if ($kind === 'photo') {
    Permission::require('employee.view');
} elseif ($download) {
    Permission::require('employee.doc_download');
} else {
    Permission::require('employee.doc_view');
}
hr_require_employee_scope($employeeId);

$storedName = null;
$label = 'document';
$fileSize = null;
if ($kind === 'photo') {
    $storedName = $employee['employee_picture'];
    $label = 'Photo';
} elseif ($kind === 'cnic') {
    $storedName = $employee['cnic_picture'];
    $label = 'CNIC';
} else {
    $doc = $docId > 0
        ? Database::fetch('SELECT * FROM employee_documents WHERE id = :d AND employee_id = :e', ['d' => $docId, 'e' => $employeeId])
        : null;
    if ($doc === null) {
        $notFound();
    }
    $storedName = $doc['file_name'];
    $label = (string)$doc['original_name'];
}

$path = hr_doc_path($storedName);
if ($path === null) {
    $notFound();
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = HR_MIME_BY_EXT[$ext] ?? 'application/octet-stream';
$inlineOk = in_array($ext, ['jpg', 'png', 'gif', 'webp', 'pdf'], true) && !$download;

$downloadName = $kind === 'doc'
    ? hr_clean_original_name($label)
    : ((string)$employee['employee_code'] ?: 'employee') . '-' . $label . '.' . $ext;

if ($download) {
    hr_log('employee.document_downloaded', $employeeId, 'Downloaded ' . ($kind === 'doc' ? '"' . $label . '"' : $label) . ' for ' . ($employee['employee_code'] ?: '#' . $employeeId));
}

/* Release the session lock before streaming so a big file doesn't block
   the person's other tabs. */
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
header('Content-Disposition: ' . ($inlineOk ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', '\\', "\r", "\n"], '_', $downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));

readfile($path);
exit;
