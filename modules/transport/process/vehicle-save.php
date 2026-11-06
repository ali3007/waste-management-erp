<?php
/**
 * Create or edit a vehicle.
 *
 * Everything is validated here, server-side, whatever the form did:
 * permission, CSRF, lookups against the database, duplicate registration /
 * tracker / RFID, geography chain, area scope, status rules and the picture.
 * Changes are written together with their audit rows in one transaction.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$id = (int)post('id');
$isEdit = $id > 0;
$back = $isEdit ? 'modules/transport/vehicle-form.php?id=' . $id : 'modules/transport/vehicle-form.php';
trn_post_guard($isEdit ? 'transport.update' : 'transport.create', $back);

$existing = null;
if ($isEdit) {
    $existing = trn_require_vehicle($id);
    if ($existing['deleted_at'] !== null) {
        trn_back('modules/transport/vehicle-view.php?id=' . $id, 'error', 'An archived vehicle cannot be edited. Restore it first.');
    }
}

$canIdent = !$isEdit || can('transport.identifiers');
$v = trn_validate_vehicle($_POST, $existing, $canIdent);
$errors = $v['errors'];
$data = $v['data'];
$reason = Security::cleanText(post('change_reason'), 255);

/* Initial geography (create only — later changes go through Deployment). */
$geo = null;
$geoDate = trn_today();
if (!$isEdit) {
    [$r, $d, $t, $u, $b] = trn_post_geography();
    if ($r || $d || $t || $u) {
        $geo = trn_resolve_geography($r, $d, $t, $u, $b);
        if ($geo === null) {
            $errors['uc_id'] = 'Invalid administrative hierarchy — choose a valid Region, District, Tehsil and UC (Beat is optional).';
        } elseif (!hr_can_access_uc($geo['uc_id'])) {
            $errors['uc_id'] = 'Unauthorized action — that UC is outside your area.';
        }
        $dt = trn_valid_date(post('deploy_date') ?: trn_today());
        if ($dt === null || $dt > trn_today()) {
            $errors['deploy_date'] = 'Enter a valid deployment start date (not in the future).';
        } else {
            $geoDate = $dt;
        }
    } elseif (trn_is_scoped()) {
        $errors['uc_id'] = 'Choose the UC this vehicle will work in — it must be inside your area.';
    }
}

/* Status rules */
if (!$errors && $data['status_id'] > 0) {
    $cat = (string)trn_status_category($data['status_id']);
    if ($cat !== 'active') {
        if ($geo !== null) {
            $errors['status_id'] = 'A vehicle that is not in service cannot be deployed. Pick an in-service status, or leave Operational Information empty.';
        }
        if ($isEdit && ($existing['driver_id'] !== null || $existing['deployment_id'] !== null) && (int)$data['status_id'] !== (int)$existing['status_id']) {
            $errors['status_id'] = 'End this vehicle\'s current driver assignment and deployment before moving it to a status that is not in service.';
        }
    }
}

/* Picture: validate before touching the database. */
$newPhoto = hr_has_upload('picture');
if ($newPhoto) {
    $check = trn_validate_upload($_FILES['picture'], 'photo');
    if (!$check['ok']) {
        $errors['picture'] = $check['message'];
    }
}

if ($errors) {
    trn_back_with_old($back, 'Please correct: ' . implode(' ', array_values($errors)));
}

$fields = ['registration_no', 'vehicle_type_id', 'make', 'model', 'manufacture_year', 'color', 'status_id', 'ownership_type_id',
    'chassis_no', 'engine_no', 'tracker_no', 'rfid_no', 'remarks'];
$storedPhoto = null;
$oldPhoto = null;

