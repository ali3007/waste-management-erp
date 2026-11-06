<?php
/**
 * Human Resources module — shared bootstrap.
 *
 * Employees are independent HR records, not ERP login accounts — an
 * employee only gets login access if explicitly linked to a `users` row
 * (employees.user_id, nullable). Assignment (UC/Beat) lives in its own
 * table, employee_assignments, separate from the employee master, so
 * reassignment doesn't overwrite history and staff types that need
 * multiple simultaneous UCs (Monitoring Officer) aren't fighting a
 * single-value column.
 *
 * Everything the module needs lives here or in lib/: staff pools/types,
 * the employee master, secure documents (lib/uploads.php), assignments,
 * the shared filter/scope SQL (lib/filters.php), the profile and
 * employment-letter PDFs (lib/pdf.php) and the CSV importer
 * (lib/import.php). Schema changes are applied additively on first load
 * (hr_ensure_schema), so an already-installed system is upgraded in
 * place — nothing is dropped, recreated or reinstalled.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const HR_MODULE_VERSION = '2.0.0';

/** Private folder for employee documents (storage/ is denied to web access). */
const HR_DOC_DIR = STORAGE_PATH . '/employee-docs';

/** Create every table this module needs if it isn't there yet — same
    self-healing pattern as modules/structure/bootstrap.php, and for the
    same reason: this module was added to an already-installed system,
    and the installer can never be re-run to pick up new tables. */
