<?php
/** Soft-delete a fuel record (kept in the database and the audit trail). */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = trn_return_url('modules/transport/fuel.php');
trn_post_guard('transport.fuel_manage', $back);

$rec = Database::fetch('SELECT * FROM vehicle_fuel_records WHERE id = :id AND deleted_at IS NULL', ['id' => (int)post('id')]);
if ($rec === null) {
    trn_back($back, 'error', 'That fuel record could not be found.');
}
$vehicle = trn_require_vehicle((int)$rec['vehicle_id']);
$reason = Security::cleanText(post('reason'), 255);

try {
    Database::beginTransaction();
    Database::execute('UPDATE vehicle_fuel_records SET deleted_at = NOW(), deleted_by = :by WHERE id = :id', ['by' => Auth::id(), 'id' => (int)$rec['id']]);
    trn_audit('fuel.deleted', 'fuel', (int)$rec['id'], 'Fuel record #' . $rec['id'] . ' deleted for ' . $vehicle['registration_no'],
        ['Date' => $rec['fuel_date'], 'Total' => $rec['total_cost']], null, $reason, (int)$vehicle['id']);
    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    trn_back($back, 'error', Database::friendlyError($e));
}
trn_back($back, 'success', 'Fuel record deleted. It is kept in the audit trail.');
