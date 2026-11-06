<?php
/**
 * Creates or edits a complaint.
 *
 * Create: validates everything on the server (the browser's checks are a
 * convenience only), warns about likely duplicates WITHOUT blocking, then in
 * ONE transaction generates the unique complaint number, stores the
 * complaint, assigns it to its UC (notifying that UC's Supervisor and
 * Monitoring Officer(s)), starts the SLA clock and writes the audit trail.
 *
 * Edit: changes only the complainant/complaint/location-text fields. The
 * UC/Beat can only change through Reassign, so that is always audited.
 *
 * Works both as a normal form post and as an AJAX call (the create form
 * uses AJAX so it can upload the chosen pictures right afterwards).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

$id = (int)post('id');
$isEdit = $id > 0;
Permission::require($isEdit ? 'complaint.update' : 'complaint.create');

$ajax = is_ajax();
$backTo = $isEdit ? 'modules/complaints/complaint-edit.php?id=' . $id : 'modules/complaints/complaint-create.php';

if (!is_post()) {
    redirect('modules/complaints/index.php');
}
Security::requireCsrf($backTo);

/** One way to fail for both request styles. */
$fail = static function (string $message, array $extra = []) use ($ajax, $backTo): never {
    if ($ajax) {
        json_response(['ok' => false, 'message' => $message] + $extra, 422);
    }
    flash('error', $message);
    redirect($backTo);
};

$existing = null;
if ($isEdit) {
    $existing = cmp_require_access($id, 'complaint.update');
    cmp_require_action($existing, 'edit');
}

$name = Security::cleanText(post('complainant_name'), 150);
$address = Security::cleanText(post('complainant_address'), 255);
$phone = Security::cleanText(post('phone'), 30);
$sourceId = (int)post('source_id');
$typeId = (int)post('type_id');
$priorityId = (int)post('priority_id');
$description = Security::cleanMultiline(post('description'), 4000);
$date = cmp_parse_date(post('complaint_date'));
$timeRaw = post('complaint_time');
$time = $timeRaw !== '' ? cmp_parse_time($timeRaw) : null;
$locationAddress = Security::cleanText(post('location_address'), 255);
$latRaw = post('latitude');
$lngRaw = post('longitude');

$regionId = (int)post('region_id');
$districtId = (int)post('district_id');
$tehsilId = (int)post('tehsil_id');
$ucId = (int)post('uc_id');
$beatId = (int)post('beat_id');

keep_old([
    'complainant_name' => $name, 'complainant_address' => $address, 'phone' => $phone, 'source_id' => (string)$sourceId,
    'type_id' => (string)$typeId, 'priority_id' => (string)$priorityId, 'description' => $description,
    'complaint_date' => post('complaint_date'), 'complaint_time' => $timeRaw, 'region_id' => (string)$regionId,
    'district_id' => (string)$districtId, 'tehsil_id' => (string)$tehsilId, 'uc_id' => (string)$ucId, 'beat_id' => (string)$beatId,
    'location_address' => $locationAddress, 'latitude' => $latRaw, 'longitude' => $lngRaw,
]);

$errors = [];
if ($name === '') { $errors[] = 'Enter the complainant\'s name.'; }
if ($description === '') { $errors[] = 'Enter the complaint description.'; }
if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,30}$/', $phone)) { $errors[] = 'Enter a valid phone number.'; }
if ($date === null) { $errors[] = 'Enter a valid complaint date.'; }
elseif ($date > date('Y-m-d')) { $errors[] = 'The complaint date cannot be in the future.'; }
if ($timeRaw !== '' && $time === null) { $errors[] = 'Enter a valid complaint time.'; }

