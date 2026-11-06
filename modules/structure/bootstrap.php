<?php
/**
 * Administrative Structure module — shared bootstrap.
 *
 * Region > District > Tehsil > UC, with Beats and Sites as parallel
 * children of UC. Every page in this module includes this file right
 * after config.php.
 *
 * STAGE 1 of this module is live: Region, District, Tehsil, UC — full
 * create/edit/activate/deactivate, one shared interface. Beat, Site and
 * UC KML/KMZ tables already exist (created below) so later stages need
 * no further migration; only their screens are still to come.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const STRUCTURE_MODULE_VERSION = '1.0.0';

/**
 * The whole hierarchy in one place. Adding a level here — as Beat and
 * Site will be in a later stage — is the only change node-save.php,
 * node-status.php and index.php need to support it.
 */
const STRUCTURE_LEVELS = [
    'region' => [
        'table' => 'regions', 'label' => 'Region', 'plural' => 'Regions',
        'parent_level' => null, 'parent_column' => null,
        'code_label' => 'Region Code',
    ],
    'district' => [
        'table' => 'districts', 'label' => 'District', 'plural' => 'Districts',
        'parent_level' => 'region', 'parent_column' => 'region_id',
        'code_label' => 'District Code',
    ],
    'tehsil' => [
        'table' => 'tehsils', 'label' => 'Tehsil', 'plural' => 'Tehsils',
        'parent_level' => 'district', 'parent_column' => 'district_id',
        'code_label' => 'Tehsil Code',
    ],
    'uc' => [
        'table' => 'ucs', 'label' => 'UC', 'plural' => 'UCs',
        'parent_level' => 'tehsil', 'parent_column' => 'tehsil_id',
        'code_label' => 'UC Code', 'has_uc_number' => true,
    ],
    'beat' => [
        'table' => 'beats', 'label' => 'Beat', 'plural' => 'Beats',
        'parent_level' => 'uc', 'parent_column' => 'uc_id',
        'code_label' => 'Beat Number', 'code_column' => 'beat_number',
        'has_description' => false,
    ],
    'site' => [
        'table' => 'sites', 'label' => 'Site', 'plural' => 'Sites',
        'parent_level' => 'uc', 'parent_column' => 'uc_id',
        'code_label' => 'Site Number', 'has_site_type' => true, 'has_coordinates' => true, 'has_address' => true,
    ],
];

/* ------------------------------------------------------------------ *
 | Self-healing schema                                                 |
 * ------------------------------------------------------------------ */

/**
 * Create every table this module needs if it isn't there yet.
 *
 * database/schema.sql carries the same definitions for a brand-new
 * install; this is what upgrades a system that was already installed
 * and running before this module existed — the installer itself is
 * locked and can never be re-run, so a page-load-time, idempotent
 * CREATE TABLE IF NOT EXISTS is the only safe way to add tables to it.
 */
