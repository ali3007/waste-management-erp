<?php
/** Change a vehicle's status (its own permission), with logical-validity checks. */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$id = (int)post('id');
$back = 'modules/transport/vehicle-view.php?id=' . $id;
trn_post_guard('transport.status', $back);
$vehicle = trn_require_vehicle($id);

$statusId = (int)post('status_id');
$reason = Security::cleanText(post('reason'), 255);
$target = $statusId > 0 ? Database::fetch("SELECT * FROM vehicle_statuses WHERE id = :id AND status = 'active'", ['id' => $statusId]) : null;

if ($vehicle['deleted_at'] !== null) {
    trn_back($back, 'error', 'An archived vehicle\'s status cannot be changed. Restore it first.');
}
if ($target === null) {
    trn_back($back, 'error', 'Choose an available status.');
}
if ((int)$vehicle['status_id'] === $statusId) {
    trn_back($back, 'info', 'The vehicle already has that status.');
}
if ($target['category'] !== 'active' && ($vehicle['driver_id'] !== null || $vehicle['deployment_id'] !== null)) {
    trn_back($back, 'error', 'End this vehicle\'s current driver assignment and deployment before setting "' . $target['name'] . '".');
}

try {
    Database::beginTransaction();
    Database::update('vehicles', ['status_id' => $statusId, 'updated_by' => Auth::id()], ['id' => $id]);
    trn_audit('vehicle.status_changed', 'vehicle', $id, 'Status of ' . $vehicle['registration_no'] . ' changed from ' . $vehicle['status_name'] . ' to ' . $target['name'],
        ['Status' => $vehicle['status_name']], ['Status' => $target['name']], $reason, $id);
    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    trn_back($back, 'error', Database::friendlyError($e));
}
trn_back($back, 'success', 'Status changed to ' . $target['name'] . '.');
