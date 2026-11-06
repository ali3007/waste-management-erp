<?php
/**
 * Attendance — core helpers: Asia/Karachi clock, status vocabulary, settings,
 * category (staff pool) mapping, audit trail and request guards.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/* ------------------------------------------------------------------ *
 | Clock (always Asia/Karachi, never the browser's)                    |
 * ------------------------------------------------------------------ */

function att_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone(ATT_TZ);
}

/**
 * Current time in Asia/Karachi. A test clock can be injected ONLY from the
 * command line (cron/sweep.php --now=..., the self-test); a web request can
 * never alter the clock.
 */
function att_now(): DateTimeImmutable
{
    if (PHP_SAPI === 'cli' && !empty($GLOBALS['__att_now_override'])) {
        return new DateTimeImmutable((string)$GLOBALS['__att_now_override'], att_tz());
    }
    return new DateTimeImmutable('now', att_tz());
}

function att_set_test_clock(?string $dateTime): void
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('The test clock is only available from the command line.');
    }
    $GLOBALS['__att_now_override'] = $dateTime;
}

function att_now_sql(): string { return att_now()->format('Y-m-d H:i:s'); }
function att_today(): string   { return att_now()->format('Y-m-d'); }
function att_tomorrow(): string { return att_now()->modify('+1 day')->format('Y-m-d'); }

/** Strict YYYY-MM-DD → same string, or null. */
function att_valid_date(string $v): ?string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, att_tz());
    return ($d !== false && $d->format('Y-m-d') === $v && $v >= '2000-01-01' && $v <= '2100-12-31') ? $v : null;
}

function att_is_sunday(string $date): bool
{
    return (new DateTimeImmutable($date, att_tz()))->format('N') === '7';
}

/** 'HH:MM' or 'HH:MM:SS' → 'HH:MM:SS', or null. */
function att_valid_time(string $v): ?string
{
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', trim($v), $m)) {
        return null;
    }
    return $m[1] . ':' . $m[2] . ':' . ($m[3] ?? '00');
}

function att_fmt_time(?string $v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return date('h:i A', strtotime('1970-01-01 ' . substr($v, -8)) ?: 0);
}

function att_fmt_dt(?string $v): string
{
    return $v ? date('d M Y, h:i A', strtotime($v) ?: 0) : '—';
}

function att_fmt_date(?string $v): string
{
    return $v ? date('D, d M Y', strtotime($v) ?: 0) : '—';
}

/* ------------------------------------------------------------------ *
 | Status vocabulary — one definition for every screen and export      |
 * ------------------------------------------------------------------ */

const ATT_OPEN_STATUSES = ['not_marked', 'checked_in', 'pending_review', 'incomplete'];

function att_status_labels(): array
{
    return [
        'not_marked'     => 'Not marked yet',
        'checked_in'     => 'Checked in',
        'present'        => 'Present',
        'incomplete'     => 'Incomplete',
        'pending_review' => 'Pending Review',
        'absent'         => 'Absent',
        'leave'          => 'Leave',
        'off_day'        => 'Off Day',
        'holiday'        => 'Holiday',
    ];
}

function att_status_label(string $s): string
{
    return att_status_labels()[$s] ?? ucfirst(str_replace('_', ' ', $s));
}

function att_status_badge(string $s): string
{
    return '<span class="badge att-st att-st--' . e($s) . '">' . e(att_status_label($s)) . '</span>';
}

/* ------------------------------------------------------------------ *
 | Settings (system_settings, group 'attendance') — read fresh          |
 * ------------------------------------------------------------------ */

function att_setting(string $key, ?string $default = null): ?string
{
    $v = Database::fetchValue('SELECT setting_value FROM system_settings WHERE setting_key = :k', ['k' => $key]);
    return $v === null ? $default : (string)$v;
}

function att_save_setting(string $key, string $value): void
{
    Database::execute(
        "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (:k, :v, 'attendance')
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        ['k' => $key, 'v' => $value]
    );
    att_config_cache(true);
}

