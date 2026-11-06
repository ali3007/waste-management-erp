<?php
/**
 * Vehicles — filtering, geography option lists and input validation.
 *
 * One definition of "which vehicles match these filters" is shared by the
 * vehicle list, the dashboard, the reports and every export, so a screen and
 * its export can never disagree. Area-based access control is applied inside
 * the same SQL.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const TRN_VEHICLE_SORTS = [
    'reg'       => 'v.registration_no',
    'type'      => 'vt.name',
    'status'    => 'vs.name',
    'ownership' => 'ot.name',
    'driver'    => 'dr.name',
    'area'      => 'uc.name',
    'added'     => 'v.created_at',
];

/** Read and sanitise vehicle filters from a request array ($_GET). */
function trn_read_vehicle_filters(array $src): array
{
    $text = static fn(string $k): string => isset($src[$k]) && is_string($src[$k]) ? trim($src[$k]) : '';
    $int = static fn(string $k): int => max(0, (int)$text($k));

    $sort = $text('sort');
    $dir = strtolower($text('dir')) === 'desc' ? 'desc' : 'asc';

    return [
        'q'         => Security::cleanText($text('q'), 120),
        'type'      => $int('type'),
        'status'    => $int('status'),
        'ownership' => $int('ownership'),
        'category'  => isset(trn_status_categories()[$text('category')]) ? $text('category') : '',
        'driver'    => $int('driver'),
        'region'    => $int('region'),
        'district'  => $int('district'),
        'tehsil'    => $int('tehsil'),
        'uc'        => $int('uc'),
        'beat'      => $int('beat'),
        'deployed'  => in_array($text('deployed'), ['yes', 'no'], true) ? $text('deployed') : '',
        'has_driver' => in_array($text('has_driver'), ['yes', 'no'], true) ? $text('has_driver') : '',
        'archived'  => in_array($text('archived'), ['include', 'only'], true) ? $text('archived') : '',
        'sort'      => isset(TRN_VEHICLE_SORTS[$sort]) ? $sort : 'reg',
        'dir'       => $dir,
    ];
}

/** Only the filters that are actually set (sorting is not a filter). */
function trn_active_vehicle_filters(array $f): array
{
    return array_filter(
        array_diff_key($f, ['sort' => 1, 'dir' => 1]),
        static fn($v) => $v !== '' && $v !== 0
    );
}

/**
 * WHERE clause + parameters for a filter set, over trn_vehicle_from().
 *
 * @return array{0:string,1:array}
 */
function trn_vehicle_filter_sql(array $f): array
{
    $where = ['1=1'];
    $params = [];

    if ($f['archived'] === 'only') {
        $where[] = 'v.deleted_at IS NOT NULL';
    } elseif ($f['archived'] !== 'include') {
        $where[] = 'v.deleted_at IS NULL';
    }

    if ($f['q'] !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $f['q']) . '%';
        $key = trn_norm_key($f['q']);
        $or = [
            'v.registration_no LIKE :fq1', 'v.tracker_no LIKE :fq2', 'v.rfid_no LIKE :fq3', 'v.chassis_no LIKE :fq4',
            'v.engine_no LIKE :fq5', 'v.make LIKE :fq6', 'v.model LIKE :fq7', 'dr.name LIKE :fq8', 'vt.name LIKE :fq9',
        ];
        foreach (range(1, 9) as $i) {
            $params['fq' . $i] = $like;
        }
        if ($key !== '') {
            $or[] = 'v.registration_key LIKE :fqk';
            $params['fqk'] = '%' . $key . '%';
        }
        $digits = Security::normalizeCnic($f['q']);
        if ($digits !== '') {
            $or[] = 'dr.cnic LIKE :fqc';
            $params['fqc'] = '%' . $digits . '%';
        }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }

    $eq = [
        'type' => 'v.vehicle_type_id', 'status' => 'v.status_id', 'ownership' => 'v.ownership_type_id',
        'driver' => 'da.employee_id', 'region' => 'dp.region_id', 'district' => 'dp.district_id',
        'tehsil' => 'dp.tehsil_id', 'uc' => 'dp.uc_id', 'beat' => 'dp.beat_id',
    ];
    foreach ($eq as $key => $column) {
        if ($f[$key] > 0) {
            $where[] = $column . ' = :f_' . $key;
            $params['f_' . $key] = $f[$key];
        }
    }

    if ($f['category'] !== '') {
        $where[] = 'vs.category = :f_category';
        $params['f_category'] = $f['category'];
    }

    if ($f['deployed'] === 'yes') {
        $where[] = 'dp.id IS NOT NULL';
    } elseif ($f['deployed'] === 'no') {
        $where[] = 'dp.id IS NULL';
    }
    if ($f['has_driver'] === 'yes') {
        $where[] = 'da.id IS NOT NULL';
    } elseif ($f['has_driver'] === 'no') {
        $where[] = 'da.id IS NULL';
    }

    $scope = trn_scope_sql('v');
    if ($scope !== '') {
        $where[] = $scope;
    }

    return [implode(' AND ', $where), $params];
}

