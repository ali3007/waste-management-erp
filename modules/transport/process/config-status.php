<?php
/**
 * Activate / deactivate a configurable list value. Nothing is ever deleted:
 * a value already used by vehicles or records only becomes unavailable for
 * NEW entries, so history keeps its meaning.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$kind = post('kind');
$reg = trn_config_registry()[$kind] ?? null;
$back = trn_return_url($kind === 'vehicle_type' ? 'modules/transport/vehicle-types.php' : 'modules/transport/config.php?tab=' . $kind);
if ($reg === null) {
    trn_back('modules/transport/index.php', 'error', 'Unknown list.');
}
trn_post_guard($reg['permission'], $back);

$id = (int)post('id');
$to = post('status') === 'active' ? 'active' : 'inactive';
$row = Database::fetch("SELECT * FROM `{$reg['table']}` WHERE id = :id", ['id' => $id]);
if ($row === null) {
    trn_back($back, 'error', 'That ' . strtolower($reg['label']) . ' could not be found.');
}
if ($row['status'] === $to) {
    trn_back($back, 'info', 'No change — it is already ' . $to . '.');
}
if ($kind === 'vehicle_status' && $to === 'inactive' && (int)$row['is_default'] === 1) {
    trn_back($back, 'error', 'This is the default status for new vehicles. Make another status the default first.');
}

try {
    Database::beginTransaction();
    Database::update($reg['table'], ['status' => $to, 'updated_by' => Auth::id()], ['id' => $id]);
    trn_audit('config.' . $kind . '_status', $kind, $id, $reg['label'] . ' ' . ($to === 'active' ? 'activated' : 'deactivated') . ': ' . $row['name'],
        ['Status' => $row['status']], ['Status' => $to]);
    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    trn_back($back, 'error', Database::friendlyError($e));
}

$uses = trn_lookup_usage($kind, $id);
trn_back($back, 'success', '"' . $row['name'] . '" ' . ($to === 'active' ? 'activated.' : 'deactivated.')
    . ($to === 'inactive' && $uses > 0 ? ' It stays on the ' . $uses . ' existing record(s) and is hidden from new entries.' : ''));
