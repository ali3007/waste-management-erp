<?php
/**
 * Complaints — shared helpers: statuses, lookups, numbering, geography
 * validation (against the existing Phase 2 tables) and HR staff lookup
 * (against the existing Phase 3 tables).
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** Current timestamp used for every stored time, so PHP and SQL never disagree. */
function cmp_now(): string
{
    return date('Y-m-d H:i:s');
}

/** Workflow states keyed by slug (label/colour are editable in the database). */
function cmp_statuses(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (Database::fetchAll('SELECT slug, label, color, sort_order FROM complaint_statuses ORDER BY sort_order ASC') as $row) {
            $cache[$row['slug']] = $row;
        }
    }
    return $cache;
}

function cmp_status_label(string $slug): string
{
    return cmp_statuses()[$slug]['label'] ?? ucfirst(str_replace('_', ' ', $slug));
}

function cmp_status_color(string $slug): string
{
    return cmp_statuses()[$slug]['color'] ?? '#8a948f';
}

/** A coloured status pill. The colour is validated as hex before use in a style. */
function cmp_status_badge(string $slug): string
{
    $color = cmp_status_color($slug);
    $color = preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : '#8a948f';
    return '<span class="cmp-pill" style="--pill:' . $color . '">' . e(cmp_status_label($slug)) . '</span>';
}

function cmp_priority_badge(?string $name, ?string $color): string
{
    if ($name === null || $name === '') {
        return '—';
    }
    $color = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$color) ? $color : '#17a06a';
    return '<span class="cmp-pill cmp-pill--outline" style="--pill:' . $color . '">' . e($name) . '</span>';
}

/** Statuses in which a complaint is "open" (still needs work). */
function cmp_open_statuses(): array
{
    return [CMP_ST_NEW, CMP_ST_ASSIGNED, CMP_ST_PROGRESS, CMP_ST_READY, CMP_ST_REOPENED];
}

/** Configured lookup rows for dropdowns (active ones, plus the one currently selected). */
function cmp_lookup_options(string $table, ?int $includeId = null): array
{
    if (!in_array($table, ['complaint_sources', 'complaint_types', 'complaint_priorities'], true)) {
        return [];
    }
    $extra = $table === 'complaint_priorities' ? ', color' : '';
    $sql = "SELECT id, name{$extra}, status FROM {$table} WHERE status = 'active'";
    $params = [];
    if ($includeId) {
        $sql .= ' OR id = :inc';
        $params['inc'] = $includeId;
    }
    return Database::fetchAll($sql . ' ORDER BY sort_order ASC, name ASC', $params);
}

/* ------------------------------------------------------------------ *
 | Settings                                                            |
 * ------------------------------------------------------------------ */

function cmp_save_setting(string $key, string $value): void
{
    Database::execute(
        "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (:k, :v, 'complaints')
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        ['k' => $key, 'v' => $value]
    );
}

/** Read fresh from the database (bypasses the per-request cache used by setting()). */
function cmp_setting_fresh(string $key, ?string $default = null): ?string
{
    $v = Database::fetchValue('SELECT setting_value FROM system_settings WHERE setting_key = :k', ['k' => $key]);
    return $v === false || $v === null ? $default : (string)$v;
}

/**
 * HR staff-type ids counted as "Supervisor" ('supervisor') or
 * "Monitoring Officer" ('monitor'). Configured on the Settings tab; until
 * an administrator saves a choice, types whose slug is `supervisor` /
 * `monitoring-officer` are used so a standard HR setup works immediately.
 *
 * @return int[]
 */
function cmp_staff_type_ids(string $role): array
{
    static $cache = [];
    if (isset($cache[$role])) {
        return $cache[$role];
    }
    $key = $role === 'supervisor' ? 'complaint_supervisor_type_ids' : 'complaint_mo_type_ids';
    $saved = cmp_setting_fresh($key);

    if ($saved === null) {
        $slug = $role === 'supervisor' ? 'supervisor' : 'monitoring-officer';
        $rows = Database::fetchAll('SELECT id FROM hr_staff_types WHERE slug = :s', ['s' => $slug]);
        return $cache[$role] = array_map('intval', array_column($rows, 'id'));
    }
    $ids = array_values(array_filter(array_map('intval', explode(',', $saved)), static fn($v) => $v > 0));
    return $cache[$role] = $ids;
}

/* ------------------------------------------------------------------ *
 | Complaint number                                                    |
 * ------------------------------------------------------------------ */

/**
 * Next complaint number. MUST be called inside the transaction that
 * inserts the complaint: the counter row is locked until that transaction
 * ends, so two simultaneous submissions can never receive the same
 * number, and a rolled-back submission gives its number back (no gaps).
 * The UNIQUE index on complaint_number is the final safety net.
 */