function trn_vehicle_order_sql(array $f): string
{
    $col = TRN_VEHICLE_SORTS[$f['sort']] ?? 'v.registration_no';
    return $col . ' ' . ($f['dir'] === 'desc' ? 'DESC' : 'ASC') . ', v.id ASC';
}

/** Query string for the current filters plus overrides (page, sort …). */
function trn_vehicle_filter_query(array $f, array $extra = []): string
{
    $params = trn_active_vehicle_filters($f);
    if ($f['sort'] !== 'reg' || $f['dir'] !== 'asc') {
        $params['sort'] = $f['sort'];
        $params['dir'] = $f['dir'];
    }
    return trn_qs(array_merge($params, $extra));
}

/**
 * Option lists for the filter panel's dependent geography dropdowns. Children
 * are loaded only for a selected parent, so a country-wide hierarchy never
 * ships to the browser in one go. Restricted users see only their own UCs.
 *
 * @return array{regions:array,districts:array,tehsils:array,ucs:array,beats:array}
 */
function trn_geo_options(array $f): array
{
    $regions = Database::fetchAll("SELECT id, name FROM regions WHERE status = 'active' ORDER BY name ASC");
    $districts = $f['region'] > 0
        ? Database::fetchAll("SELECT id, name FROM districts WHERE region_id = :p AND status = 'active' ORDER BY name ASC", ['p' => $f['region']])
        : [];
    $tehsils = $f['district'] > 0
        ? Database::fetchAll("SELECT id, name FROM tehsils WHERE district_id = :p AND status = 'active' ORDER BY name ASC", ['p' => $f['district']])
        : [];

    $ucSql = "SELECT id, name FROM ucs WHERE tehsil_id = :p AND status = 'active'";
    $ids = hr_accessible_uc_ids();
    if ($ids !== null) {
        $ucSql .= $ids ? ' AND id IN (' . implode(',', array_map('intval', $ids)) . ')' : ' AND 1 = 0';
    }
    $ucs = $f['tehsil'] > 0 ? Database::fetchAll($ucSql . ' ORDER BY name ASC', ['p' => $f['tehsil']]) : [];
    $beats = $f['uc'] > 0
        ? Database::fetchAll("SELECT id, name FROM beats WHERE uc_id = :p AND status = 'active' ORDER BY name ASC", ['p' => $f['uc']])
        : [];

    return ['regions' => $regions, 'districts' => $districts, 'tehsils' => $tehsils, 'ucs' => $ucs, 'beats' => $beats];
}

/** Drivers who currently hold, or ever held, a vehicle — for the "Driver" filter. */
function trn_driver_filter_options(): array
{
    return Database::fetchAll(
        'SELECT DISTINCT e.id, e.name, e.employee_code
           FROM vehicle_driver_assignments a JOIN employees e ON e.id = a.employee_id
          ORDER BY e.name ASC LIMIT 500'
    );
}

/* ------------------------------------------------------------------ *
 | Input validation (Add / Edit vehicle)                               |
 * ------------------------------------------------------------------ */

