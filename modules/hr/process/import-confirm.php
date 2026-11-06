<?php
/**
 * Step 4 → 5: the user confirmed the preview. Import ONLY the rows that
 * passed validation.
 *
 * Everything runs in one transaction. Duplicates are re-checked at the
 * moment of insert (someone may have added the same CNIC since the
 * preview), and the database's UNIQUE(cnic) is the final guard — a row
 * that loses that race is counted as skipped, never duplicated.
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

$token = post('token');
Security::requireCsrf('modules/hr/import.php?step=preview&t=' . $token);

$staged = hr_import_load($token);
if ($staged === null) {
    flash('error', 'That import preview has expired or was already used. Upload the file again.');
    redirect('modules/hr/import.php');
}

$valid = $staged['result']['valid'] ?? [];
if (empty($valid)) {
    flash('error', 'There are no valid rows to import.');
    redirect('modules/hr/import.php?step=preview&t=' . $token);
}

@set_time_limit(300);

$imported = 0;
$raceSkipped = [];
$firstId = null;

try {
    Database::beginTransaction();

    $exists = Database::pdo()->prepare('SELECT 1 FROM employees WHERE cnic = :c');
    foreach ($valid as $row) {
        $exists->execute(['c' => $row['cnic']]);
        if ($exists->fetchColumn() !== false) {
            $raceSkipped[] = $row['row'];
            continue;
        }
        try {
            $id = Database::insert('employees', [
                'name' => $row['name'], 'father_name' => $row['father_name'], 'cnic' => $row['cnic'],
                'dob' => $row['dob'], 'cnic_issue_date' => $row['cnic_issue_date'],
                'phone' => $row['phone'], 'address' => $row['address'],
                'region_id' => $row['region_id'], 'district_id' => $row['district_id'], 'tehsil_id' => $row['tehsil_id'],
                'staff_pool_id' => $row['staff_pool_id'], 'staff_type_id' => $row['staff_type_id'],
                'created_by' => Auth::id(), 'updated_by' => Auth::id(),
            ]);
            Database::update('employees', ['employee_code' => hr_generate_employee_code($id)], ['id' => $id]);
            $firstId ??= $id;
            $imported++;
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                $raceSkipped[] = $row['row'];
                continue;
            }
            throw $e;
        }
    }

    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    flash('error', 'The import failed and nothing was saved. ' . Database::friendlyError($e));
    redirect('modules/hr/import.php?step=preview&t=' . $token);
}

$skipped = count($staged['result']['skipped'] ?? []) + count($raceSkipped);
$errors = count($staged['result']['errors'] ?? []);

try {
    Database::insert('activity_log', [
        'user_id' => Auth::id(), 'module' => 'hr', 'action' => 'employee.imported',
        'description' => "CSV import \"{$staged['file']}\": {$imported} imported, {$skipped} skipped, {$errors} errors",
        'ip_address' => client_ip(),
    ]);
} catch (Throwable $e) {
    log_event('hr', 'Import log failed', ['error' => $e->getMessage()]);
}

hr_import_discard($token);

$_SESSION['hr_import_done'] = ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors, 'file' => $staged['file'], 'race' => $raceSkipped];
redirect('modules/hr/import.php?step=done');