function hr_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    /* Once every step has succeeded for this module version it is recorded
       in system_settings, so ordinary page loads skip all of the checks
       below instead of repeating ~30 queries per request. */
    if (setting('hr_schema_version') === HR_MODULE_VERSION) {
        return;
    }
    $GLOBALS['__hr_schema_failed'] = false;

    $statements = [
        "CREATE TABLE IF NOT EXISTS `hr_staff_pools` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(80) NOT NULL, `slug` VARCHAR(80) NOT NULL,
            `description` VARCHAR(255) NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_pool_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `hr_staff_types` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `staff_pool_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(80) NOT NULL, `slug` VARCHAR(80) NOT NULL,
            `description` VARCHAR(255) NULL,
            `assignment_mode` ENUM('none','single','multi') NOT NULL DEFAULT 'single',
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_type_slug` (`slug`), KEY `idx_type_pool` (`staff_pool_id`),
            CONSTRAINT `fk_type_pool` FOREIGN KEY (`staff_pool_id`) REFERENCES `hr_staff_pools` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `employees` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_code` VARCHAR(20) NULL, `user_id` INT UNSIGNED NULL,
            `name` VARCHAR(120) NOT NULL, `father_name` VARCHAR(120) NOT NULL, `cnic` CHAR(13) NOT NULL,
            `dob` DATE NULL, `address` VARCHAR(255) NULL, `phone` VARCHAR(30) NULL, `cnic_issue_date` DATE NULL,
            `region_id` INT UNSIGNED NOT NULL, `district_id` INT UNSIGNED NOT NULL, `tehsil_id` INT UNSIGNED NOT NULL,
            `staff_pool_id` INT UNSIGNED NOT NULL, `staff_type_id` INT UNSIGNED NOT NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `cnic_picture` VARCHAR(190) NULL, `employee_picture` VARCHAR(190) NULL,
            `created_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_employee_code` (`employee_code`), UNIQUE KEY `uq_employee_cnic` (`cnic`),
            UNIQUE KEY `uq_employee_user` (`user_id`), KEY `idx_employee_status` (`status`),
            KEY `idx_employee_region` (`region_id`), KEY `idx_employee_district` (`district_id`),
            KEY `idx_employee_tehsil` (`tehsil_id`), KEY `idx_employee_type` (`staff_type_id`),
            CONSTRAINT `fk_employee_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_employee_region` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_employee_district` FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_employee_tehsil` FOREIGN KEY (`tehsil_id`) REFERENCES `tehsils` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_employee_pool` FOREIGN KEY (`staff_pool_id`) REFERENCES `hr_staff_pools` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_employee_type` FOREIGN KEY (`staff_type_id`) REFERENCES `hr_staff_types` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_employee_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_employee_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `employee_documents` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `employee_id` INT UNSIGNED NOT NULL,
            `file_name` VARCHAR(190) NOT NULL, `original_name` VARCHAR(190) NOT NULL,
            `title` VARCHAR(150) NULL, `mime_type` VARCHAR(100) NULL, `file_size` INT UNSIGNED NULL,
            `uploaded_by` INT UNSIGNED NULL, `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `idx_document_employee` (`employee_id`),
            CONSTRAINT `fk_document_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `employee_assignments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `employee_id` INT UNSIGNED NOT NULL,
            `uc_id` INT UNSIGNED NOT NULL, `beat_id` INT UNSIGNED NULL,
            `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `assigned_by` INT UNSIGNED NULL,
            `unassigned_at` TIMESTAMP NULL, `unassigned_by` INT UNSIGNED NULL,
            PRIMARY KEY (`id`), KEY `idx_assignment_employee_current` (`employee_id`, `unassigned_at`),
            KEY `idx_assignment_uc` (`uc_id`), KEY `idx_assignment_beat` (`beat_id`),
            CONSTRAINT `fk_assignment_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_assignment_uc` FOREIGN KEY (`uc_id`) REFERENCES `ucs` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_assignment_beat` FOREIGN KEY (`beat_id`) REFERENCES `beats` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    $pdo = Database::pdo();
    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            $GLOBALS['__hr_schema_failed'] = true;
            log_event('hr', 'Schema ensure failed', ['error' => $e->getMessage()]);
        }
    }

    /* Seed the three pools the spec names — a starting point, not a
       fixed list. Nothing about HR Type creation is hard-coded to
       these; a Super Admin can add, rename or deactivate any of them
       from HR Types, same as everything else in this module. */
    try {
        $pdo->exec(
            "INSERT IGNORE INTO hr_staff_pools (name, slug) VALUES
             ('Office Staff', 'office-staff'),
             ('Field Staff', 'field-staff'),
             ('Transport Staff', 'transport-staff')"
        );
    } catch (Throwable $e) {
        $GLOBALS['__hr_schema_failed'] = true;
            log_event('hr', 'Staff pool seed failed', ['error' => $e->getMessage()]);
    }

    try {
        Database::execute(
            'INSERT INTO modules (slug, name, version) VALUES (:slug, :name, :version)
             ON DUPLICATE KEY UPDATE version = VALUES(version)',
            ['slug' => 'hr', 'name' => 'Human Resources', 'version' => HR_MODULE_VERSION]
        );
    } catch (Throwable $e) {
        $GLOBALS['__hr_schema_failed'] = true;
            log_event('hr', 'Could not register module', ['error' => $e->getMessage()]);
    }

    hr_migrate_schema();

    foreach (['view', 'create', 'update', 'delete'] as $action) {
        $label = match ($action) {
            'view'   => 'View employees',
            'create' => 'Add employees',
            'update' => 'Edit employees',
            'delete' => 'Activate or deactivate employees',
        };
        Permission::register('employee', $action, $label);
    }
    foreach (['view', 'create', 'update', 'delete'] as $action) {
        $label = match ($action) {
            'view'   => 'View HR types',
            'create' => 'Add HR types',
            'update' => 'Edit HR types',
            'delete' => 'Activate, deactivate or delete HR types',
        };
        Permission::register('hrtype', $action, $label);
    }
    foreach (['view', 'create', 'update'] as $action) {
        $label = match ($action) {
            'view'   => 'View employee assignments',
            'create' => 'Assign an employee to a UC/Beat',
            'update' => 'Reassign or remove an employee\'s assignment',
        };
        Permission::register('assignment', $action, $label);
    }

    /* Added with the HR dashboard / documents / reports / import release.
       Registered once; existing roles keep exactly what they had until an
       administrator ticks the new boxes in Roles & permissions. */
    $extra = [
        ['employee', 'doc_view',      'View employee documents (CNIC and other files)'],
        ['employee', 'doc_create',    'Upload employee documents'],
        ['employee', 'doc_download',  'Download employee documents'],
        ['employee', 'doc_delete',    'Delete employee documents'],
        ['employee', 'pdf',           'Generate/download the employee profile PDF'],
        ['employee', 'letter',        'Generate/download the employment letter'],
        ['hr',       'dashboard',     'View the HR dashboard'],
        ['hr',       'reports',       'View HR reports'],
        ['hr',       'reports_export', 'Export HR reports and employee lists'],
        ['hr',       'import',        'Use the employee CSV bulk import'],
    ];
    foreach ($extra as [$module, $action, $label]) {
        try {
            Permission::register($module, $action, $label);
        } catch (Throwable $e) {
            $GLOBALS['__hr_schema_failed'] = true;
            log_event('hr', 'Permission register failed', ['slug' => $module . '.' . $action, 'error' => $e->getMessage()]);
        }
    }

    if (empty($GLOBALS['__hr_schema_failed'])) {
        try {
            Database::execute(
                "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('hr_schema_version', :v, 'hr')
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                ['v' => HR_MODULE_VERSION]
            );
        } catch (Throwable $e) {
            log_event('hr', 'Could not record schema version', ['error' => $e->getMessage()]);
        }
    }
}