/**
 * Validate the vehicle form. Works for create ($existing = null) and update.
 * Dropdown ids are checked against the database — a value is accepted only if
 * it is active, or is the one the vehicle already has (so an edit never fails
 * just because a type was deactivated later).
 *
 * @return array{errors:array<string,string>, data:array}
 */
function trn_validate_vehicle(array $in, ?array $existing, bool $canChangeIdentifiers): array
{
    $errors = [];
    $selfId = $existing !== null ? (int)$existing['id'] : 0;
    $str = static fn(string $k, int $max): string => Security::cleanText((string)($in[$k] ?? ''), $max);

    /* Registration number */
    $reg = trn_clean_regno((string)($in['registration_no'] ?? ''));
    $regKey = trn_norm_key($reg);
    if ($reg === '' || mb_strlen($regKey) < 2) {
        $errors['registration_no'] = 'Enter the vehicle / registration number.';
    } else {
        $dup = Database::fetch(
            'SELECT id, registration_no FROM vehicles WHERE registration_key = :k AND id <> :id',
            ['k' => $regKey, 'id' => $selfId]
        );
        if ($dup !== null) {
            $errors['registration_no'] = 'Vehicle number ' . $dup['registration_no'] . ' is already registered.';
        }
    }

    /* Lookups */
    $lookup = static function (string $kind, string $field, string $label, ?int $current) use (&$errors, $in): int {
        $id = (int)($in[$field] ?? 0);
        if ($id <= 0) {
            $errors[$field] = 'Choose a ' . $label . '.';
            return 0;
        }
        $reg = trn_config_registry()[$kind];
        $ok = Database::exists(
            "SELECT id FROM `{$reg['table']}` WHERE id = :id AND (status = 'active' OR id = :cur)",
            ['id' => $id, 'cur' => (int)$current]
        );
        if (!$ok) {
            $errors[$field] = 'That ' . $label . ' is not available.';
            return 0;
        }
        return $id;
    };
    $typeId = $lookup('vehicle_type', 'vehicle_type_id', 'vehicle type', $existing['vehicle_type_id'] ?? null);
    $ownerId = $lookup('ownership_type', 'ownership_type_id', 'ownership type', $existing['ownership_type_id'] ?? null);

    /* Status: its own permission on existing vehicles; on a new vehicle the
       configured default is used when nothing was chosen. */
    $statusId = (int)($in['status_id'] ?? 0);
    if ($existing !== null && !can('transport.status')) {
        $statusId = (int)$existing['status_id'];
    } elseif ($statusId <= 0 && $existing === null) {
        $statusId = (int)trn_default_status_id();
    }
    if ($statusId <= 0) {
        $errors['status_id'] = 'Choose a vehicle status.';
    } elseif (!Database::exists(
        "SELECT id FROM vehicle_statuses WHERE id = :id AND (status = 'active' OR id = :cur)",
        ['id' => $statusId, 'cur' => (int)($existing['status_id'] ?? 0)]
    )) {
        $errors['status_id'] = 'That status is not available.';
        $statusId = 0;
    }

    /* Descriptive fields */
    $make = $str('make', 80);
    $model = $str('model', 80);
    $color = $str('color', 40);
    $remarks = Security::cleanMultiline((string)($in['remarks'] ?? ''), 500);

    $yearRaw = trim((string)($in['manufacture_year'] ?? ''));
    $year = null;
    if ($yearRaw !== '') {
        $maxYear = (int)date('Y') + 1;
        if (!preg_match('/^\d{4}$/', $yearRaw) || (int)$yearRaw < 1950 || (int)$yearRaw > $maxYear) {
            $errors['manufacture_year'] = 'Enter a four-digit year between 1950 and ' . $maxYear . '.';
        } else {
            $year = (int)$yearRaw;
        }
    }

    /* Optional identification — chassis / engine must not repeat on another live vehicle */
    $chassis = $str('chassis_no', 60);
    $engine = $str('engine_no', 60);
    foreach (['chassis_no' => [$chassis, 'chassis'], 'engine_no' => [$engine, 'engine']] as $col => [$val, $label]) {
        if ($val !== '' && Database::exists(
            "SELECT id FROM vehicles WHERE {$col} = :v AND id <> :id AND deleted_at IS NULL",
            ['v' => $val, 'id' => $selfId]
        )) {
            $errors[$col] = 'Another vehicle already has this ' . $label . ' number.';
        }
    }

    /* Tracker / RFID — optional, unique when provided. Only people with the
       identifiers permission may change them on an existing vehicle. */
    $tracker = $str('tracker_no', 60);
    $rfid = $str('rfid_no', 60);
    if ($existing !== null && !$canChangeIdentifiers) {
        $tracker = (string)($existing['tracker_no'] ?? '');
        $rfid = (string)($existing['rfid_no'] ?? '');
    }
    foreach (['tracker_no' => [$tracker, 'tracker_key', 'tracker'], 'rfid_no' => [$rfid, 'rfid_key', 'RFID']] as $col => [$val, $keyCol, $label]) {
        if ($val === '') {
            continue;
        }
        $key = trn_norm_key($val);
        if ($key === '') {
            $errors[$col] = 'Enter a valid ' . $label . ' number, or leave it empty.';
            continue;
        }
        $holder = Database::fetch(
            "SELECT id, registration_no FROM vehicles WHERE {$keyCol} = :k AND id <> :id",
            ['k' => $key, 'id' => $selfId]
        );
        if ($holder !== null) {
            $errors[$col] = 'This ' . $label . ' number is already assigned to vehicle ' . $holder['registration_no'] . '.';
        }
    }

    return ['errors' => $errors, 'data' => [
        'registration_no' => $reg, 'registration_key' => $regKey,
        'vehicle_type_id' => $typeId, 'make' => $make !== '' ? $make : null, 'model' => $model !== '' ? $model : null,
        'manufacture_year' => $year, 'color' => $color !== '' ? $color : null,
        'status_id' => $statusId, 'ownership_type_id' => $ownerId,
        'chassis_no' => $chassis !== '' ? $chassis : null, 'engine_no' => $engine !== '' ? $engine : null,
        'tracker_no' => $tracker !== '' ? $tracker : null, 'tracker_key' => $tracker !== '' ? trn_norm_key($tracker) : null,
        'rfid_no' => $rfid !== '' ? $rfid : null, 'rfid_key' => $rfid !== '' ? trn_norm_key($rfid) : null,
        'remarks' => $remarks !== '' ? $remarks : null,
    ]];
}

