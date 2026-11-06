<?php
/** Add or edit a maintenance record (with optional invoice/receipt). */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$id = (int)post('id');
$isEdit = $id > 0;
$back = trn_return_url('modules/transport/maintenance.php');
$formBack = $isEdit ? 'modules/transport/maintenance.php?edit=' . $id : 'modules/transport/maintenance.php?new=1';
trn_post_guard('transport.maintenance_manage', $formBack);

$existing = null;
if ($isEdit) {
    $existing = Database::fetch('SELECT * FROM vehicle_maintenance WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    if ($existing === null) {
        trn_back($back, 'error', 'That maintenance record could not be found.');
    }
    $vehicle = trn_require_vehicle((int)$existing['vehicle_id']);
} else {
    $vehicle = trn_require_vehicle((int)post('vehicle_id'));
    if ($vehicle['deleted_at'] !== null) {
        trn_back_with_old($formBack, 'This vehicle is archived. Restore it before adding records.');
    }
}

$errors = [];
$date = trn_valid_date(post('maintenance_date'));
if ($date === null || $date > trn_today()) {
    $errors[] = 'Enter a valid maintenance date (not in the future).';
}
$typeId = (int)post('maintenance_type_id');
if (!Database::exists("SELECT id FROM maintenance_types WHERE id = :id AND (status = 'active' OR id = :cur)", ['id' => $typeId, 'cur' => (int)($existing['maintenance_type_id'] ?? 0)])) {
    $errors[] = 'Choose a maintenance type.';
}
$desc = Security::cleanMultiline(post('description'), 1000);
if ($desc === '') {
    $errors[] = 'Describe the work performed.';
}
$cost = trn_decimal(post('cost'), 2, 9999999999.0);
if ($cost === null) {
    $errors[] = 'Enter the cost as a number (0 if free).';
}
$odo = post('odometer') !== '' ? trn_decimal(post('odometer'), 1, 99999999.0) : null;
if (post('odometer') !== '' && $odo === null) {
    $errors[] = 'Odometer must be a number.';
}
$next = post('next_maintenance_date') !== '' ? trn_valid_date(post('next_maintenance_date'), '1950-01-01', '2150-12-31') : null;
if (post('next_maintenance_date') !== '' && ($next === null || ($date !== null && $next < $date))) {
    $errors[] = 'The next maintenance date must be a valid date on or after the maintenance date.';
}
$newFile = hr_has_upload('invoice');
if ($newFile) {
    $check = trn_validate_upload($_FILES['invoice'], 'doc');
    if (!$check['ok']) {
        $errors[] = $check['message'];
    }
}
if ($errors) {
    trn_back_with_old($formBack, implode(' ', $errors));
}

$row = [
    'maintenance_date' => $date, 'maintenance_type_id' => $typeId, 'description' => $desc,
    'workshop' => ($w = Security::cleanText(post('workshop'), 150)) !== '' ? $w : null,
    'odometer' => $odo, 'cost' => $cost, 'next_maintenance_date' => $next,
    'remarks' => ($r = Security::cleanMultiline(post('remarks'), 500)) !== '' ? $r : null,
];
$stored = null;
$oldFile = null;

try {
    Database::beginTransaction();
    if ($newFile) {
        $stored = trn_store_upload($_FILES['invoice'], 'receipt', (int)$vehicle['id']);
        if (!$stored['ok']) {
            throw new RuntimeException($stored['message']);
        }
        $row += ['invoice_file' => $stored['file'], 'invoice_original' => $stored['original'], 'invoice_mime' => $stored['mime'], 'invoice_size' => $stored['size']];
    }
    if (!$isEdit) {
        $row += ['vehicle_id' => (int)$vehicle['id'], 'added_by' => Auth::id(), 'updated_by' => Auth::id()];
        $newId = Database::insert('vehicle_maintenance', $row);
        trn_audit('maintenance.added', 'maintenance', $newId, 'Maintenance added for ' . $vehicle['registration_no'] . ' (cost ' . $cost . ')',
            null, ['Date' => $date, 'Cost' => $cost, 'Work' => mb_substr($desc, 0, 120)], null, (int)$vehicle['id']);
        $msg = 'Maintenance record added.';
    } else {
        if (post('remove_invoice') === '1' && !$newFile) {
            $row += ['invoice_file' => null, 'invoice_original' => null, 'invoice_mime' => null, 'invoice_size' => null];
        }
        $oldFile = $existing['invoice_file'];
        Database::update('vehicle_maintenance', $row + ['updated_by' => Auth::id()], ['id' => $id]);
        [$o, $n] = trn_diff($existing, $row, ['maintenance_date', 'maintenance_type_id', 'description', 'workshop', 'odometer', 'cost', 'next_maintenance_date', 'remarks']);
        if (array_key_exists('invoice_file', $row) && $row['invoice_file'] !== $existing['invoice_file']) {
            $o['Invoice'] = $existing['invoice_original'];
            $n['Invoice'] = $row['invoice_original'];
        }
        trn_audit('maintenance.updated', 'maintenance', $id, 'Maintenance record #' . $id . ' updated for ' . $vehicle['registration_no'], $o ?: null, $n ?: null, null, (int)$vehicle['id']);
        $msg = 'Maintenance record updated.';
    }
    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    if ($stored !== null && !empty($stored['file'])) {
        trn_delete_file($stored['file']);
    }
    trn_back_with_old($formBack, $e instanceof RuntimeException ? $e->getMessage() : Database::friendlyError($e));
}
if ($oldFile && array_key_exists('invoice_file', $row) && $row['invoice_file'] !== $oldFile) {
    trn_delete_file($oldFile);
}
clear_old();
trn_back($back, 'success', $msg);