/**
 * Additive upgrade steps for installs that already have the Stage 1
 * tables. Each step checks information_schema first, because MySQL 5.7/8
 * has no "ADD COLUMN IF NOT EXISTS" (MariaDB does) and this has to run
 * the same on XAMPP's MariaDB and on a production MySQL.
 */
function hr_migrate_schema(): void
{
    $pdo = Database::pdo();

    $hasColumn = static function (string $table, string $column) use ($pdo): bool {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$table, $column]);
        return $st->fetchColumn() !== false;
    };
    $hasIndex = static function (string $table, string $index) use ($pdo): bool {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $st->execute([$table, $index]);
        return $st->fetchColumn() !== false;
    };

    $steps = [
        [fn() => !$hasColumn('employee_documents', 'title'),     'ALTER TABLE employee_documents ADD COLUMN `title` VARCHAR(150) NULL AFTER `original_name`'],
        [fn() => !$hasColumn('employee_documents', 'mime_type'), 'ALTER TABLE employee_documents ADD COLUMN `mime_type` VARCHAR(100) NULL AFTER `title`'],
        [fn() => !$hasColumn('employee_documents', 'file_size'), 'ALTER TABLE employee_documents ADD COLUMN `file_size` INT UNSIGNED NULL AFTER `mime_type`'],
        [fn() => !$hasIndex('employees', 'idx_employee_pool'),   'ALTER TABLE employees ADD KEY `idx_employee_pool` (`staff_pool_id`)'],
        [fn() => !$hasIndex('employee_assignments', 'idx_assignment_open'), 'ALTER TABLE employee_assignments ADD KEY `idx_assignment_open` (`unassigned_at`, `uc_id`, `beat_id`)'],
    ];
    foreach ($steps as [$needed, $sql]) {
        try {
            if ($needed()) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {
            $GLOBALS['__hr_schema_failed'] = true;
            log_event('hr', 'Schema migration step failed', ['sql' => $sql, 'error' => $e->getMessage()]);
        }
    }
}

hr_ensure_schema();

/* ------------------------------------------------------------------ *
 | Helpers                                                             |
 * ------------------------------------------------------------------ */

function hr_status_label(string $status): string
{
    return $status === 'active' ? 'Active' : 'Inactive';
}

