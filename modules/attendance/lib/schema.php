<?php
/**
 * Versioned, additive migrations + permission registration.
 *
 * Migration files live in modules/attendance/migrations/NNN_name.sql and are
 * applied once each (recorded with a checksum in attendance_migrations). Every
 * statement is CREATE TABLE IF NOT EXISTS, so running them on a database that
 * already has some of the tables is safe and destroys nothing. The same runner
 * is available from the command line: php modules/attendance/cron/migrate.php
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** Permission catalogue: slug action => label. Registered through Permission::register(). */
function att_permission_catalogue(): array
{
    return [
        'access'            => 'Open the Attendance module',
        'mark_self'         => 'Mark own Check-in / Check-out',
        'mark_team'         => 'Mark Check-in / Check-out for assigned Field and Transport staff',
        'history_view'      => 'View attendance history',
        'dashboard'         => 'View the attendance dashboard',
        'reports'           => 'View attendance reports',
        'export'            => 'Export attendance reports (Excel / PDF)',
        'timing_view'       => 'View attendance timing settings',
        'timing_manage'     => 'Manage attendance timing settings (all categories and the company default)',
        'edit'              => 'Correct attendance records (Attendance Edit)',
        'review'            => 'Review Pending Review / Incomplete attendance',
        'leave_view'        => 'View leave requests',
        'leave_submit_self' => 'Submit own leave requests',
        'leave_submit_proxy'=> 'Submit leave requests on behalf of assigned staff',
        'leave_approve'     => 'Approve or reject leave requests',
        'holidays_manage'   => 'Manage the Office Staff holiday calendar',
        'audit'             => 'View attendance audit logs',
        'config'            => 'Configure attendance categories and the scheduler',
    ];
}

/** @return string[] migration file paths, in order */
function att_migration_files(): array
{
    $files = array_merge(glob(__DIR__ . '/../migrations/*.sql') ?: [], glob(__DIR__ . '/../migrations/*.php') ?: []);
    usort($files, static fn(string $a, string $b): int => strcmp(basename($a), basename($b)));
    return $files;
}

/** Apply any migration not yet recorded. Returns the file names applied. */
function att_run_migrations(): array
{
    $pdo = Database::pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `attendance_migrations` (
        `filename` VARCHAR(120) NOT NULL, `checksum` CHAR(32) NOT NULL, `applied_at` DATETIME NOT NULL,
        PRIMARY KEY (`filename`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $applied = [];
    foreach (att_migration_files() as $file) {
        $name = basename($file);
        $sql = (string)file_get_contents($file);
        $sum = md5($sql);
        $done = Database::fetch('SELECT checksum FROM attendance_migrations WHERE filename = :f', ['f' => $name]);
        if ($done !== null) {
            if ($done['checksum'] !== $sum) {
                log_event('attendance', 'Migration file changed after it was applied', ['file' => $name]);
            }
            continue;
        }
        if (str_ends_with($file, '.php')) {
            $run = require $file;          // a closure(PDO) that checks information_schema before each change
            $run($pdo);
        } else {
            foreach (preg_split('/^-- @@\s*$/m', $sql) ?: [] as $statement) {
                if (trim($statement) !== '') {
                    $pdo->exec($statement);   // DDL auto-commits in MySQL; each statement is idempotent
                }
            }
        }
        Database::execute('INSERT INTO attendance_migrations (filename, checksum, applied_at) VALUES (:f, :c, :t)
                           ON DUPLICATE KEY UPDATE checksum = VALUES(checksum)', ['f' => $name, 'c' => $sum, 't' => att_now_sql()]);
        $applied[] = $name;
    }
    return $applied;
}

/** Self-healing install, same pattern as HR/Transport: skipped once the version is recorded. */
function att_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (setting('attendance_schema_version') === ATT_SCHEMA_VERSION . '/' . ATT_VERSION) {
        return;
    }
    $lock = (string)Database::fetchValue("SELECT GET_LOCK('swms_attendance_schema', 15)");
    try {
        att_run_migrations();
        foreach (att_permission_catalogue() as $action => $label) {
            Permission::register('attendance', $action, $label);
        }
        Database::execute("INSERT INTO modules (slug, name, version) VALUES ('attendance', 'Attendance', :v)
                           ON DUPLICATE KEY UPDATE version = VALUES(version)", ['v' => ATT_VERSION]);
        att_save_setting('attendance_schema_version', ATT_SCHEMA_VERSION . '/' . ATT_VERSION);
    } catch (Throwable $e) {
        log_event('attendance', 'Schema setup failed', ['error' => $e->getMessage()]);
        if (APP_ENV === 'development') {
            throw $e;
        }
    } finally {
        if ($lock === '1') {
            Database::query("SELECT RELEASE_LOCK('swms_attendance_schema')");
        }
    }
}

/**
 * One "manage timing" permission per staff pool, so a role can be allowed to manage a single
 * category's timing (Office, Field or Transport) through the normal Roles screen. Pools are data,
 * so this re-registers when the pool list changes (cheap signature check, not per-request writes).
 */
function att_sync_pool_permissions(): void
{
    try {
        $pools = Database::fetchAll('SELECT id, name, slug FROM hr_staff_pools ORDER BY id');
        $sig = md5((string)json_encode($pools));
        if (att_setting('attendance_pool_perm_sig') === $sig) {
            return;
        }
        foreach ($pools as $p) {
            Permission::register('attendance', substr(att_timing_pool_permission($p), strlen('attendance.')), 'Manage ' . $p['name'] . ' attendance timing');
        }
        att_save_setting('attendance_pool_perm_sig', $sig);
    } catch (Throwable $e) {
        log_event('attendance', 'Pool permission sync failed', ['error' => $e->getMessage()]);
    }
}
