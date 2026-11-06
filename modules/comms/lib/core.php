<?php
/**
 * Communication — core helpers: module settings (stored in the shared system_settings table,
 * never hard-coded), central timezone, audit trail and request guards.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** Setting defaults. Every value can be overridden in system_settings (group "comms"). */
function comms_setting_defaults(): array
{
    return [
        'comms_poll_interval_sec'   => '5',      // chat/notification polling
        'comms_timezone'            => APP_TIMEZONE,
        'comms_edit_window_min'     => '15',     // own-message edit/delete window
        'comms_max_file_mb'         => '10',
        'comms_storage_limit_mb'    => '2048',
        'comms_voice_max_sec'       => '120',
        'comms_allowed_extensions'  => 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv',
    ];
}

/** Module settings reader. Own tiny cache (reset after a save) so a change is visible immediately. */
function comms_settings_reset(): void
{
    comms_settings_cache(true);
}

function comms_settings_cache(bool $reset = false): array
{
    static $cache = null;
    if ($reset || $cache === null) {
        $cache = [];
        foreach (Database::fetchAll("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'comms\\_%'") as $r) {
            $cache[$r['setting_key']] = (string)$r['setting_value'];
        }
    }
    return $cache;
}

function comms_setting(string $key): string
{
    $c = comms_settings_cache();
    return $c[$key] ?? (comms_setting_defaults()[$key] ?? '');
}

function comms_tz(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        try {
            $tz = new DateTimeZone(comms_setting('comms_timezone'));
        } catch (Throwable) {
            $tz = new DateTimeZone(APP_TIMEZONE);
        }
    }
    return $tz;
}

function comms_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', comms_tz());
}

/**
 * Append one audit record. The module offers no update or delete path for this table.
 * Never pass passwords, tokens or message bodies in $old / $new.
 */
function comms_audit(string $action, string $description, array $x = []): void
{
    $json = static fn($v): ?string => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    Database::insert('comms_audit_log', [
        'actor_id'     => array_key_exists('actor_id', $x) ? $x['actor_id'] : Auth::id(),
        'action'       => mb_substr($action, 0, 80),
        'subject_type' => $x['subject_type'] ?? null,
        'subject_id'   => $x['subject_id'] ?? null,
        'description'  => mb_substr($description, 0, 250),
        'old_values'   => $json($x['old'] ?? null),
        'new_values'   => $json($x['new'] ?? null),
        'ip_address'   => PHP_SAPI === 'cli' ? 'cli' : client_ip(),
    ]);
}

/** Audit that must never break the user's action. */
function comms_audit_safe(string $action, string $description, array $x = []): void
{
    try {
        comms_audit($action, $description, $x);
    } catch (Throwable $e) {
        log_event('comms', 'Audit write failed', ['action' => $action, 'error' => $e->getMessage()]);
    }
}

/**
 * Module guard: workspace access AND the specific permission(s). The two are separate on purpose —
 * no permission implies another.
 */
function comms_require(string ...$perms): void
{
    Permission::require('comms.access');
    foreach ($perms as $p) {
        Permission::require($p);
    }
}
