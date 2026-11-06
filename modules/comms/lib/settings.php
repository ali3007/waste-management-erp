<?php
/** Communication settings: validation and saving (values live in system_settings, group "comms"). */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** Extensions that can run code or script in a browser/server; never allowed, whatever the admin types. */
const COMMS_FORBIDDEN_EXT = ['php','php3','php4','php5','php7','php8','phtml','phar','pht','phps','exe','dll','bat','cmd','com','msi','sh','bash','js','mjs','html','htm','xhtml','svg','xml','jsp','asp','aspx','cgi','pl','py','rb','htaccess','ini','vbs','ps1','jar','scr','lnk'];

/** @return array<string,array{label:string,hint:string,type:string}> */
function comms_settings_fields(): array
{
    return [
        'comms_poll_interval_sec'  => ['label' => 'Refresh interval (seconds)', 'hint' => 'How often open chat windows check for new messages (2–60). Lower is faster but uses more of the server.', 'type' => 'number'],
        'comms_edit_window_min'    => ['label' => 'Edit / delete time limit (minutes)', 'hint' => 'How long people may change their own messages and comments (0–1440).', 'type' => 'number'],
        'comms_timezone'           => ['label' => 'Central timezone', 'hint' => 'Used for deadlines, reminders and message times.', 'type' => 'text'],
        'comms_max_file_mb'        => ['label' => 'Maximum file size (MB)', 'hint' => 'Per file, 1–200.', 'type' => 'number'],
        'comms_storage_limit_mb'   => ['label' => 'Total storage limit (MB)', 'hint' => 'Uploads stop when this is reached. Files are never deleted automatically. 100–10,000,000.', 'type' => 'number'],
        'comms_voice_max_sec'      => ['label' => 'Maximum voice message (seconds)', 'hint' => '5–600.', 'type' => 'number'],
        'comms_allowed_extensions' => ['label' => 'Allowed file types', 'hint' => 'Comma-separated extensions, for example pdf,docx,jpg. Executable and script types are always refused.', 'type' => 'text'],
    ];
}

/** @return array{0:array<string,string>,1:array<string,string>} cleaned values, errors keyed by setting */
function comms_validate_settings(array $in): array
{
    $clean = [];
    $err = [];
    $int = static function (string $key, string $label, int $min, int $max) use ($in, &$clean, &$err): void {
        $v = trim((string)($in[$key] ?? ''));
        if (!preg_match('/^\d{1,9}$/', $v) || (int)$v < $min || (int)$v > $max) {
            $err[$key] = $label . ' must be a whole number from ' . number_format($min) . ' to ' . number_format($max) . '.';
            return;
        }
        $clean[$key] = (string)(int)$v;
    };
    $int('comms_poll_interval_sec', 'Refresh interval', 2, 60);
    $int('comms_edit_window_min', 'Edit / delete time limit', 0, 1440);
    $int('comms_max_file_mb', 'Maximum file size', 1, 200);
    $int('comms_storage_limit_mb', 'Total storage limit', 100, 10000000);
    $int('comms_voice_max_sec', 'Maximum voice message', 5, 600);
    if (!$err && (int)$clean['comms_max_file_mb'] > (int)$clean['comms_storage_limit_mb']) {
        $err['comms_max_file_mb'] = 'The maximum file size cannot exceed the total storage limit.';
    }

    $tz = trim((string)($in['comms_timezone'] ?? ''));
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        $err['comms_timezone'] = 'Choose a valid timezone such as Asia/Karachi.';
    } else {
        $clean['comms_timezone'] = $tz;
    }

    $exts = [];
    foreach (explode(',', strtolower((string)($in['comms_allowed_extensions'] ?? ''))) as $e) {
        $e = ltrim(trim($e), '.');
        if ($e === '') {
            continue;
        }
        if (!preg_match('/^[a-z0-9]{1,8}$/', $e)) {
            $err['comms_allowed_extensions'] = 'File types may contain only letters and digits (e.g. pdf,docx).';
            break;
        }
        if (in_array($e, COMMS_FORBIDDEN_EXT, true)) {
            $err['comms_allowed_extensions'] = '“' . $e . '” is an executable or script type and can never be allowed.';
            break;
        }
        $exts[$e] = true;
    }
    if (!isset($err['comms_allowed_extensions'])) {
        if (!$exts) {
            $err['comms_allowed_extensions'] = 'Allow at least one file type.';
        } else {
            $clean['comms_allowed_extensions'] = implode(',', array_keys($exts));
        }
    }
    return [$clean, $err];
}

/** Save validated values; audits old/new for every changed key. @return string[] changed keys */
function comms_save_settings(array $clean, int $actorId): array
{
    $changed = [];
    $old = [];
    $new = [];
    foreach ($clean as $key => $value) {
        // Read the stored value directly: setting() caches for the whole request and would be stale.
        $stored = Database::fetchValue('SELECT setting_value FROM system_settings WHERE setting_key = :k', ['k' => $key]);
        $current = $stored !== null ? (string)$stored : (comms_setting_defaults()[$key] ?? '');
        if ($current === $value) {
            continue;
        }
        Database::execute(
            "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (:k, :v, 'comms')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            ['k' => $key, 'v' => $value]
        );
        $old[$key] = $current;
        $new[$key] = $value;
        $changed[] = $key;
    }
    if ($changed) {
        comms_settings_reset();
        comms_audit('settings.changed', 'Communication settings changed', ['actor_id' => $actorId, 'subject_type' => 'settings', 'old' => $old, 'new' => $new]);
    }
    return $changed;
}
