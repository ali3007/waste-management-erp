<?php
/**
 * Creates or updates an employee, including their pictures/documents.
 *
 * The administrative area (Region/District/Tehsil) and HR classification
 * (Pool/Type) are each a parent-child chain; every chain is re-validated
 * server-side as a REAL chain (not just "does this id exist somewhere"),
 * and only active records may be newly chosen — the client only submits
 * the deepest ids, so a tampered request could otherwise mix a District
 * from one Region with a Tehsil from another, or pick a deactivated type.
 *
 * Files are validated BEFORE anything is written, so a rejected upload
 * never leaves a half-created employee behind. The files themselves are
 * moved after the database commit (they need the new employee's id).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

$id = (int)post('id');
$isEdit = $id > 0;

Permission::require($isEdit ? 'employee.update' : 'employee.create');

if (!is_post()) {
    redirect('modules/hr/employees.php');
}

$backTo = $isEdit ? 'modules/hr/employee-edit.php?id=' . $id : 'modules/hr/employee-create.php';
Security::requireCsrf($backTo);

$existing = null;
if ($isEdit) {
    $existing = hr_find_employee($id);
    if ($existing === null) {
        flash('error', 'Record not found.');
        redirect('modules/hr/employees.php');
    }
    hr_require_employee_scope($id);
}

$name = Security::cleanText(post('name'), 120);
$fatherName = Security::cleanText(post('father_name'), 120);
$cnic = Security::normalizeCnic(post('cnic'));
$dob = post('dob');
$phone = Security::cleanText(post('phone'), 30);
$cnicIssueDate = post('cnic_issue_date');
$address = Security::cleanMultiline(post('address'), 255);
$regionId = (int)post('region_id');
$districtId = (int)post('district_id');
$tehsilId = (int)post('tehsil_id');
$poolId = (int)post('staff_pool_id');
$typeId = (int)post('staff_type_id');

keep_old([
    'name' => $name, 'father_name' => $fatherName, 'cnic' => post('cnic'), 'dob' => $dob, 'phone' => $phone,
    'cnic_issue_date' => $cnicIssueDate, 'address' => $address, 'region_id' => (string)$regionId,
    'district_id' => (string)$districtId, 'tehsil_id' => (string)$tehsilId,
    'staff_pool_id' => (string)$poolId, 'staff_type_id' => (string)$typeId,
]);

/** A real calendar date in Y-m-d form, not in the future, not absurdly old. */
$validDate = static function (string $value): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return false;
    }
    return $value <= date('Y-m-d') && (int)$m[1] >= 1900;
};

$errors = [];
if ($name === '') { $errors[] = 'Enter the employee\'s name.'; }
if ($fatherName === '') { $errors[] = "Enter the father's name."; }
if (!Security::isCnic($cnic)) { $errors[] = 'Enter a valid 13-digit CNIC.'; }
if ($dob !== '' && !$validDate($dob)) { $errors[] = 'Enter a valid Date of Birth (it cannot be in the future).'; }
if ($cnicIssueDate !== '' && !$validDate($cnicIssueDate)) { $errors[] = 'Enter a valid CNIC Issue Date (it cannot be in the future).'; }
if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,30}$/', $phone)) { $errors[] = 'Enter a valid phone number.'; }

/* Each pair is checked as a real chain. A newly chosen record must be
   active; keeping the value an existing employee already has is allowed
   even if it was deactivated since. */
$activeOrKept = static fn(string $table, int $id, ?int $kept): string =>
    "(status = 'active'" . ($kept ? ' OR id = ' . (int)$kept : '') . ')';

$tehsil = Database::fetch(
    'SELECT * FROM tehsils WHERE id = :id AND district_id = :d AND ' . $activeOrKept('tehsils', $tehsilId, $existing ? (int)$existing['tehsil_id'] : null),
    ['id' => $tehsilId, 'd' => $districtId]
);
$district = $tehsil !== null ? Database::fetch(
    'SELECT * FROM districts WHERE id = :id AND region_id = :r AND ' . $activeOrKept('districts', $districtId, $existing ? (int)$existing['district_id'] : null),
    ['id' => $districtId, 'r' => $regionId]
) : null;
$region = $district !== null ? Database::fetch(
    'SELECT * FROM regions WHERE id = :id AND ' . $activeOrKept('regions', $regionId, $existing ? (int)$existing['region_id'] : null),
    ['id' => $regionId]
) : null;
if ($region === null || $district === null || $tehsil === null) {
    $errors[] = 'Invalid administrative hierarchy — choose an active Region, District and Tehsil that belong together.';
}

