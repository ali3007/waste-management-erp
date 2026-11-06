<?php
/**
 * Transport — shared helpers.
 *
 * Everything configurable is read from the database here (lookup tables and
 * system_settings). The only values in code are SYSTEM LOGIC: the status
 * categories that decide behaviour, and fall-back defaults for settings an
 * administrator has not saved yet.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/* ------------------------------------------------------------------ *
 | Settings                                                            |
 * ------------------------------------------------------------------ */

/** System-logic defaults used only until an administrator saves a value. */
const TRN_SETTING_DEFAULTS = [
    'transport_doc_warning_days' => '30',
    'transport_driver_pool_ids'  => '',
    'transport_driver_type_ids'  => '',
    'transport_driver_multi'     => '0',
    'transport_currency'         => '',
    'transport_distance_unit'    => 'km',
    'transport_volume_unit'      => 'L',
];

function trn_setting(string $key): string
{
    return (string)(setting($key, TRN_SETTING_DEFAULTS[$key] ?? '') ?? (TRN_SETTING_DEFAULTS[$key] ?? ''));
}

function trn_save_setting(string $key, string $value): void
{
    Database::execute(
        "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (:k, :v, 'transport')
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        ['k' => $key, 'v' => $value]
    );
}

function trn_warning_days(): int
{
    return max(0, min(3650, (int)trn_setting('transport_doc_warning_days')));
}

function trn_currency(): string { return trn_setting('transport_currency'); }
function trn_distance_unit(): string { return trn_setting('transport_distance_unit') ?: 'km'; }
function trn_volume_unit(): string { return trn_setting('transport_volume_unit') ?: 'L'; }

/** "1,250.00" with the configured currency label in front, when there is one. */
function trn_money(mixed $v): string
{
    $c = trn_currency();
    return ($c !== '' ? $c . ' ' : '') . number_format((float)$v, 2);
}

function trn_num(mixed $v, int $dec = 0): string
{
    return number_format((float)$v, $dec);
}

/** Today in the application's timezone — one source so SQL and PHP agree. */
function trn_today(): string
{
    return date('Y-m-d');
}

function trn_date(?string $v): string
{
    return $v ? format_datetime($v, 'd M Y') : '—';
}

/** Valid calendar date (YYYY-MM-DD) or null. */
function trn_valid_date(string $v, string $min = '1950-01-01', string $max = '2100-12-31'): ?string
{
    $v = trim($v);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if ($d === false || $d->format('Y-m-d') !== $v) {
        return null;
    }
    return ($v >= $min && $v <= $max) ? $v : null;
}

/** Non-negative decimal as a plain string ("1250.50"), or null. Commas and spaces tolerated. */
function trn_decimal(string $v, int $scale = 2, float $max = 99999999.0): ?string
{
    $v = str_replace([',', ' '], '', trim($v));
    if ($v === '' || !preg_match('/^\d+(\.\d+)?$/', $v)) {
        return null;
    }
    if ((float)$v > $max) {
        return null;
    }
    return number_format((float)$v, $scale, '.', '');
}

/* ------------------------------------------------------------------ *
 | Normalisation                                                       |
 * ------------------------------------------------------------------ */

/** Comparison key: upper-case letters and digits only ("lec-1234" = "LEC 1234" = "LEC1234"). */
function trn_norm_key(string $v): string
{
    return mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', $v) ?? '');
}

/** Display form of a registration number: trimmed, single-spaced, upper-case. */
function trn_clean_regno(string $v): string
{
    return mb_strtoupper(Security::cleanText($v, 40));
}

function trn_clean_code(string $v, int $max = 60): string
{
    return Security::cleanText($v, $max);
}

/* ------------------------------------------------------------------ *
 | Request helpers                                                     |
 * ------------------------------------------------------------------ */

/** Flash a message and go back — the standard exit for a rejected form. */
function trn_back(string $url, string $type, string $message): never
{
    flash($type, $message);
    redirect($url);
}

/** Same, but keeps what the person typed so the form can be refilled. */
function trn_back_with_old(string $url, string $message, array $old = []): never
{
    keep_old($old !== [] ? $old : $_POST);
    flash('error', $message);
    redirect($url);
}

/**
 * Standard preamble for every form handler: installed, POST, permission,
 * CSRF — in that order, so nothing is read or changed before all pass.
 */
