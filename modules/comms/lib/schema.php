<?php
/**
 * Versioned, additive migrations + permission registration for Communication.
 * Same self-healing pattern as Attendance: skipped once the version is recorded; a MySQL named lock
 * keeps two simultaneous first requests from racing. Nothing here touches an existing table.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** action => label. Each is independent: holding one never grants another. */
function comms_permission_catalogue(): array
{
    return [
        'access'              => 'Open the Communication workspace',
        'chat_private'        => 'Start private (one-to-one) chats',
        'group_create'        => 'Create group chats',
        'group_manage'        => 'Manage group members, name, photo and announcements',
        'send_message'        => 'Send chat messages',
        'send_file'           => 'Send files in chats',
        'send_voice'          => 'Send voice messages',
        'message_moderate'    => 'Edit or delete other users\' messages after the time limit',
        'chat_content_access' => 'Read private chat content (audit / investigation)',
        'task_create'         => 'Create tasks',
        'task_assign'         => 'Assign tasks to other employees',
        'task_self_assign'    => 'Assign tasks to oneself',
        'task_edit'           => 'Edit assigned tasks',
        'task_cancel'         => 'Cancel tasks',
        'task_progress'       => 'Update task progress and checklists',
        'template_manage'     => 'Create, edit and delete task templates',
        'task_submit'         => 'Submit task completion with evidence',
        'evidence_view'       => 'View task completion evidence',
        'task_review'         => 'Review, approve or reject task submissions',
        'reviewer_reassign'   => 'Reassign task reviewers',
        'deadline_extend'     => 'Approve deadline extensions and manage reminders',
        'task_reopen'         => 'Reopen completed tasks',
        'task_archive'        => 'Archive tasks',
        'task_restore'        => 'Restore archived tasks',
        'reports'             => 'View Communication and task reports',
        'export'              => 'Export task reports (CSV / Excel)',
        'audit_view'          => 'View the Communication audit log',
        'settings'            => 'Manage Communication settings and storage',
    ];
}

/** @return string[] */
function comms_migration_files(): array
{
    $files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
    usort($files, static fn(string $a, string $b): int => strcmp(basename($a), basename($b)));
    return $files;
}

/** Apply any migration not yet recorded. @return string[] file names applied */
function comms_run_migrations(): array
{
    $pdo = Database::pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `comms_migrations` (
        `filename` VARCHAR(120) NOT NULL, `checksum` CHAR(32) NOT NULL, `applied_at` DATETIME NOT NULL,
        PRIMARY KEY (`filename`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $applied = [];
    foreach (comms_migration_files() as $file) {
        $name = basename($file);
        $sql = (string)file_get_contents($file);
        $sum = md5($sql);
        $done = Database::fetch('SELECT checksum FROM comms_migrations WHERE filename = :f', ['f' => $name]);
        if ($done !== null) {
            if ($done['checksum'] !== $sum) {
                log_event('comms', 'Migration file changed after it was applied', ['file' => $name]);
            }
            continue;
        }
        foreach (preg_split('/^-- @@\s*$/m', $sql) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
        Database::execute('INSERT INTO comms_migrations (filename, checksum, applied_at) VALUES (:f, :c, :t)
                           ON DUPLICATE KEY UPDATE checksum = VALUES(checksum)', ['f' => $name, 'c' => $sum, 't' => gmdate('Y-m-d H:i:s')]);
        $applied[] = $name;
    }
    return $applied;
}

function comms_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (setting('comms_schema_version') === COMMS_SCHEMA_VERSION . '/' . COMMS_VERSION) {
        return;
    }
    $lock = (string)Database::fetchValue("SELECT GET_LOCK('swms_comms_schema', 15)");
    try {
        comms_run_migrations();
        foreach (comms_permission_catalogue() as $action => $label) {
            Permission::register('comms', $action, $label);
        }
        Database::execute("INSERT INTO modules (slug, name, version) VALUES ('comms', 'Communication & Tasks', :v)
                           ON DUPLICATE KEY UPDATE version = VALUES(version)", ['v' => COMMS_VERSION]);
        Database::execute("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('comms_schema_version', :v, 'comms')
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", ['v' => COMMS_SCHEMA_VERSION . '/' . COMMS_VERSION]);
    } catch (Throwable $e) {
        log_event('comms', 'Schema setup failed', ['error' => $e->getMessage()]);
        if (APP_ENV === 'development') {
            throw $e;
        }
    } finally {
        if ($lock === '1') {
            Database::query("SELECT RELEASE_LOCK('swms_comms_schema')");
        }
    }
}
