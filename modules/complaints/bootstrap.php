<?php
/**
 * Complaints Management module (Phase 4) — shared bootstrap.
 *
 * Everything here builds on Phases 1–3 and creates NO duplicates of them:
 *   - users / roles / permissions  → the existing authorization system
 *   - regions → districts → tehsils → ucs → beats → the Phase 2 structure
 *   - employees + employee_assignments → the Phase 3 HR data, which is how
 *     a complaint's UC finds its Supervisor and Monitoring Officer(s)
 *
 * Business data (sources, types, priorities, SLA timings, numbering format,
 * which HR types count as Supervisor / Monitoring Officer, PPT designs) is
 * stored in the database and managed from the module's Configuration page.
 * The only rows seeded are the seven workflow STATES (the engine's own
 * vocabulary) — their labels and colours stay editable.
 *
 * Schema is applied additively on first load (cmp_ensure_schema), the same
 * pattern the HR module uses, so an installed system is upgraded in place.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const CMP_MODULE_VERSION = '1.0.0';

/** Private storage (storage/ is denied to the web server). */
const CMP_STORAGE_DIR  = STORAGE_PATH . '/complaints';
const CMP_TEMPLATE_DIR = STORAGE_PATH . '/ppt-templates';
const CMP_TMP_DIR      = STORAGE_PATH . '/tmp';

/** Workflow state slugs — the engine's fixed vocabulary. */
const CMP_ST_NEW = 'new';
const CMP_ST_ASSIGNED = 'assigned';
const CMP_ST_PROGRESS = 'in_progress';
const CMP_ST_READY = 'ready';
const CMP_ST_RESOLVED = 'resolved';
const CMP_ST_REOPENED = 'reopened';
const CMP_ST_REJECTED = 'rejected';