function hr_status_badge(string $status): string
{
    return $status === 'active' ? 'badge--active' : 'badge--inactive';
}

/** Active staff pools, for the Add/Edit Employee form. */
function hr_staff_pools(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM hr_staff_pools' . ($activeOnly ? " WHERE status = 'active'" : '') . ' ORDER BY name ASC';
    return Database::fetchAll($sql);
}

/** Active staff types for one pool, or every active type if no pool given. */
function hr_staff_types(?int $poolId = null, bool $activeOnly = true): array
{
    $where = [];
    $params = [];
    if ($activeOnly) {
        $where[] = "status = 'active'";
    }
    if ($poolId !== null) {
        $where[] = 'staff_pool_id = :pool';
        $params['pool'] = $poolId;
    }
    $sql = 'SELECT * FROM hr_staff_types' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY name ASC';
    return Database::fetchAll($sql, $params);
}

/** EMP-00001 style code, generated once the row has an id. */
function hr_generate_employee_code(int $employeeId): string
{
    return 'EMP-' . str_pad((string)$employeeId, 5, '0', STR_PAD_LEFT);
}

/** Fetch one employee with its pool/type/region chain names joined in —
    every screen that lists or shows an employee needs these together. */
function hr_find_employee(int $id): ?array
{
    return Database::fetch(
        'SELECT e.*, r.name AS region_name, d.name AS district_name, t.name AS tehsil_name,
                sp.name AS staff_pool_name, st.name AS staff_type_name, st.assignment_mode
           FROM employees e
           JOIN regions r ON r.id = e.region_id
           JOIN districts d ON d.id = e.district_id
           JOIN tehsils t ON t.id = e.tehsil_id
           JOIN hr_staff_pools sp ON sp.id = e.staff_pool_id
           JOIN hr_staff_types st ON st.id = e.staff_type_id
          WHERE e.id = :id',
        ['id' => $id]
    );
}

/** True when an employee has at least one currently-open assignment row. */
function hr_is_assigned(int $employeeId): bool
{
    return Database::exists(
        'SELECT id FROM employee_assignments WHERE employee_id = :id AND unassigned_at IS NULL',
        ['id' => $employeeId]
    );
}

/** Every currently-open assignment row for one employee, UC/Beat names joined in. */
function hr_current_assignments(int $employeeId): array
{
    return Database::fetchAll(
        'SELECT a.*, u.name AS uc_name, b.name AS beat_name
           FROM employee_assignments a
           JOIN ucs u ON u.id = a.uc_id
           LEFT JOIN beats b ON b.id = a.beat_id
          WHERE a.employee_id = :id AND a.unassigned_at IS NULL
          ORDER BY a.assigned_at ASC',
        ['id' => $employeeId]
    );
}

/**
 * Area-based access control (spec section 29).
 *
 * Which UC ids the signed-in user is allowed to see HR/operational data
 * for. Returns null for "unrestricted" — Super Admin, or anyone not
 * linked to an employee record at all (their access is governed by
 * ordinary Permission grants only, untouched by this rule). Returns a
 * possibly-empty array of UC ids for a user who IS linked to an
 * employee: only those UCs are visible to them, regardless of what
 * Permission::can() would otherwise allow.
 *
 * Every list/report query that shows UC-scoped data should treat a
 * non-null return as a mandatory `WHERE uc_id IN (...)` — this is
 * enforced at the query level deliberately, so it can't be bypassed by
 * changing an id in a URL; it isn't a UI-only restriction. Built once
 * here so Attendance, Complaints and any future operational module can
 * reuse the exact same rule instead of reimplementing it.
 */
function hr_accessible_uc_ids(): ?array
{
    static $cache = false; // false = not computed yet (null is a valid answer)
    if ($cache !== false) {
        return $cache;
    }
    return $cache = hr_compute_accessible_uc_ids();
}

