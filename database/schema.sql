-- ---------------------------------------------------------------------
-- Solid Waste Management System — Phase 1 schema
--
-- Only authentication, company and system tables live here. Future
-- modules add their own tables and reference users(id) / roles(id);
-- nothing below should need to change when they do.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Key/value store for system + company configuration.
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL,
  `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
  `is_public`     TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_setting_key` (`setting_key`),
  KEY `idx_setting_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roles. `level` orders seniority; 100 is reserved for Super Admin.
CREATE TABLE IF NOT EXISTS `roles` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(80) NOT NULL,
  `slug`        VARCHAR(80) NOT NULL,
  `description` VARCHAR(255) NULL,
  `level`       SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  `is_system`   TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_slug` (`slug`),
  KEY `idx_role_level` (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permission catalogue. Future modules insert their own rows.
CREATE TABLE IF NOT EXISTS `permissions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module`      VARCHAR(60) NOT NULL,
  `action`      VARCHAR(60) NOT NULL,
  `slug`        VARCHAR(140) NOT NULL,
  `name`        VARCHAR(140) NOT NULL,
  `description` VARCHAR(255) NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permission_slug` (`slug`),
  KEY `idx_permission_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id`       INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`, `permission_id`),
  KEY `idx_rp_permission` (`permission_id`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Accounts. `login_type` decides whether the unified login field is
-- matched against `email` (Super Admin) or `cnic` (ERP users).
-- NULLs are allowed on both, and MySQL permits repeated NULLs in a
-- UNIQUE index, so each identifier stays unique where it is used.
CREATE TABLE IF NOT EXISTS `users` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id`               INT UNSIGNED NULL,
  `name`                  VARCHAR(120) NOT NULL,
  `email`                 VARCHAR(190) NULL,
  `cnic`                  CHAR(13) NULL,
  `phone`                 VARCHAR(30) NULL,
  `password_hash`         VARCHAR(255) NOT NULL,
  `login_type`            ENUM('email','cnic') NOT NULL DEFAULT 'cnic',
  `status`                ENUM('active','inactive','pending','blocked') NOT NULL DEFAULT 'pending',
  `profile_picture`       VARCHAR(190) NULL,
  `must_change_password`  TINYINT(1) NOT NULL DEFAULT 0,
  `activation_token_hash` CHAR(64) NULL,
  `activation_expires_at` DATETIME NULL,
  `activated_at`          DATETIME NULL,
  `password_changed_at`   DATETIME NULL,
  `last_login_at`         DATETIME NULL,
  `last_login_ip`         VARCHAR(45) NULL,
  `created_by`            INT UNSIGNED NULL,
  `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_email` (`email`),
  UNIQUE KEY `uq_user_cnic` (`cnic`),
  KEY `idx_user_role` (`role_id`),
  KEY `idx_user_status` (`status`),
  KEY `idx_user_activation` (`activation_token_hash`),
  CONSTRAINT `fk_user_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_user_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-use password reset tokens (stored hashed).
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `ip_hash`    CHAR(64) NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at`    DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reset_token` (`token_hash`),
  KEY `idx_reset_user` (`user_id`),
  CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remember-me tokens: public selector + hashed validator.
CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `selector`       CHAR(18) NOT NULL,
  `validator_hash` CHAR(64) NOT NULL,
  `user_agent`     VARCHAR(255) NULL,
  `expires_at`     DATETIME NOT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_remember_selector` (`selector`),
  KEY `idx_remember_user` (`user_id`),
  CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed/successful sign-in log, used for throttling and auditing.
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier`   VARCHAR(190) NOT NULL,
  `user_id`      INT UNSIGNED NULL,
  `ip_hash`      CHAR(64) NOT NULL,
  `user_agent`   VARCHAR(255) NULL,
  `successful`   TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_attempt_ip` (`ip_hash`, `successful`, `attempted_at`),
  KEY `idx_attempt_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only trail. Future modules write here instead of inventing
-- their own logging tables.
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NULL,
  `module`      VARCHAR(60) NOT NULL DEFAULT 'core',
  `action`      VARCHAR(80) NOT NULL,
  `subject_type` VARCHAR(80) NULL,
  `subject_id`  INT UNSIGNED NULL,
  `description` VARCHAR(255) NULL,
  `ip_address`  VARCHAR(45) NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_user` (`user_id`),
  KEY `idx_activity_module` (`module`, `action`),
  CONSTRAINT `fk_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registry of installed modules, so Phase 2+ can install itself without
-- touching Phase 1 code.
CREATE TABLE IF NOT EXISTS `modules` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(60) NOT NULL,
  `name`        VARCHAR(120) NOT NULL,
  `version`     VARCHAR(20) NOT NULL DEFAULT '1.0.0',
  `is_enabled`  TINYINT(1) NOT NULL DEFAULT 1,
  `installed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_module_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Administrative structure (Region > District > Tehsil > UC), plus
-- Beats and Sites as parallel children of UC. Nothing here assumes a
-- specific province or country — Region is simply the top level.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `regions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(120) NOT NULL,
  `code`        VARCHAR(30) NOT NULL,
  `description` VARCHAR(255) NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`  INT UNSIGNED NULL,
  `updated_by`  INT UNSIGNED NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_region_code` (`code`),
  KEY `idx_region_status` (`status`),
  CONSTRAINT `fk_region_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_region_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `districts` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `region_id`   INT UNSIGNED NOT NULL,
  `name`        VARCHAR(120) NOT NULL,
  `code`        VARCHAR(30) NOT NULL,
  `description` VARCHAR(255) NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`  INT UNSIGNED NULL,
  `updated_by`  INT UNSIGNED NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_district_region_code` (`region_id`, `code`),
  KEY `idx_district_status` (`status`),
  CONSTRAINT `fk_district_region` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_district_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_district_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tehsils` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `district_id` INT UNSIGNED NOT NULL,
  `name`        VARCHAR(120) NOT NULL,
  `code`        VARCHAR(30) NOT NULL,
  `description` VARCHAR(255) NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`  INT UNSIGNED NULL,
  `updated_by`  INT UNSIGNED NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tehsil_district_code` (`district_id`, `code`),
  KEY `idx_tehsil_status` (`status`),
  CONSTRAINT `fk_tehsil_district` FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_tehsil_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tehsil_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ucs` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tehsil_id`       INT UNSIGNED NOT NULL,
  `name`            VARCHAR(120) NOT NULL,
  `code`            VARCHAR(30) NOT NULL,
  `uc_number`       VARCHAR(20) NULL,
  `description`     VARCHAR(255) NULL,
  `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `kml_file`        VARCHAR(190) NULL,
  `kmz_file`        VARCHAR(190) NULL,
  `map_uploaded_at` DATETIME NULL,
  `map_uploaded_by` INT UNSIGNED NULL,
  `created_by`      INT UNSIGNED NULL,
  `updated_by`      INT UNSIGNED NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_uc_tehsil_code` (`tehsil_id`, `code`),
  KEY `idx_uc_status` (`status`),
  CONSTRAINT `fk_uc_tehsil` FOREIGN KEY (`tehsil_id`) REFERENCES `tehsils` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_uc_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_uc_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_uc_map_uploaded_by` FOREIGN KEY (`map_uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Beats and Sites are parallel children of a UC (see bootstrap.php in
-- modules/structure for why both are created now even though their
-- screens arrive in a later stage — one schema, staged UI).
CREATE TABLE IF NOT EXISTS `beats` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uc_id`        INT UNSIGNED NOT NULL,
  `name`         VARCHAR(120) NOT NULL,
  `beat_number`  VARCHAR(30) NOT NULL,
  `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`   INT UNSIGNED NULL,
  `updated_by`   INT UNSIGNED NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_beat_uc_number` (`uc_id`, `beat_number`),
  KEY `idx_beat_status` (`status`),
  CONSTRAINT `fk_beat_uc` FOREIGN KEY (`uc_id`) REFERENCES `ucs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_beat_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_beat_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_types` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(80) NOT NULL,
  `slug`       VARCHAR(80) NOT NULL,
  `is_system`  TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_site_type_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sites` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uc_id`        INT UNSIGNED NOT NULL,
  `site_type_id` INT UNSIGNED NOT NULL,
  `name`         VARCHAR(120) NOT NULL,
  `code`         VARCHAR(30) NOT NULL,
  `description`  VARCHAR(255) NULL,
  `latitude`     DECIMAL(10,7) NULL,
  `longitude`    DECIMAL(10,7) NULL,
  `address`      VARCHAR(255) NULL,
  `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`   INT UNSIGNED NULL,
  `updated_by`   INT UNSIGNED NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_site_uc_code` (`uc_id`, `code`),
  KEY `idx_site_status` (`status`),
  KEY `idx_site_type` (`site_type_id`),
  CONSTRAINT `fk_site_uc` FOREIGN KEY (`uc_id`) REFERENCES `ucs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_site_type` FOREIGN KEY (`site_type_id`) REFERENCES `site_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_site_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_site_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Human Resources (Phase 4). Employees are independent of ERP login
-- users (optional link via employees.user_id). Assignment lives in its
-- own table, separate from the employee master, so reassignment and
-- multi-UC assignment (for staff types that need it) don't require
-- overwriting history.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `hr_staff_pools` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(80) NOT NULL,
  `slug`        VARCHAR(80) NOT NULL,
  `description` VARCHAR(255) NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`  INT UNSIGNED NULL,
  `updated_by`  INT UNSIGNED NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pool_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- assignment_mode drives Employee Assignment's rules per staff type:
-- none (office staff — no UC/Beat needed), single (one UC + optional
-- Beat, e.g. Supervisor/field worker), multi (several UCs, no Beat
-- granularity, e.g. Monitoring Officer).
CREATE TABLE IF NOT EXISTS `hr_staff_types` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_pool_id`   INT UNSIGNED NOT NULL,
  `name`            VARCHAR(80) NOT NULL,
  `slug`            VARCHAR(80) NOT NULL,
  `description`     VARCHAR(255) NULL,
  `assignment_mode` ENUM('none','single','multi') NOT NULL DEFAULT 'single',
  `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`      INT UNSIGNED NULL,
  `updated_by`      INT UNSIGNED NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_type_slug` (`slug`),
  KEY `idx_type_pool` (`staff_pool_id`),
  CONSTRAINT `fk_type_pool` FOREIGN KEY (`staff_pool_id`) REFERENCES `hr_staff_pools` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `employees` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_code`    VARCHAR(20) NULL,
  `user_id`          INT UNSIGNED NULL,
  `name`             VARCHAR(120) NOT NULL,
  `father_name`      VARCHAR(120) NOT NULL,
  `cnic`             CHAR(13) NOT NULL,
  `dob`              DATE NULL,
  `address`          VARCHAR(255) NULL,
  `phone`            VARCHAR(30) NULL,
  `cnic_issue_date`  DATE NULL,
  `region_id`        INT UNSIGNED NOT NULL,
  `district_id`      INT UNSIGNED NOT NULL,
  `tehsil_id`        INT UNSIGNED NOT NULL,
  `staff_pool_id`    INT UNSIGNED NOT NULL,
  `staff_type_id`    INT UNSIGNED NOT NULL,
  `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `cnic_picture`     VARCHAR(190) NULL,
  `employee_picture` VARCHAR(190) NULL,
  `created_by`       INT UNSIGNED NULL,
  `updated_by`       INT UNSIGNED NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_code` (`employee_code`),
  UNIQUE KEY `uq_employee_cnic` (`cnic`),
  UNIQUE KEY `uq_employee_user` (`user_id`),
  KEY `idx_employee_status` (`status`),
  KEY `idx_employee_region` (`region_id`),
  KEY `idx_employee_district` (`district_id`),
  KEY `idx_employee_tehsil` (`tehsil_id`),
  KEY `idx_employee_type` (`staff_type_id`),
  CONSTRAINT `fk_employee_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_employee_region` FOREIGN KEY (`region_id`) REFERENCES `regions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_employee_district` FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_employee_tehsil` FOREIGN KEY (`tehsil_id`) REFERENCES `tehsils` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_employee_pool` FOREIGN KEY (`staff_pool_id`) REFERENCES `hr_staff_pools` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_employee_type` FOREIGN KEY (`staff_type_id`) REFERENCES `hr_staff_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_employee_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_employee_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `employee_documents` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id`   INT UNSIGNED NOT NULL,
  `file_name`     VARCHAR(190) NOT NULL,
  `original_name` VARCHAR(190) NOT NULL,
  `title`         VARCHAR(150) NULL,
  `mime_type`     VARCHAR(100) NULL,
  `file_size`     INT UNSIGNED NULL,
  `uploaded_by`   INT UNSIGNED NULL,
  `uploaded_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_document_employee` (`employee_id`),
  CONSTRAINT `fk_document_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per assignment period. "Currently assigned" = unassigned_at
-- IS NULL. A staff type with assignment_mode='multi' can have several
-- concurrently-open rows for the same employee (different UCs); 'single'
-- enforces at most one open row at application level.
CREATE TABLE IF NOT EXISTS `employee_assignments` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id`    INT UNSIGNED NOT NULL,
  `uc_id`          INT UNSIGNED NOT NULL,
  `beat_id`        INT UNSIGNED NULL,
  `assigned_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `assigned_by`    INT UNSIGNED NULL,
  `unassigned_at`  TIMESTAMP NULL,
  `unassigned_by`  INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_assignment_employee_current` (`employee_id`, `unassigned_at`),
  KEY `idx_assignment_uc` (`uc_id`),
  KEY `idx_assignment_beat` (`beat_id`),
  CONSTRAINT `fk_assignment_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignment_uc` FOREIGN KEY (`uc_id`) REFERENCES `ucs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_assignment_beat` FOREIGN KEY (`beat_id`) REFERENCES `beats` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