function structure_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $statements = [
        "CREATE TABLE IF NOT EXISTS `regions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(120) NOT NULL,
            `code` VARCHAR(30) NOT NULL,
            `description` VARCHAR(255) NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_region_code` (`code`), KEY `idx_region_status` (`status`),
            CONSTRAINT `fk_region_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_region_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `districts` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `region_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `code` VARCHAR(30) NOT NULL,
            `description` VARCHAR(255) NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_district_region_code` (`region_id`, `code`), KEY `idx_district_status` (`status`),
            CONSTRAINT `fk_district_region` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_district_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_district_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `tehsils` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `district_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `code` VARCHAR(30) NOT NULL,
            `description` VARCHAR(255) NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_tehsil_district_code` (`district_id`, `code`), KEY `idx_tehsil_status` (`status`),
            CONSTRAINT `fk_tehsil_district` FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_tehsil_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_tehsil_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `ucs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tehsil_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `code` VARCHAR(30) NOT NULL,
            `uc_number` VARCHAR(20) NULL,
            `description` VARCHAR(255) NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `kml_file` VARCHAR(190) NULL, `kmz_file` VARCHAR(190) NULL,
            `map_uploaded_at` DATETIME NULL, `map_uploaded_by` INT UNSIGNED NULL,
            `created_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_uc_tehsil_code` (`tehsil_id`, `code`), KEY `idx_uc_status` (`status`),
            CONSTRAINT `fk_uc_tehsil` FOREIGN KEY (`tehsil_id`) REFERENCES `tehsils` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_uc_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_uc_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_uc_map_uploaded_by` FOREIGN KEY (`map_uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `beats` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uc_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `beat_number` VARCHAR(30) NOT NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_beat_uc_number` (`uc_id`, `beat_number`), KEY `idx_beat_status` (`status`),
            CONSTRAINT `fk_beat_uc` FOREIGN KEY (`uc_id`) REFERENCES `ucs` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_beat_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_beat_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `site_types` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(80) NOT NULL, `slug` VARCHAR(80) NOT NULL,
            `is_system` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_site_type_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `sites` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `uc_id` INT UNSIGNED NOT NULL, `site_type_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(120) NOT NULL, `code` VARCHAR(30) NOT NULL,
            `description` VARCHAR(255) NULL,
            `latitude` DECIMAL(10,7) NULL, `longitude` DECIMAL(10,7) NULL,
            `address` VARCHAR(255) NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_site_uc_code` (`uc_id`, `code`),
            KEY `idx_site_status` (`status`), KEY `idx_site_type` (`site_type_id`),
            CONSTRAINT `fk_site_uc` FOREIGN KEY (`uc_id`) REFERENCES `ucs` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_site_type` FOREIGN KEY (`site_type_id`) REFERENCES `site_types` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_site_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_site_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    $pdo = Database::pdo();
    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            log_event('structure', 'Schema ensure failed', ['error' => $e->getMessage()]);
        }
    }

    try {
        $pdo->exec(
            "INSERT IGNORE INTO site_types (name, slug, is_system) VALUES
             ('TCP', 'tcp', 1),
             ('Dumpsite', 'dumpsite', 1),
             ('Waste Enclosure', 'waste-enclosure', 1),
             ('Attendance Point', 'attendance-point', 1)"
        );
    } catch (Throwable $e) {
        log_event('structure', 'Site type seed failed', ['error' => $e->getMessage()]);
    }

    /*
     * The map tile source is a setting, not a hard-coded URL — free tile
     * providers change their terms without warning (this exact app hit
     * two different providers breaking within one conversation: OSM's
     * own usage policy blocking embedded apps, then CARTO requiring a
     * key from Aug 2026 onward). Defaulting to OSM's single-host URL
     * (no {s} subdomain sharding, which OSM's current docs also use) is
     * the least-registration option available today; whoever manages
     * this system can swap it for any tile provider later by editing
     * one row, with no code or file changes needed.
     */
    try {
        $pdo->exec(
            "INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_group) VALUES
             ('map_tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', 'structure'),
             ('map_tile_attribution', '&copy; <a href=\"https://www.openstreetmap.org/copyright\" target=\"_blank\" rel=\"noopener\">OpenStreetMap</a> contributors', 'structure')"
        );
    } catch (Throwable $e) {
        log_event('structure', 'Map tile setting seed failed', ['error' => $e->getMessage()]);
    }

    try {
        Database::execute(
            'INSERT INTO modules (slug, name, version) VALUES (:slug, :name, :version)
             ON DUPLICATE KEY UPDATE version = VALUES(version)',
            ['slug' => 'structure', 'name' => 'Administrative Structure', 'version' => STRUCTURE_MODULE_VERSION]
        );
    } catch (Throwable $e) {
        log_event('structure', 'Could not register module', ['error' => $e->getMessage()]);
    }

    foreach (['view', 'create', 'update', 'status', 'map'] as $action) {
        $label = match ($action) {
            'view'   => 'View administrative structure',
            'create' => 'Add regions, districts, tehsils and UCs',
            'update' => 'Edit regions, districts, tehsils and UCs',
            'status' => 'Activate or deactivate structure records',
            'map'    => 'Upload UC boundary files (KML/KMZ)',
        };
        Permission::register('structure', $action, $label);
    }
}

structure_ensure_schema();

/* ------------------------------------------------------------------ *
 | Level helpers — generic across Region/District/Tehsil/UC (and,      |
 | later, Beat/Site) so one set of pages serves every level.           |
 * ------------------------------------------------------------------ */

function structure_level_config(string $level): ?array
{
    return STRUCTURE_LEVELS[$level] ?? null;
}

/**
 * Every level whose parent is $level — plural, because a UC has two
 * kinds of children (Beats now, Sites in the next stage), not one.
 * Everything that used to assume a single child level is written
 * against this from the start, so Sites needs no rework here later.
 */
function structure_child_levels(?string $level): array
{
    $children = [];
    foreach (STRUCTURE_LEVELS as $slug => $config) {
        if ($config['parent_level'] === $level) {
            $children[] = $slug;
        }
    }
    return $children;
}

function structure_find(string $level, int $id): ?array
{
    $config = structure_level_config($level);
    if ($config === null) {
        return null;
    }
    return Database::fetch("SELECT * FROM `{$config['table']}` WHERE id = :id", ['id' => $id]);
}

/**
 * Walk up from $parentId at $childLevel's parent level to the root,
 * returning the ancestor chain in top-down order for breadcrumbs.
 * Each entry is ['level' => slug, 'row' => full row].
 */
function structure_ancestors(string $childLevel, ?int $parentId): array
{
    if ($parentId === null) {
        return [];
    }

    $childConfig = structure_level_config($childLevel);
    $parentLevel = $childConfig['parent_level'] ?? null;
    if ($parentLevel === null) {
        return [];
    }

    $row = structure_find($parentLevel, $parentId);
    if ($row === null) {
        return [];
    }

    $parentConfig = structure_level_config($parentLevel);
    $grandParentColumn = $parentConfig['parent_column'] ?? null;
    $chain = $grandParentColumn !== null
        ? structure_ancestors($parentLevel, (int)$row[$grandParentColumn])
        : [];

    $chain[] = ['level' => $parentLevel, 'row' => $row];
    return $chain;
}

/** Counts for the dashboard cards — includes Beat/Site even before their
    own screens exist, since the tables (and the honest zero) are real. */
function structure_counts(): array
{
    $counts = [];
    foreach (['region' => 'regions', 'district' => 'districts', 'tehsil' => 'tehsils',
              'uc' => 'ucs', 'beat' => 'beats', 'site' => 'sites'] as $key => $table) {
        try {
            $counts[$key] = (int)Database::fetchValue("SELECT COUNT(*) FROM `{$table}`");
        } catch (Throwable $e) {
            $counts[$key] = 0;
        }
    }
    return $counts;
}

function structure_status_label(string $status): string
{
    return $status === 'active' ? 'Active' : 'Inactive';
}

function structure_status_badge(string $status): string
{
    return $status === 'active' ? 'badge--active' : 'badge--inactive';
}

/** All Site Types, for the dropdown and the cross-hierarchy Sites browser. */
function structure_site_types(): array
{
    try {
        return Database::fetchAll('SELECT * FROM site_types ORDER BY name ASC');
    } catch (Throwable $e) {
        return [];
    }
}

/** A plain, keyless link to view a coordinate on a map — no embedded
    viewer yet (that's UC-level KML/KMZ, a later stage), just a way to
    actually see where a Site is right now. */
function structure_map_link(?string $latitude, ?string $longitude): string
{
    if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
        return '';
    }
    return 'https://www.google.com/maps?q=' . rawurlencode($latitude) . ',' . rawurlencode($longitude);
}