function trn_post_guard(string $permission, string $backUrl): void
{
    if (!IS_INSTALLED) {
        redirect('install/index.php');
    }
    if (!is_post()) {
        redirect($backUrl);
    }
    Permission::require($permission);
    Security::requireCsrf($backUrl);
}

/** Safe relative return URL: only paths inside modules/transport are honoured. */
function trn_return_url(string $default): string
{
    $to = post('return_to');
    if ($to !== '' && preg_match('#^modules/transport/[A-Za-z0-9_./?=&%-]*$#', $to) && !str_contains($to, '..')) {
        return $to;
    }
    return $default;
}

function trn_not_found(): never
{
    if (is_ajax()) {
        json_response(['ok' => false, 'message' => 'Record not found.'], 404);
    }
    http_response_code(404);
    $pageTitle = 'Not found';
    $layout = 'auth';
    require INCLUDES_PATH . '/header.php';
    echo '<div class="auth-card auth-result"><div class="auth-result__icon">' . icon('alert') . '</div>'
        . '<h1 class="auth-card__title">Record not found</h1>'
        . '<p class="auth-card__lead">It may have been removed, or the link is wrong.</p>'
        . '<a class="btn btn--primary btn--block" href="' . e(base_url('modules/transport/index.php')) . '" style="margin-top:24px"><span class="btn__label">Back to Transport</span></a></div>';
    require INCLUDES_PATH . '/footer.php';
    exit;
}

/* ------------------------------------------------------------------ *
 | Audit trail (shared activity_log + transport_audit_changes)         |
 * ------------------------------------------------------------------ */

/**
 * Record one audited action. Call it INSIDE the same transaction as the
 * change, so a change can never be saved without its audit row (a failure
 * here throws and the caller's transaction rolls back).
 */
function trn_audit(
    string $action,
    string $subjectType,
    int $subjectId,
    string $description,
    ?array $old = null,
    ?array $new = null,
    ?string $reason = null,
    ?int $vehicleId = null
): void {
    $logId = Database::insert('activity_log', [
        'user_id' => Auth::id() ?: null, 'module' => 'transport', 'action' => $action,
        'subject_type' => $subjectType, 'subject_id' => $subjectId,
        'description' => mb_substr($description, 0, 250), 'ip_address' => client_ip(),
    ]);

    if ($old !== null || $new !== null || ($reason !== null && $reason !== '') || $vehicleId !== null) {
        Database::insert('transport_audit_changes', [
            'activity_log_id' => $logId, 'vehicle_id' => $vehicleId,
            'old_value' => $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'new_value' => $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'reason' => $reason !== null && $reason !== '' ? mb_substr($reason, 0, 255) : null,
        ]);
    }
}

/**
 * Keep only the fields that actually changed.
 *
 * @param string[] $fields
 * @return array{0:array,1:array} [old subset, new subset]
 */
function trn_diff(array $old, array $new, array $fields): array
{
    $o = [];
    $n = [];
    foreach ($fields as $f) {
        $a = $old[$f] ?? null;
        $b = $new[$f] ?? null;
        if ((string)$a !== (string)$b) {
            $o[$f] = $a;
            $n[$f] = $b;
        }
    }
    return [$o, $n];
}

/* ------------------------------------------------------------------ *
 | Configurable lookups                                                |
 * ------------------------------------------------------------------ */

/**
 * The six database-driven lists. This array describes HOW each behaves
 * (table, permission, extra columns, where it is used) — it holds no
 * business values. `usage` powers dependency protection: a value in use is
 * deactivated, never deleted, so history keeps its meaning.
 */