try {
    Database::beginTransaction();

    if (!$isEdit) {
        $row = $data + ['created_by' => Auth::id(), 'updated_by' => Auth::id()];
        $id = Database::insert('vehicles', $row);

        foreach (['tracker' => 'tracker_no', 'rfid' => 'rfid_no'] as $kind => $col) {
            if ($data[$col] !== null) {
                Database::insert('vehicle_identifier_history', ['vehicle_id' => $id, 'kind' => $kind, 'action' => 'added',
                    'new_value' => $data[$col], 'changed_by' => Auth::id()]);
            }
        }
        if ($newPhoto) {
            $stored = trn_store_upload($_FILES['picture'], 'photo', $id);
            if (!$stored['ok']) {
                throw new RuntimeException($stored['message']);
            }
            $storedPhoto = $stored['file'];
            Database::update('vehicles', ['picture_file' => $storedPhoto], ['id' => $id]);
        }
        trn_audit('vehicle.created', 'vehicle', $id, 'Vehicle ' . $data['registration_no'] . ' created',
            null, trn_audit_names(array_intersect_key($data, array_flip($fields))), null, $id);

        if ($geo !== null) {
            $fresh = trn_find_vehicle($id);
            $res = trn_deploy_vehicle($fresh, $geo, $geoDate, 'Initial deployment when the vehicle was added', 'assign', true);
            if (!$res['ok']) {
                throw new RuntimeException($res['message']);
            }
        }
        $msg = 'Vehicle ' . $data['registration_no'] . ' added.';
    } else {
        $oldPhoto = (string)($existing['picture_file'] ?? '');
        $update = $data + ['updated_by' => Auth::id()];
        unset($update['registration_key']);
        $update['registration_key'] = $data['registration_key'];

        if (post('remove_picture') === '1' && !$newPhoto) {
            $update['picture_file'] = null;
        }
        if ($newPhoto) {
            $stored = trn_store_upload($_FILES['picture'], 'photo', $id);
            if (!$stored['ok']) {
                throw new RuntimeException($stored['message']);
            }
            $storedPhoto = $stored['file'];
            $update['picture_file'] = $storedPhoto;
        }
        Database::update('vehicles', $update, ['id' => $id]);

        [$o, $n] = trn_diff($existing, $data, $fields);
        $statusOld = $o['status_id'] ?? null;
        $statusNew = $n['status_id'] ?? null;
        $regOld = $o['registration_no'] ?? null;
        $regNew = $n['registration_no'] ?? null;
        $trkChanged = array_key_exists('tracker_no', $o);
        $rfidChanged = array_key_exists('rfid_no', $o);

        if ($regOld !== null) {
            Database::insert('vehicle_identifier_history', ['vehicle_id' => $id, 'kind' => 'registration', 'action' => 'changed',
                'old_value' => $regOld, 'new_value' => $regNew, 'reason' => $reason ?: null, 'changed_by' => Auth::id()]);
            trn_audit('vehicle.registration_changed', 'vehicle', $id, 'Vehicle number changed from ' . $regOld . ' to ' . $regNew,
                ['Vehicle number' => $regOld], ['Vehicle number' => $regNew], $reason, $id);
        }
        foreach (['tracker' => ['tracker_no', $trkChanged, 'Tracker'], 'rfid' => ['rfid_no', $rfidChanged, 'RFID']] as $kind => [$col, $changed, $label]) {
            if (!$changed) {
                continue;
            }
            $oldV = $o[$col];
            $newV = $n[$col];
            $act = ($oldV === null || $oldV === '') ? 'added' : (($newV === null || $newV === '') ? 'removed' : 'changed');
            Database::insert('vehicle_identifier_history', ['vehicle_id' => $id, 'kind' => $kind, 'action' => $act,
                'old_value' => $oldV, 'new_value' => $newV, 'reason' => $reason ?: null, 'changed_by' => Auth::id()]);
            trn_audit('vehicle.' . $kind . '_changed', 'vehicle', $id, $label . ' number ' . $act . ' on ' . $data['registration_no'],
                [$label => $oldV], [$label => $newV], $reason, $id);
        }
        if ($statusOld !== null) {
            trn_audit('vehicle.status_changed', 'vehicle', $id, 'Status of ' . $data['registration_no'] . ' changed',
                trn_audit_names(['status_id' => $statusOld]), trn_audit_names(['status_id' => $statusNew]), $reason, $id);
        }
        unset($o['status_id'], $n['status_id'], $o['registration_no'], $n['registration_no'], $o['tracker_no'], $n['tracker_no'], $o['rfid_no'], $n['rfid_no']);
        $photoChanged = array_key_exists('picture_file', $update) && (string)$update['picture_file'] !== $oldPhoto;
        if ($photoChanged) {
            $o['picture'] = $oldPhoto !== '' ? 'on file' : 'none';
            $n['picture'] = $update['picture_file'] ? 'replaced' : 'removed';
        }
        if ($o || $n) {
            trn_audit('vehicle.updated', 'vehicle', $id, 'Vehicle ' . $data['registration_no'] . ' updated', trn_audit_names($o), trn_audit_names($n), null, $id);
        }
        $msg = 'Vehicle ' . $data['registration_no'] . ' updated.';
    }

    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    trn_delete_file($storedPhoto);
    if (trn_is_duplicate_key($e)) {
        trn_back_with_old($back, 'That vehicle number, tracker or RFID was just registered by someone else. Please check and try again.');
    }
    $text = $e instanceof RuntimeException ? $e->getMessage() : Database::friendlyError($e);
    trn_back_with_old($back, $text);
}

if ($isEdit && $oldPhoto !== '' && $oldPhoto !== (string)Database::fetchValue('SELECT picture_file FROM vehicles WHERE id = :id', ['id' => $id])) {
    trn_delete_file($oldPhoto);
}

clear_old();
trn_back('modules/transport/vehicle-view.php?id=' . $id, 'success', $msg);
