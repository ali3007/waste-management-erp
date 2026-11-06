-- Communication & Task Management — core tables (Phase 1). Additive only; every statement is idempotent.
-- Statements are separated by a line containing only "-- @@" (same runner convention as Attendance).

CREATE TABLE IF NOT EXISTS `comms_audit_log` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id`     INT UNSIGNED NULL,
  `action`       VARCHAR(80)  NOT NULL,
  `subject_type` VARCHAR(60)  NULL,
  `subject_id`   BIGINT UNSIGNED NULL,
  `description`  VARCHAR(255) NULL,
  `old_values`   JSON NULL,
  `new_values`   JSON NULL,
  `ip_address`   VARCHAR(45)  NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comms_audit_actor` (`actor_id`, `created_at`),
  KEY `idx_comms_audit_subject` (`subject_type`, `subject_id`),
  KEY `idx_comms_audit_action` (`action`, `created_at`),
  CONSTRAINT `fk_comms_audit_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