function cmp_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (setting('cmp_schema_version') === CMP_MODULE_VERSION) {
        return;
    }
    $failed = false;
    $pdo = Database::pdo();
    $ENG = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $lookup = static fn(string $table, string $extra = ''): string =>
        "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(120) NOT NULL,
            `description` VARCHAR(255) NULL,
            {$extra}
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_{$table}_name` (`name`)
        ) {$ENG}";

    $statements = [
        $lookup('complaint_sources'),
        $lookup('complaint_types'),
        $lookup('complaint_priorities', "`color` VARCHAR(9) NOT NULL DEFAULT '#17a06a',"),

        "CREATE TABLE IF NOT EXISTS `complaint_statuses` (
            `slug` VARCHAR(30) NOT NULL, `label` VARCHAR(60) NOT NULL, `color` VARCHAR(9) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0, PRIMARY KEY (`slug`)
        ) {$ENG}",

        "CREATE TABLE IF NOT EXISTS `complaint_sla_rules` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `priority_id` INT UNSIGNED NOT NULL,
            `due_hours` INT UNSIGNED NOT NULL,
            `warning_percent` TINYINT UNSIGNED NOT NULL DEFAULT 25,
            `start_event` ENUM('created','assigned') NOT NULL DEFAULT 'created',
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `updated_by` INT UNSIGNED NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_sla_priority` (`priority_id`),
            CONSTRAINT `fk_sla_priority` FOREIGN KEY (`priority_id`) REFERENCES `complaint_priorities` (`id`) ON DELETE CASCADE
        ) {$ENG}",

        "CREATE TABLE IF NOT EXISTS `complaint_sequences` (
            `name` VARCHAR(40) NOT NULL, `last_value` BIGINT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (`name`)
        ) {$ENG}",

        "CREATE TABLE IF NOT EXISTS `complaints` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `complaint_number` VARCHAR(40) NOT NULL,
            `complainant_name` VARCHAR(150) NOT NULL,
            `complainant_address` VARCHAR(255) NULL,
            `phone` VARCHAR(30) NULL,
            `source_id` INT UNSIGNED NOT NULL,
            `type_id` INT UNSIGNED NOT NULL,
            `priority_id` INT UNSIGNED NOT NULL,
            `description` TEXT NOT NULL,
            `complaint_date` DATE NOT NULL,
            `complaint_time` TIME NULL,
            `region_id` INT UNSIGNED NOT NULL,
            `district_id` INT UNSIGNED NOT NULL,
            `tehsil_id` INT UNSIGNED NOT NULL,
            `uc_id` INT UNSIGNED NOT NULL,
            `beat_id` INT UNSIGNED NULL,
            `location_address` VARCHAR(255) NULL,
            `latitude` DECIMAL(10,7) NULL,
            `longitude` DECIMAL(10,7) NULL,
            `status` VARCHAR(30) NOT NULL DEFAULT 'new',
            `assigned_at` DATETIME NULL, `started_at` DATETIME NULL, `ready_at` DATETIME NULL,
            `resolved_at` DATETIME NULL, `rejected_at` DATETIME NULL,
            `sla_start_at` DATETIME NULL, `sla_warn_at` DATETIME NULL, `sla_due_at` DATETIME NULL,
            `created_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
            `deleted_at` DATETIME NULL, `deleted_by` INT UNSIGNED NULL, `delete_reason` VARCHAR(255) NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_complaint_number` (`complaint_number`),
            KEY `idx_complaint_date` (`complaint_date`), KEY `idx_complaint_phone` (`phone`),
            KEY `idx_complaint_status` (`status`), KEY `idx_complaint_source` (`source_id`),
            KEY `idx_complaint_type` (`type_id`), KEY `idx_complaint_priority` (`priority_id`),
            KEY `idx_complaint_region` (`region_id`), KEY `idx_complaint_district` (`district_id`),
            KEY `idx_complaint_tehsil` (`tehsil_id`), KEY `idx_complaint_uc` (`uc_id`, `status`),
            KEY `idx_complaint_beat` (`beat_id`), KEY `idx_complaint_deleted` (`deleted_at`),
            KEY `idx_complaint_created` (`created_at`), KEY `idx_complaint_sla` (`sla_due_at`),
            KEY `idx_complaint_creator` (`created_by`),
            CONSTRAINT `fk_complaint_source` FOREIGN KEY (`source_id`) REFERENCES `complaint_sources` (`id`),
            CONSTRAINT `fk_complaint_type` FOREIGN KEY (`type_id`) REFERENCES `complaint_types` (`id`),
            CONSTRAINT `fk_complaint_priority` FOREIGN KEY (`priority_id`) REFERENCES `complaint_priorities` (`id`),
            CONSTRAINT `fk_complaint_status` FOREIGN KEY (`status`) REFERENCES `complaint_statuses` (`slug`),
            CONSTRAINT `fk_complaint_region` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`),
            CONSTRAINT `fk_complaint_district` FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`),
            CONSTRAINT `fk_complaint_tehsil` FOREIGN KEY (`tehsil_id`) REFERENCES `tehsils` (`id`),
            CONSTRAINT `fk_complaint_uc` FOREIGN KEY (`uc_id`) REFERENCES `ucs` (`id`),
            CONSTRAINT `fk_complaint_beat` FOREIGN KEY (`beat_id`) REFERENCES `beats` (`id`),
            CONSTRAINT `fk_complaint_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) {$ENG}",

        "CREATE TABLE IF NOT EXISTS `complaint_assignments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `complaint_id` INT UNSIGNED NOT NULL,
            `uc_id` INT UNSIGNED NOT NULL, `beat_id` INT UNSIGNED NULL,
            `assigned_by` INT UNSIGNED NULL, `assigned_at` DATETIME NOT NULL, `ended_at` DATETIME NULL,
            `reason` VARCHAR(255) NULL, `staff_snapshot` TEXT NULL,
            PRIMARY KEY (`id`), KEY `idx_cassign_complaint` (`complaint_id`, `ended_at`), KEY `idx_cassign_uc` (`uc_id`),
            CONSTRAINT `fk_cassign_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_cassign_uc` FOREIGN KEY (`uc_id`) REFERENCES `ucs` (`id`)
        ) {$ENG}",

        /* One table for both galleries: (complaint_id, kind, sort_order) keeps
           the Before and After sequences fully independent. */
        "CREATE TABLE IF NOT EXISTS `complaint_evidence` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `complaint_id` INT UNSIGNED NOT NULL,
            `kind` ENUM('before','after') NOT NULL, `sort_order` INT UNSIGNED NOT NULL,
            `file_name` VARCHAR(120) NOT NULL, `thumb_name` VARCHAR(120) NOT NULL, `preview_name` VARCHAR(120) NOT NULL,
            `original_name` VARCHAR(190) NOT NULL, `mime_type` VARCHAR(60) NOT NULL,
            `file_size` INT UNSIGNED NOT NULL DEFAULT 0, `width` INT UNSIGNED NULL, `height` INT UNSIGNED NULL,
            `uploaded_by` INT UNSIGNED NULL, `uploaded_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), KEY `idx_evidence_order` (`complaint_id`, `kind`, `sort_order`),
            CONSTRAINT `fk_evidence_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE
        ) {$ENG}",

        "CREATE TABLE IF NOT EXISTS `complaint_attachments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `complaint_id` INT UNSIGNED NOT NULL,
            `file_name` VARCHAR(120) NOT NULL, `original_name` VARCHAR(190) NOT NULL, `mime_type` VARCHAR(100) NOT NULL,
            `file_size` INT UNSIGNED NOT NULL DEFAULT 0, `uploaded_by` INT UNSIGNED NULL, `uploaded_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), KEY `idx_cattach_complaint` (`complaint_id`),
            CONSTRAINT `fk_cattach_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE
        ) {$ENG}",

        /* Append-only audit trail: no code path updates or deletes a row. */
        "CREATE TABLE IF NOT EXISTS `complaint_timeline` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `complaint_id` INT UNSIGNED NOT NULL,
            `action` VARCHAR(60) NOT NULL, `title` VARCHAR(190) NOT NULL,
            `old_value` VARCHAR(190) NULL, `new_value` VARCHAR(190) NULL, `remarks` TEXT NULL,
            `user_id` INT UNSIGNED NULL, `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), KEY `idx_ctimeline_complaint` (`complaint_id`, `created_at`),
            CONSTRAINT `fk_ctimeline_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE
        ) {$ENG}",

        "CREATE TABLE IF NOT EXISTS `complaint_resolution` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `complaint_id` INT UNSIGNED NOT NULL,
            `cycle_no` INT UNSIGNED NOT NULL DEFAULT 1, `resolved_by` INT UNSIGNED NULL, `resolved_at` DATETIME NOT NULL,
            `remarks` TEXT NOT NULL, `state` ENUM('active','reopened') NOT NULL DEFAULT 'active', `reopened_at` DATETIME NULL,
            PRIMARY KEY (`id`), KEY `idx_cresolution_complaint` (`complaint_id`),
            CONSTRAINT `fk_cresolution_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE
        ) {$ENG}",

        "CREATE TABLE IF NOT EXISTS `complaint_reopen_history` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `complaint_id` INT UNSIGNED NOT NULL, `resolution_id` INT UNSIGNED NULL,
            `previous_status` VARCHAR(30) NOT NULL, `new_status` VARCHAR(30) NOT NULL, `reason` TEXT NOT NULL,
            `reopened_by` INT UNSIGNED NULL, `reopened_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), KEY `idx_creopen_complaint` (`complaint_id`),
            CONSTRAINT `fk_creopen_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE
        ) {$ENG}",

        "CREATE TABLE IF NOT EXISTS `complaint_notifications` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` INT UNSIGNED NOT NULL, `complaint_id` INT UNSIGNED NOT NULL,
            `type` VARCHAR(40) NOT NULL, `title` VARCHAR(190) NOT NULL, `message` VARCHAR(500) NOT NULL,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0, `read_at` DATETIME NULL, `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), KEY `idx_cnotif_user` (`user_id`, `is_read`, `created_at`),
            CONSTRAINT `fk_cnotif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_cnotif_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE
        ) {$ENG}",

        "CREATE TABLE IF NOT EXISTS `ppt_templates` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `name` VARCHAR(120) NOT NULL, `description` VARCHAR(255) NULL,
            `file_name` VARCHAR(120) NOT NULL, `original_name` VARCHAR(190) NOT NULL, `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
            `slide_count` INT UNSIGNED NOT NULL DEFAULT 0, `layout_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `slide_width` BIGINT UNSIGNED NULL, `slide_height` BIGINT UNSIGNED NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `uploaded_by` INT UNSIGNED NULL, `uploaded_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_ppt_template_name` (`name`)
        ) {$ENG}",
    ];

    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            $failed = true;
            log_event('complaints', 'Schema ensure failed', ['error' => $e->getMessage(), 'sql' => substr($sql, 0, 80)]);
        }
    }

    /* Workflow states: the engine's vocabulary. Labels/colours editable. */
    try {
        $pdo->exec(
            "INSERT IGNORE INTO complaint_statuses (slug, label, color, sort_order) VALUES
             ('new', 'New', '#3a7bd5', 1), ('assigned', 'Assigned', '#8e6bd1', 2),
             ('in_progress', 'In Progress', '#d9902b', 3), ('ready', 'Ready for Resolution', '#2aa6b8', 4),
             ('resolved', 'Resolved', '#17a06a', 5), ('reopened', 'Reopened', '#d6657f', 6),
             ('rejected', 'Rejected', '#8a948f', 7)"
        );
        $pdo->exec("INSERT IGNORE INTO complaint_sequences (name, last_value) VALUES ('complaint', 0)");
        $pdo->exec(
            "INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_group) VALUES
             ('complaint_number_prefix', 'CMP-', 'complaints'), ('complaint_number_padding', '6', 'complaints'),
             ('complaint_duplicate_days', '30', 'complaints')"
        );
        Database::execute(
            'INSERT INTO modules (slug, name, version) VALUES (:slug, :name, :version) ON DUPLICATE KEY UPDATE version = VALUES(version)',
            ['slug' => 'complaints', 'name' => 'Complaints Management', 'version' => CMP_MODULE_VERSION]
        );
    } catch (Throwable $e) {
        $failed = true;
        log_event('complaints', 'Seed failed', ['error' => $e->getMessage()]);
    }

    foreach (cmp_permission_definitions() as [$action, $label]) {
        try {
            Permission::register('complaint', $action, $label);
        } catch (Throwable $e) {
            $failed = true;
            log_event('complaints', 'Permission register failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    if (!$failed) {
        try {
            Database::execute(
                "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('cmp_schema_version', :v, 'complaints')
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                ['v' => CMP_MODULE_VERSION]
            );
        } catch (Throwable $e) {
            log_event('complaints', 'Could not record schema version', ['error' => $e->getMessage()]);
        }
    }
}

/** Every permission this module defines: [action, label]. */
function cmp_permission_definitions(): array
{
    return [
        ['view',            'View complaints, the complaints dashboard and search (UC scope applies to field staff)'],
        ['create',          'Create complaints'],
        ['update',          'Edit complaint information'],
        ['assign',          'Assign / reassign complaints to a UC'],
        ['evidence_before', 'Add, reorder and remove Before pictures'],
        ['evidence_after',  'Add, reorder and remove After pictures'],
        ['attach',          'Add and remove normal attachments'],
        ['remark',          'Add remarks to a complaint'],
        ['workflow',        'Field workflow: start work, mark Ready for Resolution'],
        ['resolve',         'Resolve complaints (final review) and send them back to the field'],
        ['reopen',          'Reopen resolved complaints'],
        ['reject',          'Reject complaints'],
        ['delete',          'Delete complaints (soft delete)'],
        ['restore',         'View and restore deleted complaints'],
        ['reports',         'Complaint reports: Excel / CSV / PDF export and the complaint PDF'],
        ['ppt',             'Generate complaint PowerPoint presentations'],
        ['ppt_templates',   'Manage PowerPoint templates'],
        ['config',          'Manage sources, types, priorities, SLA rules and module settings'],
    ];
}

cmp_ensure_schema();

/* Shared styles for every page of the module (CSS/JS stay in assets/). */
$extraStyles = array_values(array_unique(array_merge($extraStyles ?? [], ['users.css', 'hr.css', 'complaints.css'])));

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/workflow.php';
require_once __DIR__ . '/lib/filters.php';
require_once __DIR__ . '/lib/evidence.php';
require_once __DIR__ . '/lib/render.php';