function trn_config_registry(): array
{
    return [
        'vehicle_type' => [
            'table' => 'vehicle_types', 'label' => 'Vehicle Type', 'plural' => 'Vehicle Types',
            'permission' => 'transport.types_manage', 'usage' => [['vehicles', 'vehicle_type_id', 'vehicle']],
            'hint' => 'The kinds of vehicle in your fleet. Appears in the Add / Edit Vehicle form.',
        ],
        'vehicle_status' => [
            'table' => 'vehicle_statuses', 'label' => 'Vehicle Status', 'plural' => 'Vehicle Statuses',
            'permission' => 'transport.config', 'usage' => [['vehicles', 'status_id', 'vehicle']],
            'hint' => 'Name your statuses freely. Each one belongs to a behaviour group that tells the system how to treat it.',
        ],
        'ownership_type' => [
            'table' => 'vehicle_ownership_types', 'label' => 'Ownership Type', 'plural' => 'Ownership Types',
            'permission' => 'transport.config', 'usage' => [['vehicles', 'ownership_type_id', 'vehicle']],
            'hint' => 'How a vehicle is held, for example owned, rented or contracted.',
        ],
        'document_type' => [
            'table' => 'vehicle_document_types', 'label' => 'Document Type', 'plural' => 'Document Types',
            'permission' => 'transport.config', 'usage' => [['vehicle_documents', 'document_type_id', 'document']],
            'hint' => 'Any paper a vehicle must carry. Mark a type "required" to have it reported when missing.',
        ],
        'maintenance_type' => [
            'table' => 'maintenance_types', 'label' => 'Maintenance Type', 'plural' => 'Maintenance Types',
            'permission' => 'transport.config', 'usage' => [['vehicle_maintenance', 'maintenance_type_id', 'record']],
            'hint' => 'Categories for maintenance records.',
        ],
        'fuel_type' => [
            'table' => 'fuel_types', 'label' => 'Fuel Type', 'plural' => 'Fuel Types',
            'permission' => 'transport.config', 'usage' => [['vehicle_fuel_records', 'fuel_type_id', 'record']],
            'hint' => 'The fuels you buy.',
        ],
    ];
}

/** Behaviour groups for vehicle statuses — system logic, deliberately not editable. */
function trn_status_categories(): array
{
    return [
        'active'         => ['label' => 'In service',      'help' => 'Counts as an active vehicle; can take a driver and a deployment.'],
        'maintenance'    => ['label' => 'Under maintenance', 'help' => 'Being repaired. Cannot take a new driver or deployment.'],
        'out_of_service' => ['label' => 'Out of service',  'help' => 'Not usable for now. Cannot take a new driver or deployment.'],
        'inactive'       => ['label' => 'Inactive',        'help' => 'Retired or idle. Cannot take a new driver or deployment.'],
    ];
}

/** Rows of one lookup, active ones plus (optionally) the one currently selected. */
function trn_options(string $kind, ?int $currentId = null): array
{
    $reg = trn_config_registry()[$kind] ?? null;
    if ($reg === null) {
        return [];
    }
    $params = [];
    $where = "status = 'active'";
    if ($currentId !== null && $currentId > 0) {
        $where = "(status = 'active' OR id = :cur)";
        $params['cur'] = $currentId;
    }
    return Database::fetchAll("SELECT * FROM `{$reg['table']}` WHERE {$where} ORDER BY name ASC", $params);
}

/** How many records use one lookup value (dependency protection + display). */
function trn_lookup_usage(string $kind, int $id): int
{
    $reg = trn_config_registry()[$kind] ?? null;
    if ($reg === null) {
        return 0;
    }
    $total = 0;
    foreach ($reg['usage'] as [$table, $column]) {
        $total += (int)Database::fetchValue("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :id", ['id' => $id]);
    }
    return $total;
}

function trn_status_badge_class(string $category): string
{
    return match ($category) {
        'active' => 'badge--active',
        'maintenance' => 'badge--pending',
        default => 'badge--inactive',
    };
}

function trn_active_badge(string $status): string
{
    return $status === 'active' ? 'badge--active' : 'badge--inactive';
}

/** The default status for new vehicles (the one flagged as default), or null. */
function trn_default_status_id(): ?int
{
    $id = Database::fetchValue("SELECT id FROM vehicle_statuses WHERE is_default = 1 AND status = 'active' ORDER BY id ASC LIMIT 1");
    return $id !== null ? (int)$id : null;
}

/** Does a status (by id) belong to a behaviour group that may take drivers/deployments? */
function trn_status_category(int $statusId): ?string
{
    $c = Database::fetchValue('SELECT category FROM vehicle_statuses WHERE id = :id', ['id' => $statusId]);
    return $c !== null ? (string)$c : null;
}