/** @return int[] */
function att_id_list(?string $csv): array
{
    if ($csv === null || trim($csv) === '') {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('intval', explode(',', $csv)), static fn(int $v): bool => $v > 0)));
}

/** SQL-safe IN() body for an int list; '0' when empty so IN(0) matches nothing. */
function att_in(array $ids): string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $v): bool => $v > 0)));
    return $ids ? implode(',', $ids) : '0';
}

/* ------------------------------------------------------------------ *
 | Category mapping — which HR staff pools/types behave how            |
 | (data, not code: set on the Configuration screen)                   |
 * ------------------------------------------------------------------ */

/** Per-request cache of the configuration; $reset drops it. */
function att_config_cache(bool $reset = false): array
{
    static $c = null;
    if ($reset) {
        $c = null;
        return [];
    }
    if ($c !== null) {
        return $c;
    }
    $bySlug = static function (array $slugs): array {
        $out = [];
        foreach (Database::fetchAll('SELECT id, slug FROM hr_staff_pools') as $p) {
            if (in_array($p['slug'], $slugs, true)) {
                $out[] = (int)$p['id'];
            }
        }
        return $out;
    };
    $self = att_setting('attendance_self_pool_ids');
    $sup  = att_setting('attendance_supervised_pool_ids');
    $selfTypes = att_setting('attendance_self_type_ids');
    $supTypes = att_setting('attendance_supervisor_type_ids');
    if ($supTypes === null) {
        $supTypes = att_setting('complaint_supervisor_type_ids');   // reuse the Complaints choice when present
    }
    $supTypeIds = $supTypes !== null ? att_id_list($supTypes)
        : array_map('intval', array_column(Database::fetchAll("SELECT id FROM hr_staff_types WHERE slug = 'supervisor' OR name LIKE '%supervisor%'"), 'id'));

    return $c = [
        'self_pools'       => $self !== null ? att_id_list($self) : $bySlug(['office-staff']),
        'supervised_pools' => $sup !== null ? att_id_list($sup) : $bySlug(['field-staff', 'transport-staff']),
        /* Until an administrator saves the categories, Supervisors mark their OWN attendance
           (My Attendance); nobody else marks them. Saving the Setup screen makes the choice explicit. */
        'self_types'       => $selfTypes !== null ? att_id_list($selfTypes) : $supTypeIds,
        'supervisor_types' => $supTypeIds,
    ];
}

function att_self_pool_ids(): array       { return att_config_cache()['self_pools']; }
function att_supervised_pool_ids(): array { return att_config_cache()['supervised_pools']; }
function att_self_type_ids(): array       { return att_config_cache()['self_types']; }
function att_supervisor_type_ids(): array { return att_config_cache()['supervisor_types']; }

/**
 * How an employee's attendance is recorded:
 *   'self'       — the employee marks their own (Office Staff)
 *   'supervised' — their Supervisor marks it (Field / Transport Staff)
 *   null         — not tracked by the module
 */
function att_employee_mode(int $poolId, int $typeId): ?string
{
    if (in_array($typeId, att_self_type_ids(), true) || in_array($poolId, att_self_pool_ids(), true)) {
        return 'self';
    }
    if (in_array($poolId, att_supervised_pool_ids(), true) && !in_array($typeId, att_supervisor_type_ids(), true)) {
        return 'supervised';
    }
    return null;
}

/** Do company-wide yearly holidays apply to this pool? (Office Staff only.) */
function att_pool_observes_holidays(int $poolId): bool
{
    return in_array($poolId, att_self_pool_ids(), true);
}

/** Office pools use the company-level timing; everyone else uses their UC's. */
function att_pool_uses_company_timing(int $poolId): bool
{
    return in_array($poolId, att_self_pool_ids(), true);
}

/* ------------------------------------------------------------------ *
 | Audit (shared activity_log + attendance_audit_changes)              |
 * ------------------------------------------------------------------ */

/** Signed-in user id, or null for scheduled/system actions. */
function att_actor_id(): ?int
{
    if (PHP_SAPI === 'cli') {
        return $GLOBALS['__att_cli_actor'] ?? null;
    }
    $id = Auth::id();
    return $id > 0 ? $id : null;
}

