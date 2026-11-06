<?php
/**
 * Add or edit a fuel record. Total cost is ALWAYS recalculated here
 * (quantity × rate) — the browser's figure is never trusted. An odometer
 * reading must fit between the vehicle's neighbouring readings, so mileage
 * figures are never built on impossible data.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$id = (int)post('id');
$isEdit = $id > 0;
$back = trn_return_url('modules/transport/fuel.php');
$formBack = $isEdit ? 'modules/transport/fuel.php?edit=' . $id : 'modules/transport/fuel.php?new=1';
trn_post_guard('transport.fuel_manage', $formBack);

$existing = null;
if ($isEdit) {
    $existing = Database::fetch('SELECT * FROM vehicle_fuel_records WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    if ($existing === null) {
        trn_back($back, 'error', 'That fuel record could not be found.');
    }
    $vehicle = trn_require_vehicle((int)$existing['vehicle_id']);
} else {
    $vehicle = trn_require_vehicle((int)post('vehicle_id'));
    if ($vehicle['deleted_at'] !== null) {
        trn_back_with_old($formBack, 'This vehicle is archived. Restore it before adding records.');
    }
}
$vehicleId = (int)$vehicle['id'];

$errors = [];
$date = trn_valid_date(post('fuel_date'));
if ($date === null || $date > trn_today()) {
    $errors[] = 'Enter a valid fuel date (not in the future).';
}
$typeId = (int)post('fuel_type_id');
if (!Database::exists("SELECT id FROM fuel_types WHERE id = :id AND (status = 'active' OR id = :cur)", ['id' => $typeId, 'cur' => (int)($existing['fuel_type_id'] ?? 0)])) {
    $errors[] = 'Choose a fuel type.';
}
$qty = trn_decimal(post('quantity'), 2, 99999999.0);
$rate = trn_decimal(post('rate'), 2, 99999999.0);
if ($qty === null || (float)$qty <= 0) {
    $errors[] = 'Enter the quantity (more than zero).';
}
if ($rate === null || (float)$rate <= 0) {
    $errors[] = 'Enter the rate per litre (more than zero).';
}
$total = ($qty !== null && $rate !== null) ? number_format(round((float)$qty * (float)$rate, 2), 2, '.', '') : null;
if ($total !== null && (float)$total > 9999999999.0) {
    $errors[] = 'The total cost is too large.';
}

$odo = post('odometer') !== '' ? trn_decimal(post('odometer'), 1, 99999999.0) : null;
if (post('odometer') !== '' && $odo === null) {
    $errors[] = 'Odometer must be a number.';
}
if ($odo !== null && $date !== null) {
    $prev = Database::fetch(
        'SELECT odometer, fuel_date FROM vehicle_fuel_records WHERE vehicle_id = :v AND deleted_at IS NULL AND odometer IS NOT NULL AND id <> :self
            AND (fuel_date < :d OR (fuel_date = :d2 AND id < :self2)) ORDER BY fuel_date DESC, id DESC LIMIT 1',
        ['v' => $vehicleId, 'self' => $id, 'd' => $date, 'd2' => $date, 'self2' => $id > 0 ? $id : PHP_INT_MAX]
    );
    $next = Database::fetch(
        'SELECT odometer, fuel_date FROM vehicle_fuel_records WHERE vehicle_id = :v AND deleted_at IS NULL AND odometer IS NOT NULL AND id <> :self
            AND (fuel_date > :d OR (fuel_date = :d2 AND id > :self2)) ORDER BY fuel_date ASC, id ASC LIMIT 1',
        ['v' => $vehicleId, 'self' => $id, 'd' => $date, 'd2' => $date, 'self2' => $id > 0 ? $id : 0]
    );
    if ($prev !== null && (float)$odo < (float)$prev['odometer']) {
        $errors[] = 'Odometer ' . $odo . ' is lower than the earlier reading of ' . $prev['odometer'] . ' (' . trn_date((string)$prev['fuel_date']) . ').';
    }
    if ($next !== null && (float)$odo > (float)$next['odometer']) {
        $errors[] = 'Odometer ' . $odo . ' is higher than the later reading of ' . $next['odometer'] . ' (' . trn_date((string)$next['fuel_date']) . ').';
    }
}

/* Driver: the one posted, or the vehicle's driver on that date. */
$driverId = (int)post('driver_id');
if ($driverId > 0) {
    $okDriver = trn_find_eligible_driver($driverId) !== null
        || Database::exists('SELECT id FROM vehicle_driver_assignments WHERE vehicle_id = :v AND employee_id = :e', ['v' => $vehicleId, 'e' => $driverId])
        || ($existing !== null && (int)$existing['driver_id'] === $driverId);
    if (!$okDriver) {
        $errors[] = 'That person is not an eligible driver.';
    }
} elseif ($date !== null) {
    $d = Database::fetchValue(
        'SELECT employee_id FROM vehicle_driver_assignments WHERE vehicle_id = :v AND assigned_date <= :d AND (end_date IS NULL OR end_date > :d2) ORDER BY assigned_date DESC LIMIT 1',
        ['v' => $vehicleId, 'd' => $date, 'd2' => $date]
    );
    $driverId = $d !== null ? (int)$d : 0;
}