/* Lookups must exist and be active (a record the complaint already uses may stay). */
$lookupOk = static function (string $table, int $value, ?int $kept): bool {
    return $value > 0 && Database::exists(
        "SELECT id FROM {$table} WHERE id = :id AND (status = 'active' OR id = :kept)",
        ['id' => $value, 'kept' => (int)$kept]
    );
};
if (!$lookupOk('complaint_sources', $sourceId, $existing ? (int)$existing['source_id'] : null)) { $errors[] = 'Choose a valid Complaint Source.'; }
if (!$lookupOk('complaint_types', $typeId, $existing ? (int)$existing['type_id'] : null)) { $errors[] = 'Choose a valid Complaint Type.'; }
if (!$lookupOk('complaint_priorities', $priorityId, $existing ? (int)$existing['priority_id'] : null)) { $errors[] = 'Choose a valid Priority.'; }

/* GPS is optional, but if given must be a real coordinate pair. */
$lat = $lng = null;
if ($latRaw !== '' || $lngRaw !== '') {
    if (!is_numeric($latRaw) || !is_numeric($lngRaw) || (float)$latRaw < -90 || (float)$latRaw > 90 || (float)$lngRaw < -180 || (float)$lngRaw > 180) {
        $errors[] = 'Enter a valid latitude (-90 to 90) and longitude (-180 to 180), or leave both empty.';
    } else {
        $lat = number_format((float)$latRaw, 7, '.', '');
        $lng = number_format((float)$lngRaw, 7, '.', '');
    }
}

$geo = null;
if (!$isEdit) {
    $geo = cmp_validate_geography($regionId, $districtId, $tehsilId, $ucId, $beatId > 0 ? $beatId : null);
    if ($geo === null) {
        $errors[] = 'Invalid administrative hierarchy — choose an active Region, District, Tehsil and UC that belong together (and a Beat of that UC, if any).';
    } elseif (cmp_scope_uc_ids() !== null && !in_array($geo['uc_id'], cmp_scope_uc_ids(), true)) {
        $errors[] = 'Unauthorized action — that UC is outside your area.';
    }
}

if ($errors) {
    $fail($errors[0]);
}

/* ------------------------------------------------------------------ *
 | Edit                                                                |
 * ------------------------------------------------------------------ */
if ($isEdit) {
    $data = [
        'complainant_name' => $name, 'complainant_address' => $address !== '' ? $address : null, 'phone' => $phone !== '' ? $phone : null,
        'source_id' => $sourceId, 'type_id' => $typeId, 'priority_id' => $priorityId, 'description' => $description,
        'complaint_date' => $date, 'complaint_time' => $time, 'location_address' => $locationAddress !== '' ? $locationAddress : null,
        'latitude' => $lat, 'longitude' => $lng, 'updated_at' => cmp_now(), 'updated_by' => Auth::id(),
    ];

    $labels = [
        'complainant_name' => 'Complainant name', 'complainant_address' => 'Complainant address', 'phone' => 'Phone',
        'source_id' => 'Source', 'type_id' => 'Type', 'priority_id' => 'Priority', 'description' => 'Description',
        'complaint_date' => 'Complaint date', 'complaint_time' => 'Complaint time', 'location_address' => 'Location / address',
        'latitude' => 'Latitude', 'longitude' => 'Longitude',
    ];
    $changes = [];
    foreach ($labels as $col => $label) {
        $old = (string)($existing[$col] ?? '');
        $new = (string)($data[$col] ?? '');
        if ($col === 'complaint_time') { $old = substr($old, 0, 5); $new = substr($new, 0, 5); }
        if ($old !== $new) {
            $changes[$col] = $label;
        }
    }

    try {
        Database::beginTransaction();
        Database::update('complaints', $data, ['id' => $id]);

        if ($changes) {
            $detail = [];
            foreach (['source_id' => ['complaint_sources', 'Source'], 'type_id' => ['complaint_types', 'Type'], 'priority_id' => ['complaint_priorities', 'Priority']] as $col => [$table, $lab]) {
                if (isset($changes[$col])) {
                    $oldName = (string)Database::fetchValue("SELECT name FROM {$table} WHERE id = :id", ['id' => (int)$existing[$col]]);
                    $newName = (string)Database::fetchValue("SELECT name FROM {$table} WHERE id = :id", ['id' => (int)$data[$col]]);
                    $detail[] = $lab . ': ' . $oldName . ' → ' . $newName;
                }
            }
            $other = array_diff_key($changes, array_flip(['source_id', 'type_id', 'priority_id']));
            if ($other) {
                $detail[] = 'Also changed: ' . implode(', ', $other);
            }
            cmp_timeline($id, 'updated', 'Complaint information updated', null, null, implode("\n", $detail));
        }
        if (isset($changes['priority_id'])) {
            cmp_apply_sla($id);
        }
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        log_event('complaints', 'Complaint update failed', ['id' => $id, 'error' => $e->getMessage()]);
        $fail(Database::friendlyError($e));
    }

    clear_old();
    if ($ajax) {
        json_response(['ok' => true, 'id' => $id, 'url' => base_url('modules/complaints/complaint-view.php?id=' . $id)]);
    }
    flash('success', $changes ? 'Complaint updated.' : 'No changes were made.');
    redirect('modules/complaints/complaint-view.php?id=' . $id);
}