function hr_compute_accessible_uc_ids(): ?array
{
    if (Auth::isSuperAdmin()) {
        return null;
    }

    $user = Auth::user();
    if ($user === null) {
        return [];
    }

    $employee = Database::fetch('SELECT id FROM employees WHERE user_id = :uid', ['uid' => (int)$user['id']]);
    if ($employee === null) {
        return null;
    }

    $rows = Database::fetchAll(
        'SELECT DISTINCT uc_id FROM employee_assignments WHERE employee_id = :id AND unassigned_at IS NULL',
        ['id' => (int)$employee['id']]
    );

    return array_map('intval', array_column($rows, 'uc_id'));
}

/** Guard a single UC id against the current user's accessible scope —
    for pages that load one specific record by id (never trust the id
    alone; this is the "no bypass by changing the URL" half of the rule). */
function hr_can_access_uc(int $ucId): bool
{
    $accessible = hr_accessible_uc_ids();
    return $accessible === null || in_array($ucId, $accessible, true);
}

/**
 * Mandatory SQL fragment for area-based access control on an employees
 * query. Returns '' when the signed-in user is unrestricted, otherwise an
 * EXISTS(...) clause limiting results to employees currently assigned
 * inside the user's own UCs. The ids are cast to int and imploded (not
 * user input), so this is injection-safe by construction and can be
 * dropped into any WHERE without parameter-name collisions.
 */
function hr_scope_sql(string $employeeAlias = 'e'): string
{
    $ids = hr_accessible_uc_ids();
    if ($ids === null) {
        return '';
    }
    if (empty($ids)) {
        return '1 = 0';
    }
    return 'EXISTS (SELECT 1 FROM employee_assignments sa WHERE sa.employee_id = ' . $employeeAlias . '.id'
        . ' AND sa.unassigned_at IS NULL AND sa.uc_id IN (' . implode(',', array_map('intval', $ids)) . '))';
}

/** Is this one employee inside the signed-in user's accessible scope? */
function hr_employee_in_scope(int $employeeId): bool
{
    $scope = hr_scope_sql('e');
    if ($scope === '') {
        return true;
    }
    return Database::exists('SELECT e.id FROM employees e WHERE e.id = :id AND ' . $scope, ['id' => $employeeId]);
}

/** Stop with a 403 when the employee id in the URL/request is outside the
    user's scope — the "can't bypass by editing the id" half of the rule. */
function hr_require_employee_scope(int $employeeId): void
{
    Auth::requireLogin(); // the scope depends on WHO is signed in — never evaluate it anonymously
    if (hr_employee_in_scope($employeeId)) {
        return;
    }
    log_event('hr', 'Out-of-scope employee access blocked', ['user_id' => Auth::id(), 'employee_id' => $employeeId]);
    if (is_ajax()) {
        json_response(['ok' => false, 'message' => 'You do not have access to this record.'], 403);
    }
    http_response_code(403);
    require INCLUDES_PATH . '/components/forbidden.php';
    exit;
}

/** Write one row to the shared activity log for an HR action. */
function hr_log(string $action, int $employeeId, string $description): void
{
    try {
        Database::insert('activity_log', [
            'user_id' => Auth::id(), 'module' => 'hr', 'action' => $action,
            'subject_type' => 'employee', 'subject_id' => $employeeId,
            'description' => mb_substr($description, 0, 250), 'ip_address' => client_ip(),
        ]);
    } catch (Throwable $e) {
        log_event('hr', 'Activity log write failed', ['error' => $e->getMessage()]);
    }
}

/* Every HR page shares the toolbar/list styles from users.css plus this
   module's own stylesheet; set here once instead of on each page. */
$extraStyles = array_values(array_unique(array_merge($extraStyles ?? [], ['users.css', 'hr.css'])));

require_once __DIR__ . '/lib/uploads.php';
require_once __DIR__ . '/lib/filters.php';
require_once __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/reports.php';
require_once __DIR__ . '/lib/import.php';