/** Is configuration complete enough to add a vehicle? Returns the missing list names. */
function trn_missing_setup(): array
{
    $missing = [];
    foreach (['vehicle_type', 'vehicle_status', 'ownership_type'] as $kind) {
        $reg = trn_config_registry()[$kind];
        if (!Database::exists("SELECT id FROM `{$reg['table']}` WHERE status = 'active' LIMIT 1")) {
            $missing[$kind] = $reg['plural'];
        }
    }
    return $missing;
}

/* ------------------------------------------------------------------ *
 | Area-based access (reuses HR's rule)                                |
 * ------------------------------------------------------------------ */

/**
 * SQL fragment limiting vehicles to the signed-in user's own UCs.
 * '' = unrestricted (Super Admin, or a user not linked to an employee).
 * A restricted user sees only vehicles CURRENTLY deployed inside their UCs —
 * enforced in the query itself, so changing an id in a URL cannot bypass it.
 */
function trn_scope_sql(string $v = 'v'): string
{
    $ids = hr_accessible_uc_ids();
    if ($ids === null) {
        return '';
    }
    if (empty($ids)) {
        return '1 = 0';
    }
    return 'EXISTS (SELECT 1 FROM vehicle_deployments scp WHERE scp.vehicle_id = ' . $v . '.id AND scp.open_flag = 1'
        . ' AND scp.uc_id IN (' . implode(',', array_map('intval', $ids)) . '))';
}

function trn_is_scoped(): bool
{
    return hr_accessible_uc_ids() !== null;
}

function trn_vehicle_in_scope(int $vehicleId): bool
{
    $scope = trn_scope_sql('v');
    if ($scope === '') {
        return Database::exists('SELECT v.id FROM vehicles v WHERE v.id = :id', ['id' => $vehicleId]);
    }
    return Database::exists('SELECT v.id FROM vehicles v WHERE v.id = :id AND ' . $scope, ['id' => $vehicleId]);
}

/* ------------------------------------------------------------------ *
 | Vehicle loading                                                     |
 * ------------------------------------------------------------------ */

/** Columns every screen needs to describe a vehicle in one query. */
function trn_vehicle_columns(): string
{
    return "v.*, vt.name AS type_name, vs.name AS status_name, vs.category AS status_category,
            ot.name AS ownership_name,
            da.id AS driver_assignment_id, da.employee_id AS driver_id, da.assigned_date AS driver_since,
            dr.name AS driver_name, dr.cnic AS driver_cnic, dr.phone AS driver_phone, dr.employee_code AS driver_code,
            dr.status AS driver_status,
            dp.id AS deployment_id, dp.start_date AS deployed_since,
            dp.region_id, dp.district_id, dp.tehsil_id, dp.uc_id, dp.beat_id,
            rg.name AS region_name, ds.name AS district_name, th.name AS tehsil_name,
            uc.name AS uc_name, bt.name AS beat_name";
}

/** FROM + JOINs: type, status, ownership, CURRENT driver, CURRENT deployment and its geography. */
function trn_vehicle_from(): string
{
    return "FROM vehicles v
              JOIN vehicle_types vt ON vt.id = v.vehicle_type_id
              JOIN vehicle_statuses vs ON vs.id = v.status_id
              JOIN vehicle_ownership_types ot ON ot.id = v.ownership_type_id
              LEFT JOIN vehicle_driver_assignments da ON da.vehicle_id = v.id AND da.open_flag = 1
              LEFT JOIN employees dr ON dr.id = da.employee_id
              LEFT JOIN vehicle_deployments dp ON dp.vehicle_id = v.id AND dp.open_flag = 1
              LEFT JOIN regions rg ON rg.id = dp.region_id
              LEFT JOIN districts ds ON ds.id = dp.district_id
              LEFT JOIN tehsils th ON th.id = dp.tehsil_id
              LEFT JOIN ucs uc ON uc.id = dp.uc_id
              LEFT JOIN beats bt ON bt.id = dp.beat_id";
}

function trn_vehicle_select(): string
{
    return 'SELECT ' . trn_vehicle_columns() . ' ' . trn_vehicle_from();
}

function trn_find_vehicle(int $id): ?array
{
    return Database::fetch(trn_vehicle_select() . ' WHERE v.id = :id', ['id' => $id]);
}

/**
 * Load a vehicle for a request and refuse it when it is missing or outside
 * the user's area. Never trust an id from the URL alone.
 */
