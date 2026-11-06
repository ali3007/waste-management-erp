<?php
/**
 * Archive (soft-delete) or restore a vehicle. Nothing is deleted: history,
 * documents, maintenance and fuel stay, and the vehicle still appears in
 * historical reports. Archiving frees its number, tracker and RFID for reuse.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$id = (int)post('id');
$back = 'modules/transport/vehicle-view.php?id=' . $id;
trn_post_guard('transport.archive', $back);
$vehicle = trn_require_vehicle($id);
$restore = post('action') === 'restore';
$reason = Security::cleanText(post('reason'), 255);

try {
    Database::beginTransaction();
    trn_lock_vehicle($id);
    if (!$restore) {
        if ($vehicle['deleted_at'] !== null) {
            Database::rollBack();
            trn_back($back, 'info', 'This vehicle is already archived.');
        }
        if ($vehicle['driver_id'] !== null || $vehicle['deployment_id'] !== null) {
            Database::rollBack();
            trn_back($back, 'error', 'End the vehicle\'s current driver assignment and deployment before archiving it.');
        }
        Database::execute(
            'UPDATE vehicles SET deleted_at = NOW(), deleted_by = :by, delete_reason = :r, registration_key = NULL, tracker_key = NULL, rfid_key = NULL, updated_by = :by2 WHERE id = :id',
            ['by' => Auth::id(), 'r' => $reason !== '' ? $reason : null, 'by2' => Auth::id(), 'id' => $id]
        );
        trn_audit('vehicle.archived', 'vehicle', $id, 'Vehicle ' . $vehicle['registration_no'] . ' archived', null, null, $reason, $id);
        $msg = 'Vehicle archived. Its history is kept and it still appears in historical reports.';
    } else {
        if ($vehicle['deleted_at'] === null) {
            Database::rollBack();
            trn_back($back, 'info', 'This vehicle is not archived.');
        }
        $keys = ['registration_key' => trn_norm_key((string)$vehicle['registration_no']),
            'tracker_key' => $vehicle['tracker_no'] ? trn_norm_key((string)$vehicle['tracker_no']) : null,
            'rfid_key' => $vehicle['rfid_no'] ? trn_norm_key((string)$vehicle['rfid_no']) : null];
        foreach ($keys as $col => $key) {
            if ($key !== null && $key !== '' && Database::exists("SELECT id FROM vehicles WHERE {$col} = :k AND id <> :id", ['k' => $key, 'id' => $id])) {
                Database::rollBack();
                trn_back($back, 'error', 'Cannot restore: its ' . str_replace('_key', '', $col) . ' number is now used by another vehicle.');
            }
        }
        Database::execute(
            'UPDATE vehicles SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL, registration_key = :r, tracker_key = :t, rfid_key = :f, updated_by = :by WHERE id = :id',
            ['r' => $keys['registration_key'], 't' => $keys['tracker_key'], 'f' => $keys['rfid_key'], 'by' => Auth::id(), 'id' => $id]
        );
        trn_audit('vehicle.restored', 'vehicle', $id, 'Vehicle ' . $vehicle['registration_no'] . ' restored', null, null, $reason, $id);
        $msg = 'Vehicle restored.';
    }
    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    trn_back($back, 'error', trn_is_duplicate_key($e) ? 'Cannot restore: its number, tracker or RFID is used by another vehicle.' : Database::friendlyError($e));
}
trn_back($back, 'success', $msg);