/* ------------------------------------------------------------------ *
 | Create                                                              |
 * ------------------------------------------------------------------ */
$ack = post('ack_duplicate') === '1';
if (!$ack) {
    $duplicates = cmp_find_duplicates($phone !== '' ? $phone : null, $geo['uc_id'], $typeId, trim($locationAddress . ' ' . $address));
    if ($duplicates) {
        if ($ajax) {
            json_response(['ok' => false, 'duplicate' => true, 'message' => 'A similar complaint already exists.', 'matches' => array_map(static fn($d) => [
                'id' => (int)$d['id'], 'number' => $d['complaint_number'], 'complainant' => $d['complainant_name'], 'type' => $d['type_name'],
                'uc' => $d['uc_name'], 'date' => cmp_fmt_date((string)$d['complaint_date']), 'status' => cmp_status_label((string)$d['status']),
                'url' => base_url('modules/complaints/complaint-view.php?id=' . (int)$d['id']),
            ], $duplicates)], 409);
        }
        $_SESSION['cmp_duplicates'] = $duplicates;
        flash('warning', 'A similar complaint already exists.');
        redirect($backTo);
    }
}

$now = cmp_now();
try {
    Database::beginTransaction();

    $number = cmp_next_number();
    $id = Database::insert('complaints', [
        'complaint_number' => $number, 'complainant_name' => $name, 'complainant_address' => $address !== '' ? $address : null,
        'phone' => $phone !== '' ? $phone : null, 'source_id' => $sourceId, 'type_id' => $typeId, 'priority_id' => $priorityId,
        'description' => $description, 'complaint_date' => $date, 'complaint_time' => $time,
        'region_id' => $geo['region_id'], 'district_id' => $geo['district_id'], 'tehsil_id' => $geo['tehsil_id'],
        'uc_id' => $geo['uc_id'], 'beat_id' => $geo['beat_id'],
        'location_address' => $locationAddress !== '' ? $locationAddress : null, 'latitude' => $lat, 'longitude' => $lng,
        'status' => CMP_ST_NEW, 'created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now,
    ]);
    cmp_timeline($id, 'created', 'Complaint created', null, $number, $ack ? 'Created after reviewing a similar-complaint warning.' : null);

    $created = cmp_load($id, true);
    cmp_assign_to_uc($created, $geo, 'Initial assignment', true);
    cmp_apply_sla($id);

    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    log_event('complaints', 'Complaint create failed', ['error' => $e->getMessage()]);
    $fail(Database::friendlyError($e));
}

clear_old();
$url = base_url('modules/complaints/complaint-view.php?id=' . $id);
if ($ajax) {
    json_response(['ok' => true, 'id' => $id, 'number' => $number, 'url' => $url, 'csrf' => Security::csrfToken()]);
}
flash('success', 'Complaint ' . $number . ' created successfully.');
redirect('modules/complaints/complaint-view.php?id=' . $id);
