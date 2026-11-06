<?php
/**
 * Step 1 → 2: receive the CSV, validate every row, stage the result for
 * the preview. Writes NOTHING to the employee table.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('hr.import');
Permission::require('employee.create');

if (!is_post()) {
    redirect('modules/hr/import.php');
}
Security::requireCsrf('modules/hr/import.php');

$file = $_FILES['csv'] ?? null;
if (!is_array($file) || is_array($file['error'] ?? null) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    flash('error', 'Choose a CSV file to upload.');
    redirect('modules/hr/import.php');
}
if ($file['error'] !== UPLOAD_ERR_OK) {
    flash('error', in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        ? 'File size exceeds allowed limit (' . hr_size_label(HR_IMPORT_MAX_BYTES) . ').'
        : 'The file could not be uploaded.');
    redirect('modules/hr/import.php');
}
if ((int)$file['size'] <= 0 || (int)$file['size'] > HR_IMPORT_MAX_BYTES) {
    flash('error', 'File size exceeds allowed limit (' . hr_size_label(HR_IMPORT_MAX_BYTES) . ').');
    redirect('modules/hr/import.php');
}
if (!is_uploaded_file((string)$file['tmp_name'])) {
    flash('error', 'Invalid upload.');
    redirect('modules/hr/import.php');
}

$name = (string)($file['name'] ?? '');
if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
    flash('error', 'Invalid file format — upload a .csv file (in Excel: Save As → CSV UTF-8).');
    redirect('modules/hr/import.php');
}

/* A CSV is text. A real binary (an .xlsx renamed to .csv, an executable…)
   contains NUL bytes and is refused before any parsing. */
$sample = (string)@file_get_contents((string)$file['tmp_name'], false, null, 0, 8192);
if (str_contains($sample, "\0") && !str_starts_with($sample, "\xFF\xFE") && !str_starts_with($sample, "\xFE\xFF")) {
    flash('error', 'That file is not a plain CSV. If it is an Excel workbook, use Save As → CSV UTF-8.');
    redirect('modules/hr/import.php');
}

try {
    $analysis = hr_import_analyse((string)$file['tmp_name']);
    if (!$analysis['ok']) {
        flash('error', $analysis['message']);
        redirect('modules/hr/import.php');
    }
    $token = hr_import_stage($analysis['result'], $name);
} catch (Throwable $e) {
    log_event('hr', 'CSV analysis failed', ['error' => $e->getMessage()]);
    flash('error', 'The file could not be processed. ' . (APP_ENV === 'development' ? $e->getMessage() : 'Check the log for details.'));
    redirect('modules/hr/import.php');
}

redirect('modules/hr/import.php?step=preview&t=' . $token);