/** Human labels for audit "old/new" diffs. */
function trn_vehicle_field_labels(): array
{
    return [
        'registration_no' => 'Vehicle number', 'vehicle_type_id' => 'Vehicle type', 'make' => 'Make', 'model' => 'Model',
        'manufacture_year' => 'Year', 'color' => 'Colour', 'status_id' => 'Status', 'ownership_type_id' => 'Ownership',
        'chassis_no' => 'Chassis number', 'engine_no' => 'Engine number', 'tracker_no' => 'Tracker number',
        'rfid_no' => 'RFID number', 'remarks' => 'Remarks',
    ];
}

/** Resolve lookup ids in an audit snapshot to their names, so the trail reads plainly. */
function trn_audit_names(array $snap): array
{
    $maps = ['vehicle_type_id' => 'vehicle_types', 'status_id' => 'vehicle_statuses', 'ownership_type_id' => 'vehicle_ownership_types'];
    foreach ($maps as $field => $table) {
        if (isset($snap[$field]) && $snap[$field] !== null && $snap[$field] !== '') {
            $name = Database::fetchValue("SELECT name FROM `{$table}` WHERE id = :id", ['id' => (int)$snap[$field]]);
            $snap[$field] = $name !== null ? (string)$name : $snap[$field];
        }
    }
    $out = [];
    $labels = trn_vehicle_field_labels();
    foreach ($snap as $k => $v) {
        $out[$labels[$k] ?? $k] = $v;
    }
    return $out;
}
