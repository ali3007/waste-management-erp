<?php
/**
 * Create or edit one value in a configurable Transport list (vehicle type,
 * status, ownership type, document type, maintenance type, fuel type).
 * The permission comes from the list's own definition — Vehicle Types need
 * transport.types_manage, the rest transport.config — and is checked
 * server-side before anything is read.
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
$name = Security::cleanText(post('name'), 100);
$description = Security::cleanText(post('description'), 255);
$table = $reg['table'];

$existing = $id > 0 ? Database::fetch("SELECT * FROM `{$table}` WHERE id = :id", ['id' => $id]) : null;
if ($id > 0 && $existing === null) {
    trn_back($back, 'error', 'That ' . strtolower($reg['label']) . ' could not be found.');
}
if ($name === '') {
    trn_back_with_old($back . (str_contains($back, '?') ? '&' : '?') . ($id ? 'edit=' . $id : ''), 'Enter a name for the ' . strtolower($reg['label']) . '.');
}
$editBack = $back . (str_contains($back, '?') ? '&' : '?') . ($id ? 'edit=' . $id : 'new=1');
if (Database::exists("SELECT id FROM `{$table}` WHERE name = :n AND id <> :id", ['n' => $name, 'id' => $id])) {
    trn_back_with_old($editBack, 'A ' . strtolower($reg['label']) . ' named "' . $name . '" already exists.');
}

$data = ['name' => $name, 'description' => $description !== '' ? $description : null, 'updated_by' => Auth::id()];

if ($kind === 'vehicle_status') {
    $cat = post('category');
    if (!isset(trn_status_categories()[$cat])) {
        trn_back_with_old($editBack, 'Choose how this status should behave.');
    }
    $data['category'] = $cat;
    $data['is_default'] = post('is_default') === '1' ? 1 : 0;
    if ($data['is_default'] === 1 && $cat !== 'active') {
        trn_back_with_old($editBack, 'The default status for new vehicles must be an "In service" status.');
    }
}
if ($kind === 'document_type') {
    $data['is_required'] = post('is_required') === '1' ? 1 : 0;
    $data['requires_expiry'] = post('requires_expiry') === '1' ? 1 : 0;
    $wd = post('warning_days');
    if ($wd !== '' && (!ctype_digit($wd) || (int)$wd > 3650)) {
        trn_back_with_old($editBack, 'Warning days must be a whole number between 0 and 3650, or empty to use the default.');
    }
    $data['warning_days'] = $wd !== '' ? (int)$wd : null;
}

try {
    Database::beginTransaction();
    if ($kind === 'vehicle_status' && ($data['is_default'] ?? 0) === 1) {
        Database::execute('UPDATE vehicle_statuses SET is_default = 0 WHERE id <> :id', ['id' => $id]);
    }
    if ($existing === null) {
        $data['created_by'] = Auth::id();
        $newId = Database::insert($table, $data);
        trn_audit('config.' . $kind . '_created', $kind, $newId, $reg['label'] . ' created: ' . $name, null, ['Name' => $name]);
        $msg = $reg['label'] . ' "' . $name . '" added.';
    } else {
        Database::update($table, $data, ['id' => $id]);
        [$o, $n] = trn_diff($existing, $data, array_keys($data));
        unset($o['updated_by'], $n['updated_by']);
        trn_audit('config.' . $kind . '_updated', $kind, $id, $reg['label'] . ' updated: ' . $name, $o ?: null, $n ?: null);
        $msg = $reg['label'] . ' "' . $name . '" updated.';
    }
    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    trn_back_with_old($editBack, Database::friendlyError($e));
}

clear_old();
trn_back($back, 'success', $msg);
