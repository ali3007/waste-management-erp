-- Attendance Management Module — migration 001 (additive only).
-- Idempotent: every statement is CREATE TABLE IF NOT EXISTS. Statements are
-- separated by a line containing only "-- @@". Reuses employees, users, ucs,
-- hr_staff_pools, hr_staff_types and activity_log; creates no duplicates.
-- All DATETIME columns hold Asia/Karachi local time (the attendance timezone).

CREATE TABLE IF NOT EXISTS `attendance_timing_configs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope_key` VARCHAR(24) NOT NULL COMMENT 'company | uc-<id>',
  `uc_id` INT UNSIGNED NULL,
  `inherit_company` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = UC reverts to the company default from effective_from',
  `checkin_start` TIME NULL, `checkin_end` TIME NULL,
  `checkout_start` TIME NULL, `checkout_end` TIME NULL,
  `effective_from` DATE NOT NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_att_timing_scope_date` (`scope_key`, `effective_from`),
  KEY `idx_att_timing_uc` (`uc_id`),
  CONSTRAINT `fk_att_timing_uc` FOREIGN KEY (`uc_id`) REFERENCES `ucs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_att_timing_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `attendance_holidays` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `holiday_date` DATE NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `description` VARCHAR(500) NULL,
  `created_by` INT UNSIGNED NULL, `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_att_holiday_date` (`holiday_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `attendance_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `attendance_date` DATE NOT NULL,
  `staff_pool_id` INT UNSIGNED NOT NULL COMMENT 'category at the time of the record',
  `staff_type_id` INT UNSIGNED NOT NULL,
  `uc_id` INT UNSIGNED NULL COMMENT 'UC assignment at the time of the record',
  `supervisor_employee_id` INT UNSIGNED NULL COMMENT 'UC supervisor at the time of the record',
  `status` ENUM('not_marked','checked_in','present','incomplete','pending_review','absent','leave','off_day','holiday') NOT NULL DEFAULT 'not_marked',
  `resolution_source` ENUM('events','review','correction','auto','system') NOT NULL DEFAULT 'system',
  `leave_request_id` INT UNSIGNED NULL,
  `holiday_id` INT UNSIGNED NULL,
  `checkin_at` DATETIME NULL,
  `checkin_by_user_id` INT UNSIGNED NULL,
  `checkin_channel` ENUM('self','supervisor') NULL,
  `checkout_at` DATETIME NULL,
  `checkout_by_user_id` INT UNSIGNED NULL,
  `checkout_channel` ENUM('self','supervisor') NULL,
  `review_state` ENUM('none','pending','resolved') NOT NULL DEFAULT 'none',
  `review_reason` VARCHAR(30) NULL COMMENT 'no_checkin | no_checkout',
  `finalized_at` DATETIME NULL,
  `last_corrected_at` DATETIME NULL, `last_corrected_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_att_employee_date` (`employee_id`, `attendance_date`),
  KEY `idx_att_date` (`attendance_date`),
  KEY `idx_att_date_status` (`attendance_date`, `status`),
  KEY `idx_att_date_uc` (`attendance_date`, `uc_id`),
  KEY `idx_att_uc` (`uc_id`),
  KEY `idx_att_pool_date` (`staff_pool_id`, `attendance_date`),
  KEY `idx_att_supervisor` (`supervisor_employee_id`),
  KEY `idx_att_review` (`review_state`, `attendance_date`),
  CONSTRAINT `fk_att_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_att_pool` FOREIGN KEY (`staff_pool_id`) REFERENCES `hr_staff_pools` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_att_type` FOREIGN KEY (`staff_type_id`) REFERENCES `hr_staff_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_att_uc` FOREIGN KEY (`uc_id`) REFERENCES `ucs` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `attendance_reviews` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `record_id` BIGINT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL,
  `attendance_date` DATE NOT NULL,
  `decision` ENUM('present','absent') NOT NULL,
  `reason` VARCHAR(500) NOT NULL,
  `previous_status` VARCHAR(20) NOT NULL,
  `decided_by` INT UNSIGNED NULL,
  `decided_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_att_review_record` (`record_id`),
  KEY `idx_att_review_employee` (`employee_id`, `attendance_date`),
  CONSTRAINT `fk_att_review_record` FOREIGN KEY (`record_id`) REFERENCES `attendance_records` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_att_review_user` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `attendance_corrections` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `record_id` BIGINT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL,
  `attendance_date` DATE NOT NULL,
  `original_value` TEXT NOT NULL COMMENT 'JSON snapshot before the correction',
  `corrected_value` TEXT NOT NULL COMMENT 'JSON snapshot after the correction',
  `reason` VARCHAR(500) NOT NULL,
  `corrected_by` INT UNSIGNED NULL,
  `corrected_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_att_corr_record` (`record_id`),
  KEY `idx_att_corr_employee` (`employee_id`, `attendance_date`),
  CONSTRAINT `fk_att_corr_record` FOREIGN KEY (`record_id`) REFERENCES `attendance_records` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_att_corr_user` FOREIGN KEY (`corrected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `attendance_leave_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `staff_pool_id` INT UNSIGNED NOT NULL,
  `uc_id` INT UNSIGNED NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `reason` VARCHAR(500) NOT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `submitted_by` INT UNSIGNED NULL,
  `submitted_at` DATETIME NOT NULL,
  `decided_by` INT UNSIGNED NULL,
  `decided_at` DATETIME NULL,
  `decision_note` VARCHAR(500) NULL COMMENT 'rejection reason when rejected',
  PRIMARY KEY (`id`),
  KEY `idx_att_leave_employee` (`employee_id`, `start_date`, `end_date`),
  KEY `idx_att_leave_status` (`status`, `start_date`),
  KEY `idx_att_leave_uc` (`uc_id`),
  CONSTRAINT `fk_att_leave_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_att_leave_pool` FOREIGN KEY (`staff_pool_id`) REFERENCES `hr_staff_pools` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_att_leave_uc` FOREIGN KEY (`uc_id`) REFERENCES `ucs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_att_leave_submitter` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_att_leave_decider` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `attendance_leave_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `leave_request_id` INT UNSIGNED NOT NULL,
  `action` ENUM('submitted','approved','rejected') NOT NULL,
  `actor_user_id` INT UNSIGNED NULL,
  `note` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_att_leave_hist` (`leave_request_id`),
  CONSTRAINT `fk_att_leave_hist_req` FOREIGN KEY (`leave_request_id`) REFERENCES `attendance_leave_requests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_att_leave_hist_user` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `attendance_audit_changes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_log_id` BIGINT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NULL,
  `attendance_date` DATE NULL,
  `uc_id` INT UNSIGNED NULL,
  `outcome` ENUM('success','blocked','failed') NOT NULL DEFAULT 'success',
  `old_value` TEXT NULL, `new_value` TEXT NULL,
  `reason` VARCHAR(500) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_att_audit_log` (`activity_log_id`),
  KEY `idx_att_audit_employee` (`employee_id`, `attendance_date`),
  KEY `idx_att_audit_uc` (`uc_id`),
  CONSTRAINT `fk_att_audit_log` FOREIGN KEY (`activity_log_id`) REFERENCES `activity_log` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `attendance_job_runs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attendance_date` DATE NOT NULL,
  `status` ENUM('running','success','failed') NOT NULL,
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `rows_affected` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_message` VARCHAR(500) NULL,
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_att_job_date` (`attendance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `attendance_migrations` (
  `filename` VARCHAR(120) NOT NULL,
  `checksum` CHAR(32) NOT NULL,
  `applied_at` DATETIME NOT NULL,
  PRIMARY KEY (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