function cmp_next_number(): string
{
    Database::execute("UPDATE complaint_sequences SET last_value = LAST_INSERT_ID(last_value + 1) WHERE name = 'complaint'");
    $n = (int)Database::fetchValue('SELECT LAST_INSERT_ID()');

    $prefix = preg_replace('/[^A-Za-z0-9\-_\/]/', '', (string)cmp_setting_fresh('complaint_number_prefix', 'CMP-')) ?? '';
    $padding = max(1, min(12, (int)cmp_setting_fresh('complaint_number_padding', '6')));
    return $prefix . str_pad((string)$n, $padding, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------ *
 | Geography (Phase 2 tables — read only)                              |
 * ------------------------------------------------------------------ */

/**
 * Validate a Region → District → Tehsil → UC (→ Beat) chain as one real
 * chain, using the existing structure tables. A newly chosen record must
 * be active; $keepUc lets an existing complaint keep a UC that has been
 * deactivated since.
 *
 * @return array{region_id:int,district_id:int,tehsil_id:int,uc_id:int,beat_id:?int,uc_name:string}|null
 */
function cmp_validate_geography(int $region, int $district, int $tehsil, int $uc, ?int $beat, ?int $keepUc = null): ?array
{
    $row = Database::fetch(
        "SELECT u.id AS uc_id, u.name AS uc_name, t.id AS tehsil_id, d.id AS district_id, r.id AS region_id
           FROM ucs u
           JOIN tehsils t ON t.id = u.tehsil_id
           JOIN districts d ON d.id = t.district_id
           JOIN regions r ON r.id = d.region_id
          WHERE u.id = :uc AND t.id = :t AND d.id = :d AND r.id = :r
            AND ((u.status = 'active' AND t.status = 'active' AND d.status = 'active' AND r.status = 'active') OR u.id = :keep)",
        ['uc' => $uc, 't' => $tehsil, 'd' => $district, 'r' => $region, 'keep' => (int)$keepUc]
    );
    if ($row === null) {
        return null;
    }
    $beatId = null;
    if ($beat !== null && $beat > 0) {
        $ok = Database::exists("SELECT id FROM beats WHERE id = :b AND uc_id = :uc AND status = 'active'", ['b' => $beat, 'uc' => $uc]);
        if (!$ok) {
            return null;
        }
        $beatId = $beat;
    }
    return [
        'region_id' => (int)$row['region_id'], 'district_id' => (int)$row['district_id'],
        'tehsil_id' => (int)$row['tehsil_id'], 'uc_id' => (int)$row['uc_id'],
        'beat_id' => $beatId, 'uc_name' => (string)$row['uc_name'],
    ];
}

/** Active children of a structure level, for the form's cascade (one level at a time). */
function cmp_geography_children(string $level, int $parent): array
{
    $map = [
        'district' => ['districts', 'region_id'], 'tehsil' => ['tehsils', 'district_id'],
        'uc' => ['ucs', 'tehsil_id'], 'beat' => ['beats', 'uc_id'],
    ];
    if (!isset($map[$level]) || $parent <= 0) {
        return [];
    }
    [$table, $col] = $map[$level];
    return Database::fetchAll("SELECT id, name FROM {$table} WHERE {$col} = :p AND status = 'active' ORDER BY name ASC", ['p' => $parent]);
}

/* ------------------------------------------------------------------ *
 | HR staff for a UC (Phase 3 tables — read only)                      |
 * ------------------------------------------------------------------ */

/**
 * Who currently covers a UC, from the live HR assignments.
 *
 * @return array{supervisors:array<int,array>, monitors:array<int,array>}
 */
function cmp_uc_staff(int $ucId): array
{
    $out = ['supervisors' => [], 'monitors' => []];
    $supervisorTypes = cmp_staff_type_ids('supervisor');
    $monitorTypes = cmp_staff_type_ids('monitor');
    $all = array_values(array_unique(array_merge($supervisorTypes, $monitorTypes)));
    if (!$all) {
        return $out;
    }

    $rows = Database::fetchAll(
        "SELECT DISTINCT e.id, e.name, e.employee_code, e.phone, e.user_id, e.staff_type_id, st.name AS type_name
           FROM employee_assignments a
           JOIN employees e ON e.id = a.employee_id AND e.status = 'active'
           JOIN hr_staff_types st ON st.id = e.staff_type_id
          WHERE a.uc_id = :uc AND a.unassigned_at IS NULL AND e.staff_type_id IN (" . implode(',', array_map('intval', $all)) . ')
          ORDER BY e.name ASC',
        ['uc' => $ucId]
    );
    foreach ($rows as $row) {
        $row['id'] = (int)$row['id'];
        $row['user_id'] = $row['user_id'] !== null ? (int)$row['user_id'] : null;
        if (in_array((int)$row['staff_type_id'], $supervisorTypes, true)) {
            $out['supervisors'][] = $row;
        }
        if (in_array((int)$row['staff_type_id'], $monitorTypes, true)) {
            $out['monitors'][] = $row;
        }
    }
    return $out;
}

/** Employees of the configured Supervisor / Monitoring Officer types, for filter dropdowns. */
function cmp_staff_options(string $role): array
{
    $ids = cmp_staff_type_ids($role);
    if (!$ids) {
        return [];
    }
    return Database::fetchAll(
        "SELECT id, name, employee_code FROM employees WHERE status = 'active' AND staff_type_id IN (" . implode(',', array_map('intval', $ids)) . ') ORDER BY name ASC'
    );
}

/* ------------------------------------------------------------------ *
 | Small formatting helpers                                            |
 * ------------------------------------------------------------------ */

function cmp_map_url(?string $lat, ?string $lng): ?string
{
    if ($lat === null || $lng === null || $lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
        return null;
    }
    $lat = number_format((float)$lat, 7, '.', '');
    $lng = number_format((float)$lng, 7, '.', '');
    return 'https://www.openstreetmap.org/?mlat=' . $lat . '&mlon=' . $lng . '#map=17/' . $lat . '/' . $lng;
}

function cmp_fmt_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    return max(1, (int)round($bytes / 1024)) . ' KB';
}

/** "dd Mon yyyy" for a DATE/DATETIME string, or an em dash. */
function cmp_fmt_date(?string $value): string
{
    return $value ? format_datetime($value, 'd M Y') : '—';
}

/** Parse a user-supplied date (Y-m-d) strictly; null when invalid. Past dates of any age are allowed. */
function cmp_parse_date(string $value): ?string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return null;
    }
    return $value;
}

function cmp_parse_time(string $value): ?string
{
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value)) {
        return null;
    }
    return strlen($value) === 5 ? $value . ':00' : $value;
}