$newFile = hr_has_upload('receipt');
if ($newFile) {
    $check = trn_validate_upload($_FILES['receipt'], 'receipt');
    if (!$check['ok']) {
        $errors[] = $check['message'];
    }
}
if ($errors) {
    trn_back_with_old($formBack, implode(' ', $errors));
}

$row = [
    'fuel_date' => $date, 'fuel_type_id' => $typeId, 'quantity' => $qty, 'rate' => $rate, 'total_cost' => $total,
    'odometer' => $odo, 'is_full_tank' => post('is_full_tank') === '1' ? 1 : 0,
    'driver_id' => $driverId > 0 ? $driverId : null,
    'station' => ($s = Security::cleanText(post('station'), 150)) !== '' ? $s : null,
    'remarks' => ($r = Security::cleanMultiline(post('remarks'), 500)) !== '' ? $r : null,
];
$stored = null;
$oldFile = null;

try {
    Database::beginTransaction();
    if ($newFile) {
        $stored = trn_store_upload($_FILES['receipt'], 'receipt', $vehicleId);
        if (!$stored['ok']) {
            throw new RuntimeException($stored['message']);
        }
        $row += ['receipt_file' => $stored['file'], 'receipt_original' => $stored['original'], 'receipt_mime' => $stored['mime'], 'receipt_size' => $stored['size']];
    }
    if (!$isEdit) {
        $row += ['vehicle_id' => $vehicleId, 'added_by' => Auth::id(), 'updated_by' => Auth::id()];
        $newId = Database::insert('vehicle_fuel_records', $row);
        trn_audit('fuel.added', 'fuel', $newId, 'Fuel added for ' . $vehicle['registration_no'] . ': ' . $qty . ' @ ' . $rate . ' = ' . $total,
            null, ['Date' => $date, 'Quantity' => $qty, 'Rate' => $rate, 'Total' => $total], null, $vehicleId);
        $msg = 'Fuel record added. Total cost: ' . trn_money($total) . '.';
    } else {
        if (post('remove_receipt') === '1' && !$newFile) {
            $row += ['receipt_file' => null, 'receipt_original' => null, 'receipt_mime' => null, 'receipt_size' => null];
        }
        $oldFile = $existing['receipt_file'];
        Database::update('vehicle_fuel_records', $row + ['updated_by' => Auth::id()], ['id' => $id]);
        [$o, $n] = trn_diff($existing, $row, ['fuel_date', 'fuel_type_id', 'quantity', 'rate', 'total_cost', 'odometer', 'is_full_tank', 'driver_id', 'station', 'remarks']);
        trn_audit('fuel.updated', 'fuel', $id, 'Fuel record #' . $id . ' updated for ' . $vehicle['registration_no'], $o ?: null, $n ?: null, null, $vehicleId);
        $msg = 'Fuel record updated. Total cost: ' . trn_money($total) . '.';
    }
    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    if ($stored !== null && !empty($stored['file'])) {
        trn_delete_file($stored['file']);
    }
    trn_back_with_old($formBack, $e instanceof RuntimeException ? $e->getMessage() : Database::friendlyError($e));
}
if ($oldFile && array_key_exists('receipt_file', $row) && $row['receipt_file'] !== $oldFile) {
    trn_delete_file($oldFile);
}
clear_old();
trn_back($back, 'success', $msg);