function trn_require_vehicle(int $id): array
{
    Auth::requireLogin();
    $vehicle = $id > 0 ? trn_find_vehicle($id) : null;
    if ($vehicle === null) {
        trn_not_found();
    }
    if (!trn_vehicle_in_scope($id)) {
        log_event('transport', 'Out-of-scope vehicle access blocked', ['user_id' => Auth::id(), 'vehicle_id' => $id]);
        if (is_ajax()) {
            json_response(['ok' => false, 'message' => 'You do not have access to this record.'], 403);
        }
        http_response_code(403);
        require INCLUDES_PATH . '/components/forbidden.php';
        exit;
    }
    return $vehicle;
}

function trn_vehicle_label(array $v): string
{
    return (string)$v['registration_no'] . ' · ' . (string)($v['type_name'] ?? '');
}

/** "UC-14 / Beat A — Lahore City, Lahore" for the vehicle's current deployment, or ''. */
function trn_area_text(array $row): string
{
    if (empty($row['uc_name'])) {
        return '';
    }
    $t = (string)$row['uc_name'] . (!empty($row['beat_name']) ? ' / ' . (string)$row['beat_name'] : '');
    $parts = array_filter([(string)($row['tehsil_name'] ?? ''), (string)($row['district_name'] ?? ''), (string)($row['region_name'] ?? '')]);
    return $t . ($parts ? ' — ' . implode(', ', $parts) : '');
}

/* ------------------------------------------------------------------ *
 | Geography (reuses Phase 2 tables)                                   |
 * ------------------------------------------------------------------ */

/**
 * Validate a Region → District → Tehsil → UC (→ Beat) chain against the real
 * tables: every level must exist, be active, and belong to its parent.
 * Returns the normalised ids, or null when the chain is invalid.
 *
 * @return array{region_id:int,district_id:int,tehsil_id:int,uc_id:int,beat_id:?int}|null
 */
function trn_resolve_geography(int $regionId, int $districtId, int $tehsilId, int $ucId, ?int $beatId): ?array
{
    $row = Database::fetch(
        "SELECT r.id AS region_id, d.id AS district_id, t.id AS tehsil_id, u.id AS uc_id
           FROM ucs u
           JOIN tehsils t ON t.id = u.tehsil_id AND t.status = 'active'
           JOIN districts d ON d.id = t.district_id AND d.status = 'active'
           JOIN regions r ON r.id = d.region_id AND r.status = 'active'
          WHERE u.id = :uc AND u.status = 'active' AND t.id = :t AND d.id = :d AND r.id = :r",
        ['uc' => $ucId, 't' => $tehsilId, 'd' => $districtId, 'r' => $regionId]
    );
    if ($row === null) {
        return null;
    }
    if ($beatId !== null && $beatId > 0) {
        if (!Database::exists("SELECT id FROM beats WHERE id = :b AND uc_id = :u AND status = 'active'", ['b' => $beatId, 'u' => $ucId])) {
            return null;
        }
    } else {
        $beatId = null;
    }
    return [
        'region_id' => (int)$row['region_id'], 'district_id' => (int)$row['district_id'],
        'tehsil_id' => (int)$row['tehsil_id'], 'uc_id' => (int)$row['uc_id'], 'beat_id' => $beatId,
    ];
}

/** Read the five geography ids from POST (names: region_id … beat_id). */
function trn_post_geography(): array
{
    $beat = post('beat_id');
    return [(int)post('region_id'), (int)post('district_id'), (int)post('tehsil_id'), (int)post('uc_id'), $beat !== '' ? (int)$beat : null];
}

/* ------------------------------------------------------------------ *
 | Drivers (existing HR employees)                                     |
 * ------------------------------------------------------------------ */

/** @return int[] */
function trn_id_list(string $csv): array
{
    $ids = [];
    foreach (explode(',', $csv) as $p) {
        $n = (int)trim($p);
        if ($n > 0) {
            $ids[$n] = $n;
        }
    }
    return array_values($ids);
}

function trn_driver_rules_configured(): bool
{
    return trn_id_list(trn_setting('transport_driver_pool_ids')) !== []
        || trn_id_list(trn_setting('transport_driver_type_ids')) !== [];
}