/**
 * Write one audit entry. Called inside the same transaction as the change
 * whenever there is one, so a change can never be saved without its trail.
 *
 * $x keys: employee_id, date, uc_id, old, new, reason, outcome(success|blocked|failed),
 *          user_id (override; null+system for scheduler), subject_type, subject_id.
 */
function att_audit(string $action, string $description, array $x = []): void
{
    $userId = array_key_exists('user_id', $x) ? $x['user_id'] : att_actor_id();
    $logId = Database::insert('activity_log', [
        'user_id' => $userId, 'module' => 'attendance', 'action' => $action,
        'subject_type' => $x['subject_type'] ?? 'employee', 'subject_id' => $x['subject_id'] ?? ($x['employee_id'] ?? null),
        'description' => mb_substr($description, 0, 250),
        'ip_address' => PHP_SAPI === 'cli' ? 'cli' : client_ip(),
    ]);
    $json = static fn($v): ?string => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    Database::insert('attendance_audit_changes', [
        'activity_log_id' => $logId,
        'employee_id' => $x['employee_id'] ?? null,
        'attendance_date' => $x['date'] ?? null,
        'uc_id' => $x['uc_id'] ?? null,
        'outcome' => $x['outcome'] ?? 'success',
        'old_value' => $json($x['old'] ?? null),
        'new_value' => $json($x['new'] ?? null),
        'reason' => isset($x['reason']) && $x['reason'] !== '' ? mb_substr((string)$x['reason'], 0, 500) : null,
    ]);
}

/** Audit that must survive even when the surrounding transaction rolled back. */
function att_audit_safe(string $action, string $description, array $x = []): void
{
    try {
        att_audit($action, $description, $x);
    } catch (Throwable $e) {
        log_event('attendance', 'Audit write failed', ['action' => $action, 'error' => $e->getMessage()]);
    }
}

/* ------------------------------------------------------------------ *
 | Request guards                                                       |
 * ------------------------------------------------------------------ */

/** Module permission = the shared "access" key AND the specific key. */
function att_require(string ...$perms): void
{
    Permission::require('attendance.access');
    foreach ($perms as $p) {
        Permission::require($p);
    }
}

/** Require access plus ANY ONE of several permissions. */
function att_require_any(array $perms): void
{
    Permission::require('attendance.access');
    foreach ($perms as $p) {
        if (can($p)) {
            return;
        }
    }
    Permission::require($perms[0]);   // renders the standard 403
}

function att_back(string $url, string $type, string $message): never
{
    flash($type, $message);
    redirect($url);
}

/** Standard preamble for every form handler: installed → POST → permission → CSRF. */
function att_post_guard(array $perms, string $backUrl): void
{
    if (!IS_INSTALLED) {
        redirect('install/index.php');
    }
    if (!is_post()) {
        redirect($backUrl);
    }
    att_require(...$perms);
    Security::requireCsrf($backUrl);
}

/** Only paths inside modules/attendance are honoured as a return target. */
function att_return_url(string $default): string
{
    $to = post('return_to');
    if ($to !== '' && preg_match('#^modules/attendance/[A-Za-z0-9_./?=&%-]*$#', $to) && !str_contains($to, '..')) {
        return $to;
    }
    return $default;
}

function att_paginate(int $total, int $per = 25): array
{
    $pages = max(1, (int)ceil($total / $per));
    $page = min(max(1, (int)query('page', '1')), $pages);
    return ['page' => $page, 'per' => $per, 'pages' => $pages, 'offset' => ($page - 1) * $per];
}

function att_qs(array $params): string
{
    $params = array_filter($params, static fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return $params ? '?' . http_build_query($params) : '';
}

/** JSON error/success helper for AJAX endpoints. */
function att_json(bool $ok, string $message, array $extra = [], int $status = 200): never
{
    json_response(['ok' => $ok, 'message' => $message] + $extra, $status);
}