$pool = Database::fetch(
    'SELECT * FROM hr_staff_pools WHERE id = :id AND ' . $activeOrKept('hr_staff_pools', $poolId, $existing ? (int)$existing['staff_pool_id'] : null),
    ['id' => $poolId]
);
$type = $pool !== null ? Database::fetch(
    'SELECT * FROM hr_staff_types WHERE id = :id AND staff_pool_id = :p AND ' . $activeOrKept('hr_staff_types', $typeId, $existing ? (int)$existing['staff_type_id'] : null),
    ['id' => $typeId, 'p' => $poolId]
) : null;
if ($pool === null || $type === null) {
    $errors[] = 'Choose an active Staff Pool and a Staff Type that belongs to it.';
}

/* ---- Uploads: validate everything first, store nothing yet ---------- */
$photoFile = hr_has_upload('employee_picture') ? $_FILES['employee_picture'] : null;
$cnicFile = hr_has_upload('cnic_picture') ? $_FILES['cnic_picture'] : null;
$otherFiles = hr_files_list('other_documents');
$wantsUpload = $photoFile !== null || $cnicFile !== null || $otherFiles !== [];

if ($wantsUpload && !can('employee.doc_create')) {
    $errors[] = 'Unauthorized action — you cannot upload employee documents.';
} else {
    if (count($otherFiles) > 20) {
        $errors[] = 'Upload at most 20 other documents at a time.';
    }
    foreach ([['Employee Picture', $photoFile, 'photo'], ['CNIC Picture', $cnicFile, 'cnic']] as [$label, $file, $kind]) {
        if ($file !== null) {
            $check = hr_validate_upload($file, $kind);
            if (!$check['ok']) {
                $errors[] = $label . ': ' . $check['message'];
            }
        }
    }
    foreach ($otherFiles as $file) {
        $check = hr_validate_upload($file, 'doc');
        if (!$check['ok']) {
            $errors[] = 'Document "' . hr_clean_original_name((string)($file['name'] ?? '')) . '": ' . $check['message'];
        }
    }
}

if ($errors) {
    flash('error', $errors[0]);
    redirect($backTo);
}

/* CNIC unique across employees (independent of ERP users — an employee
   and an ERP account are different things). The database also has a
   UNIQUE constraint; this check just gives a friendly message first. */
$duplicateSql = 'SELECT id FROM employees WHERE cnic = :cnic';
$duplicateParams = ['cnic' => $cnic];
if ($isEdit) {
    $duplicateSql .= ' AND id != :id';
    $duplicateParams['id'] = $id;
}
if (Database::exists($duplicateSql, $duplicateParams)) {
    flash('error', 'Duplicate CNIC detected — an employee with that CNIC already exists.');
    redirect($backTo);
}

$data = [
    'name' => $name, 'father_name' => $fatherName, 'cnic' => $cnic,
    'dob' => $dob !== '' ? $dob : null, 'phone' => $phone !== '' ? $phone : null,
    'cnic_issue_date' => $cnicIssueDate !== '' ? $cnicIssueDate : null,
    'address' => $address !== '' ? $address : null,
    'region_id' => $regionId, 'district_id' => $districtId, 'tehsil_id' => $tehsilId,
    'staff_pool_id' => $poolId, 'staff_type_id' => $typeId,
];

$assignmentClosed = false;