/**
 * SQL fragment: which employees may drive. Taken from Transport Configuration
 * (HR staff pool(s) and/or staff type(s)). Nothing configured = nobody is
 * eligible, so the screen tells the administrator what to set up.
 */
function trn_driver_eligibility_sql(string $e = 'e'): string
{
    $pools = trn_id_list(trn_setting('transport_driver_pool_ids'));
    $types = trn_id_list(trn_setting('transport_driver_type_ids'));
    if ($pools === [] && $types === []) {
        return '1 = 0';
    }
    $parts = [];
    if ($pools !== []) {
        $parts[] = $e . '.staff_pool_id IN (' . implode(',', $pools) . ')';
    }
    if ($types !== []) {
        $parts[] = $e . '.staff_type_id IN (' . implode(',', $types) . ')';
    }
    return '(' . implode(' AND ', $parts) . ')';
}

/** One eligible, active employee by id — or null. */
function trn_find_eligible_driver(int $employeeId): ?array
{
    return Database::fetch(
        "SELECT e.id, e.name, e.cnic, e.phone, e.employee_code, e.status FROM employees e
          WHERE e.id = :id AND e.status = 'active' AND " . trn_driver_eligibility_sql('e'),
        ['id' => $employeeId]
    );
}

function trn_driver_label(?array $row, string $prefix = 'driver_'): string
{
    if ($row === null || empty($row[$prefix . 'name'])) {
        return '—';
    }
    return (string)$row[$prefix . 'name'];
}

/* ------------------------------------------------------------------ *
 | Documents — expiry status                                           |
 * ------------------------------------------------------------------ */

/**
 * SQL CASE giving 'none' | 'valid' | 'expiring' | 'expired' for a current
 * document row ($d) and its type ($t). The warning window is the type's own
 * setting when it has one, otherwise the configured global default. Dates
 * are compared against PHP's "today" so the whole app shares one clock.
 */
function trn_doc_status_sql(string $d = 'vdoc', string $t = 'vdt'): string
{
    $today = "'" . trn_today() . "'";
    $days = trn_warning_days();
    return "CASE WHEN {$d}.expiry_date IS NULL THEN 'none'
                 WHEN {$d}.expiry_date < {$today} THEN 'expired'
                 WHEN {$d}.expiry_date <= DATE_ADD({$today}, INTERVAL COALESCE({$t}.warning_days, {$days}) DAY) THEN 'expiring'
                 ELSE 'valid' END";
}

function trn_doc_status_label(string $s): string
{
    return match ($s) { 'valid' => 'Valid', 'expiring' => 'Expiring soon', 'expired' => 'Expired', default => 'No expiry' };
}

function trn_doc_status_badge(string $s): string
{
    return match ($s) { 'valid' => 'badge--active', 'expiring' => 'badge--pending', 'expired' => 'badge--blocked', default => 'badge--inactive' };
}

/* ------------------------------------------------------------------ *
 | Pagination                                                          |
 * ------------------------------------------------------------------ */

/** @return array{page:int,per:int,pages:int,offset:int} */
function trn_paginate(int $total, int $per = 25): array
{
    $pages = max(1, (int)ceil($total / $per));
    $page = min(max(1, (int)query('page', '1')), $pages);
    return ['page' => $page, 'per' => $per, 'pages' => $pages, 'offset' => ($page - 1) * $per];
}

/** Query string from an array, dropping empty values. */
function trn_qs(array $params): string
{
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return $params ? '?' . http_build_query($params) : '';
}

/** Render stored audit old/new JSON as plain "Field: old → new; …" text. */
function trn_diff_text(?string $oldJson, ?string $newJson): string
{
    $o = $oldJson ? (json_decode($oldJson, true) ?: []) : [];
    $n = $newJson ? (json_decode($newJson, true) ?: []) : [];
    $parts = [];
    foreach (array_unique(array_merge(array_keys($o), array_keys($n))) as $k) {
        $a = array_key_exists($k, $o) ? (string)($o[$k] ?? '') : null;
        $b = array_key_exists($k, $n) ? (string)($n[$k] ?? '') : null;
        $parts[] = $k . ': ' . ($a !== null ? ($a === '' ? '(empty)' : $a) : '—') . ' → ' . ($b !== null ? ($b === '' ? '(empty)' : $b) : '—');
    }
    return implode(' ; ', $parts);
}
