<?php
/**
 * Transport module — database schema, permissions and upgrade steps.
 *
 * Same self-healing pattern as modules/hr/bootstrap.php: every statement is
 * CREATE TABLE IF NOT EXISTS, the result is recorded in system_settings, and
 * ordinary page loads skip all of it once the recorded version matches. An
 * already-installed system is upgraded in place; nothing is dropped.
 *
 * Reused, NOT duplicated: employees (drivers), users, roles/permissions,
 * regions/districts/tehsils/ucs/beats, activity_log (audit), system_settings.
 *
 * No business values are inserted here. Vehicle types, statuses, ownership
 * types, document types, maintenance types and fuel types all start empty and
 * are created by authorised users (or from the optional, explicit "starter
 * data" step in Transport Configuration).
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** A simple configurable lookup table (name, description, status). */
function trn_lookup_ddl(string $table, string $extraColumns = ''): string
{
    return "CREATE TABLE IF NOT EXISTS `{$table}` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(100) NOT NULL,
        `description` VARCHAR(255) NULL,
        {$extraColumns}
        `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
        `created_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `uq_{$table}_name` (`name`), KEY `idx_{$table}_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}

/** @return string[] */
function trn_schema_statements(): array
{
    $fk = static fn(string $n): string => "CONSTRAINT `fk_trn_{$n}` FOREIGN KEY";

    return [
        trn_lookup_ddl('vehicle_types'),
        /* `category` is SYSTEM LOGIC (it drives dashboard cards and which
           actions are allowed), never a business value: the names are free. */
        trn_lookup_ddl('vehicle_statuses',
            "`category` ENUM('active','maintenance','out_of_service','inactive') NOT NULL DEFAULT 'active',
             `is_default` TINYINT(1) NOT NULL DEFAULT 0,"),
        trn_lookup_ddl('vehicle_ownership_types'),
        trn_lookup_ddl('vehicle_document_types',
            "`is_required` TINYINT(1) NOT NULL DEFAULT 0,
             `requires_expiry` TINYINT(1) NOT NULL DEFAULT 0,
             `warning_days` SMALLINT UNSIGNED NULL,"),
        trn_lookup_ddl('maintenance_types'),
        trn_lookup_ddl('fuel_types'),

        "CREATE TABLE IF NOT EXISTS `vehicles` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `registration_no` VARCHAR(40) NOT NULL,
            `registration_key` VARCHAR(40) NULL,
            `vehicle_type_id` INT UNSIGNED NOT NULL,
            `make` VARCHAR(80) NULL, `model` VARCHAR(80) NULL,
            `manufacture_year` SMALLINT UNSIGNED NULL, `color` VARCHAR(40) NULL,
            `picture_file` VARCHAR(190) NULL,
            `status_id` INT UNSIGNED NOT NULL,
            `ownership_type_id` INT UNSIGNED NOT NULL,
            `chassis_no` VARCHAR(60) NULL, `engine_no` VARCHAR(60) NULL,
            `tracker_no` VARCHAR(60) NULL, `tracker_key` VARCHAR(60) NULL,
            `rfid_no` VARCHAR(60) NULL, `rfid_key` VARCHAR(60) NULL,
            `remarks` VARCHAR(500) NULL,
            `deleted_at` DATETIME NULL, `deleted_by` INT UNSIGNED NULL, `delete_reason` VARCHAR(255) NULL,
            `created_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_vehicle_regkey` (`registration_key`),
            UNIQUE KEY `uq_vehicle_trackerkey` (`tracker_key`),
            UNIQUE KEY `uq_vehicle_rfidkey` (`rfid_key`),
            KEY `idx_vehicle_type` (`vehicle_type_id`), KEY `idx_vehicle_status` (`status_id`),
            KEY `idx_vehicle_owner` (`ownership_type_id`), KEY `idx_vehicle_deleted` (`deleted_at`),
            KEY `idx_vehicle_chassis` (`chassis_no`), KEY `idx_vehicle_engine` (`engine_no`),
            {$fk('v_type')} (`vehicle_type_id`) REFERENCES `vehicle_types` (`id`) ON DELETE RESTRICT,
            {$fk('v_status')} (`status_id`) REFERENCES `vehicle_statuses` (`id`) ON DELETE RESTRICT,
            {$fk('v_owner')} (`ownership_type_id`) REFERENCES `vehicle_ownership_types` (`id`) ON DELETE RESTRICT,
            {$fk('v_cby')} (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            {$fk('v_uby')} (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            {$fk('v_dby')} (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* Registration / tracker / RFID change history. The unique keys on
           `vehicles` only hold the CURRENT value, so a removed tracker can be
           fitted to another vehicle while the old link stays on record. */
        "CREATE TABLE IF NOT EXISTS `vehicle_identifier_history` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vehicle_id` INT UNSIGNED NOT NULL,
            `kind` ENUM('registration','tracker','rfid') NOT NULL,
            `action` ENUM('added','changed','removed') NOT NULL,
            `old_value` VARCHAR(60) NULL, `new_value` VARCHAR(60) NULL,
            `reason` VARCHAR(255) NULL,
            `changed_by` INT UNSIGNED NULL,
            `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), KEY `idx_vih_vehicle` (`vehicle_id`, `kind`),
            {$fk('vih_v')} (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE RESTRICT,
            {$fk('vih_u')} (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* One CURRENT document per vehicle + type (current_flag = 1); replaced
           and deleted rows keep their file and stay in Document History. */
        "CREATE TABLE IF NOT EXISTS `vehicle_documents` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vehicle_id` INT UNSIGNED NOT NULL,
            `document_type_id` INT UNSIGNED NOT NULL,
            `file_name` VARCHAR(190) NOT NULL, `original_name` VARCHAR(190) NOT NULL,
            `mime_type` VARCHAR(100) NULL, `file_size` INT UNSIGNED NULL,
            `issue_date` DATE NULL, `expiry_date` DATE NULL,
            `remarks` VARCHAR(500) NULL,
            `state` ENUM('current','replaced','deleted') NOT NULL DEFAULT 'current',
            `current_flag` TINYINT NULL,
            `uploaded_by` INT UNSIGNED NULL,
            `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_by` INT UNSIGNED NULL, `updated_at` DATETIME NULL,
            `closed_at` DATETIME NULL, `closed_by` INT UNSIGNED NULL, `close_reason` VARCHAR(255) NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_vdoc_current` (`vehicle_id`, `document_type_id`, `current_flag`),
            KEY `idx_vdoc_type` (`document_type_id`), KEY `idx_vdoc_expiry` (`expiry_date`), KEY `idx_vdoc_state` (`state`),
            {$fk('vd_v')} (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE RESTRICT,
            {$fk('vd_t')} (`document_type_id`) REFERENCES `vehicle_document_types` (`id`) ON DELETE RESTRICT,
            {$fk('vd_u')} (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* open_flag = 1 while the row is open, NULL once closed. UNIQUE
           (vehicle_id, open_flag) makes "one current driver per vehicle" a
           database guarantee, not just an application check. */
        "CREATE TABLE IF NOT EXISTS `vehicle_driver_assignments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vehicle_id` INT UNSIGNED NOT NULL,
            `employee_id` INT UNSIGNED NOT NULL,
            `assigned_date` DATE NOT NULL, `end_date` DATE NULL,
            `open_flag` TINYINT NULL,
            `remarks` VARCHAR(500) NULL, `end_reason` VARCHAR(255) NULL,
            `assigned_by` INT UNSIGNED NULL, `ended_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_vda_open` (`vehicle_id`, `open_flag`),
            KEY `idx_vda_employee` (`employee_id`, `open_flag`), KEY `idx_vda_dates` (`assigned_date`, `end_date`),
            {$fk('vda_v')} (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE RESTRICT,
            {$fk('vda_e')} (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
            {$fk('vda_a')} (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            {$fk('vda_n')} (`ended_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `vehicle_deployments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vehicle_id` INT UNSIGNED NOT NULL,
            `region_id` INT UNSIGNED NOT NULL, `district_id` INT UNSIGNED NOT NULL,
            `tehsil_id` INT UNSIGNED NOT NULL, `uc_id` INT UNSIGNED NOT NULL,
            `beat_id` INT UNSIGNED NULL,
            `start_date` DATE NOT NULL, `end_date` DATE NULL,
            `open_flag` TINYINT NULL,
            `remarks` VARCHAR(500) NULL, `end_reason` VARCHAR(255) NULL,
            `assigned_by` INT UNSIGNED NULL, `ended_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_vdp_open` (`vehicle_id`, `open_flag`),
            KEY `idx_vdp_uc` (`uc_id`, `open_flag`), KEY `idx_vdp_region` (`region_id`), KEY `idx_vdp_district` (`district_id`),
            KEY `idx_vdp_tehsil` (`tehsil_id`), KEY `idx_vdp_beat` (`beat_id`), KEY `idx_vdp_dates` (`start_date`, `end_date`),
            {$fk('vdp_v')} (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE RESTRICT,
            {$fk('vdp_r')} (`region_id`) REFERENCES `regions` (`id`) ON DELETE RESTRICT,
            {$fk('vdp_d')} (`district_id`) REFERENCES `districts` (`id`) ON DELETE RESTRICT,
            {$fk('vdp_t')} (`tehsil_id`) REFERENCES `tehsils` (`id`) ON DELETE RESTRICT,
            {$fk('vdp_c')} (`uc_id`) REFERENCES `ucs` (`id`) ON DELETE RESTRICT,
            {$fk('vdp_b')} (`beat_id`) REFERENCES `beats` (`id`) ON DELETE RESTRICT,
            {$fk('vdp_a')} (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            {$fk('vdp_n')} (`ended_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `vehicle_maintenance` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vehicle_id` INT UNSIGNED NOT NULL,
            `maintenance_date` DATE NOT NULL,
            `maintenance_type_id` INT UNSIGNED NOT NULL,
            `description` VARCHAR(1000) NOT NULL,
            `workshop` VARCHAR(150) NULL,
            `odometer` DECIMAL(10,1) UNSIGNED NULL,
            `cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `next_maintenance_date` DATE NULL,
            `invoice_file` VARCHAR(190) NULL, `invoice_original` VARCHAR(190) NULL,
            `invoice_mime` VARCHAR(100) NULL, `invoice_size` INT UNSIGNED NULL,
            `remarks` VARCHAR(500) NULL,
            `added_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME NULL, `deleted_by` INT UNSIGNED NULL,
            PRIMARY KEY (`id`),
            KEY `idx_vm_vehicle` (`vehicle_id`, `maintenance_date`), KEY `idx_vm_type` (`maintenance_type_id`),
            KEY `idx_vm_date` (`maintenance_date`), KEY `idx_vm_deleted` (`deleted_at`),
            {$fk('vm_v')} (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE RESTRICT,
            {$fk('vm_t')} (`maintenance_type_id`) REFERENCES `maintenance_types` (`id`) ON DELETE RESTRICT,
            {$fk('vm_a')} (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `vehicle_fuel_records` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `vehicle_id` INT UNSIGNED NOT NULL,
            `driver_id` INT UNSIGNED NULL,
            `fuel_date` DATE NOT NULL,
            `fuel_type_id` INT UNSIGNED NOT NULL,
            `quantity` DECIMAL(10,2) UNSIGNED NOT NULL,
            `rate` DECIMAL(10,2) UNSIGNED NOT NULL,
            `total_cost` DECIMAL(12,2) UNSIGNED NOT NULL,
            `odometer` DECIMAL(10,1) UNSIGNED NULL,
            `is_full_tank` TINYINT(1) NOT NULL DEFAULT 1,
            `station` VARCHAR(150) NULL,
            `receipt_file` VARCHAR(190) NULL, `receipt_original` VARCHAR(190) NULL,
            `receipt_mime` VARCHAR(100) NULL, `receipt_size` INT UNSIGNED NULL,
            `remarks` VARCHAR(500) NULL,
            `added_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME NULL, `deleted_by` INT UNSIGNED NULL,
            PRIMARY KEY (`id`),
            KEY `idx_vf_vehicle` (`vehicle_id`, `fuel_date`), KEY `idx_vf_driver` (`driver_id`),
            KEY `idx_vf_type` (`fuel_type_id`), KEY `idx_vf_date` (`fuel_date`), KEY `idx_vf_deleted` (`deleted_at`),
            {$fk('vf_v')} (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE RESTRICT,
            {$fk('vf_d')} (`driver_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
            {$fk('vf_t')} (`fuel_type_id`) REFERENCES `fuel_types` (`id`) ON DELETE RESTRICT,
            {$fk('vf_a')} (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        /* The shared activity_log has no room for old/new values or a reason,
           so those live here, keyed to the activity_log row. One audit trail,
           one extra detail table — not a second audit system. */
        "CREATE TABLE IF NOT EXISTS `transport_audit_changes` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `activity_log_id` BIGINT UNSIGNED NOT NULL,
            `vehicle_id` INT UNSIGNED NULL,
            `old_value` MEDIUMTEXT NULL, `new_value` MEDIUMTEXT NULL,
            `reason` VARCHAR(255) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_tac_log` (`activity_log_id`), KEY `idx_tac_vehicle` (`vehicle_id`),
            {$fk('tac_l')} (`activity_log_id`) REFERENCES `activity_log` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/** Permissions this module registers: [action, label]. Module key = "transport". */
function trn_permission_catalogue(): array
{
    return [
        ['dashboard',          'View Transport Dashboard'],
        ['view',               'View Vehicles'],
        ['details',            'View Vehicle Details'],
        ['create',             'Add Vehicle'],
        ['update',             'Edit Vehicle'],
        ['status',             'Change Vehicle Status'],
        ['identifiers',        'Change Vehicle Tracker / RFID'],
        ['archive',            'Archive / Restore Vehicles'],
        ['types_view',         'View Vehicle Types'],
        ['types_manage',       'Manage Vehicle Types'],
        ['documents_view',     'View Vehicle Documents'],
        ['documents_download', 'Download Vehicle Documents'],
        ['documents_manage',   'Manage Vehicle Documents'],
        ['driver_view',        'View Driver Assignments'],
        ['driver_assign',      'Assign Driver'],
        ['deployment_view',    'View Vehicle Deployment'],
        ['deployment_manage',  'Manage Vehicle Deployment'],
        ['maintenance_view',   'View Maintenance'],
        ['maintenance_manage', 'Manage Maintenance'],
        ['fuel_view',          'View Fuel Records'],
        ['fuel_manage',        'Manage Fuel'],
        ['history',            'View Assignment History'],
        ['audit',              'View Transport Audit Trail'],
        ['reports',            'View Transport Reports'],
        ['reports_export',     'Export Transport Reports'],
        ['config',             'Manage Transport Configuration'],
    ];
}

function trn_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (setting('transport_schema_version') === TRN_MODULE_VERSION) {
        return;
    }

    $failed = false;
    $pdo = Database::pdo();

    /* Phase 3 (HR) tables are prerequisites; hr/bootstrap.php has already
       created them by the time this runs (see transport bootstrap). */
    foreach (trn_schema_statements() as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            $failed = true;
            log_event('transport', 'Schema ensure failed', ['error' => $e->getMessage(), 'sql' => mb_substr($sql, 0, 120)]);
        }
    }

    foreach (trn_permission_catalogue() as [$action, $label]) {
        try {
            Permission::register('transport', $action, $label);
        } catch (Throwable $e) {
            $failed = true;
            log_event('transport', 'Permission register failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    try {
        Database::execute(
            'INSERT INTO modules (slug, name, version) VALUES (:slug, :name, :version)
             ON DUPLICATE KEY UPDATE version = VALUES(version)',
            ['slug' => 'transport', 'name' => 'Transport', 'version' => TRN_MODULE_VERSION]
        );
    } catch (Throwable $e) {
        $failed = true;
        log_event('transport', 'Could not register module', ['error' => $e->getMessage()]);
    }

    if (!$failed) {
        try {
            Database::execute(
                "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('transport_schema_version', :v, 'transport')
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                ['v' => TRN_MODULE_VERSION]
            );
        } catch (Throwable $e) {
            log_event('transport', 'Could not record schema version', ['error' => $e->getMessage()]);
        }
    }
}