try {
    Database::beginTransaction();

    if ($isEdit) {
        $data['updated_by'] = Auth::id();
        Database::update('employees', $data, ['id' => $id]);

        /* A different assignment rule (e.g. Supervisor → Office staff, or
           single → multi) can't safely inherit the old assignment rows, so
           they are closed (history kept) and the employee returns to the
           Unassigned list to be assigned again under the new rule. */
        if ((int)$existing['staff_type_id'] !== $typeId && (string)$existing['assignment_mode'] !== (string)$type['assignment_mode']) {
            $assignmentClosed = Database::execute(
                'UPDATE employee_assignments SET unassigned_at = NOW(), unassigned_by = :by
                  WHERE employee_id = :e AND unassigned_at IS NULL',
                ['by' => Auth::id(), 'e' => $id]
            ) > 0;
        }
    } else {
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();
        $id = Database::insert('employees', $data);
        Database::update('employees', ['employee_code' => hr_generate_employee_code($id)], ['id' => $id]);
    }

    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    $duplicate = $e instanceof PDOException && (string)$e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_employee_cnic');
    flash('error', $duplicate ? 'Duplicate CNIC detected — an employee with that CNIC already exists.' : Database::friendlyError($e));
    redirect($backTo);
}

hr_log($isEdit ? 'employee.updated' : 'employee.created', $id, ($isEdit ? 'Updated employee ' : 'Added employee ') . $name);

/* ---- Files: now that the employee has an id ------------------------- */
$uploadProblems = [];
$stored = 0;

if ($wantsUpload) {
    $current = Database::fetch('SELECT cnic_picture, employee_picture FROM employees WHERE id = :id', ['id' => $id]) ?: [];

    foreach ([['employee_picture', $photoFile, 'photo', 'Employee Picture'], ['cnic_picture', $cnicFile, 'cnic', 'CNIC Picture']] as [$column, $file, $kind, $label]) {
        if ($file === null) {
            continue;
        }
        $result = hr_store_upload($file, $kind, $id);
        if ($result['ok']) {
            Database::update('employees', [$column => $result['file'], 'updated_by' => Auth::id()], ['id' => $id]);
            hr_delete_stored_file($current[$column] ?? null); // replaced file goes away
            $current[$column] = $result['file'];
            $stored++;
            hr_log('employee.document_uploaded', $id, $label . ' uploaded for ' . $name);
        } else {
            $uploadProblems[] = $label . ': ' . $result['message'];
        }
    }

    foreach ($otherFiles as $file) {
        $result = hr_store_upload($file, 'doc', $id);
        if ($result['ok']) {
            Database::insert('employee_documents', [
                'employee_id' => $id, 'file_name' => $result['file'], 'original_name' => $result['original'],
                'title' => mb_substr(pathinfo($result['original'], PATHINFO_FILENAME), 0, 150),
                'mime_type' => $result['mime'], 'file_size' => $result['size'], 'uploaded_by' => Auth::id(),
            ]);
            $stored++;
            hr_log('employee.document_uploaded', $id, 'Document "' . $result['original'] . '" uploaded for ' . $name);
        } else {
            $uploadProblems[] = $result['message'];
        }
    }
}

/* Remove-picture checkboxes (edit form). */
if ($isEdit && can('employee.doc_delete')) {
    $current = Database::fetch('SELECT cnic_picture, employee_picture FROM employees WHERE id = :id', ['id' => $id]) ?: [];
    foreach (['employee_picture' => 'remove_employee_picture', 'cnic_picture' => 'remove_cnic_picture'] as $column => $flag) {
        if (post($flag) === '1' && !empty($current[$column]) && !($column === 'employee_picture' && $photoFile !== null) && !($column === 'cnic_picture' && $cnicFile !== null)) {
            hr_delete_stored_file($current[$column]);
            Database::update('employees', [$column => null, 'updated_by' => Auth::id()], ['id' => $id]);
            hr_log('employee.document_deleted', $id, ($column === 'employee_picture' ? 'Employee picture' : 'CNIC picture') . ' removed for ' . $name);
        }
    }
}

clear_old();

if ($isEdit) {
    $message = 'Employee updated successfully.';
} else {
    $message = 'Employee added successfully. ' . $name . ' is now in the Unassigned Employees list.';
}
if ($assignmentClosed) {
    $message .= ' Their previous assignment was closed because the new Staff Type uses a different assignment rule — employee moved to Unassigned list.';
}
flash('success', $message);
if ($uploadProblems) {
    flash('warning', 'The employee was saved, but some files were not uploaded. ' . implode(' ', $uploadProblems));
}

redirect('modules/hr/employee-view.php?id=' . $id);
