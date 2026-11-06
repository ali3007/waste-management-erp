-- Task submission, review, reopening, extensions, reminders (Phase 4). Additive; idempotent. Statements separated by "-- @@".

ALTER TABLE `comms_tasks`
  ADD COLUMN IF NOT EXISTS `overdue_notified_at`  TIMESTAMP NULL AFTER `archived_at`,
  ADD COLUMN IF NOT EXISTS `reviewer_flag_at`     TIMESTAMP NULL AFTER `overdue_notified_at`;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_submissions` (
  `id`                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`                    INT UNSIGNED NOT NULL,
  `user_id`                    INT UNSIGNED NOT NULL,
  `round_no`                   INT UNSIGNED NOT NULL DEFAULT 1,
  `client_uuid`                CHAR(36) NOT NULL,
  `status`                     ENUM('pending_review','approved','rejected') NOT NULL DEFAULT 'pending_review',
  `comment`                    TEXT NOT NULL,
  `checklist_version`          INT UNSIGNED NULL,
  `progress_at_submit`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `reviewer_id`                INT UNSIGNED NULL,
  `decided_by`                 INT UNSIGNED NULL,
  `decided_at`                 TIMESTAMP NULL,
  `decision_comment`           TEXT NULL,
  `reopen_id`                  BIGINT UNSIGNED NULL,
  `reopened_at`                TIMESTAMP NULL,
  `review_overdue_notified_at` TIMESTAMP NULL,
  `submitted_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comms_sub_uuid` (`user_id`, `client_uuid`),
  KEY `idx_comms_sub_task` (`task_id`, `user_id`, `round_no`),
  KEY `idx_comms_sub_review` (`status`, `reviewer_id`),
  CONSTRAINT `fk_comms_sub_task` FOREIGN KEY (`task_id`) REFERENCES `comms_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comms_sub_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_evidence` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id` BIGINT UNSIGNED NOT NULL,
  `task_id`       INT UNSIGNED NOT NULL,
  `uploader_id`   INT UNSIGNED NULL,
  `original_name` VARCHAR(190) NOT NULL,
  `stored_name`   CHAR(32) NOT NULL,
  `mime`          VARCHAR(80) NOT NULL,
  `size_bytes`    BIGINT UNSIGNED NOT NULL,
  `sha256`        CHAR(64) NOT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comms_ev_sub` (`submission_id`),
  KEY `idx_comms_ev_stored` (`stored_name`),
  CONSTRAINT `fk_comms_evid_sub` FOREIGN KEY (`submission_id`) REFERENCES `comms_task_submissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_reviewer_changes` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`      INT UNSIGNED NOT NULL,
  `old_reviewer` INT UNSIGNED NULL,
  `new_reviewer` INT UNSIGNED NOT NULL,
  `reason`       VARCHAR(500) NOT NULL,
  `changed_by`   INT UNSIGNED NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comms_rc_task` (`task_id`),
  CONSTRAINT `fk_comms_rc_task` FOREIGN KEY (`task_id`) REFERENCES `comms_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_reopens` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`     INT UNSIGNED NOT NULL,
  `reopened_by` INT UNSIGNED NULL,
  `reason`      VARCHAR(500) NOT NULL,
  `details`     TEXT NOT NULL,
  `affected`    JSON NULL,
  `prev_status` VARCHAR(30) NOT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comms_ro_task` (`task_id`),
  CONSTRAINT `fk_comms_ro_task` FOREIGN KEY (`task_id`) REFERENCES `comms_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_extensions` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`          INT UNSIGNED NOT NULL,
  `requested_by`     INT UNSIGNED NOT NULL,
  `proposed_date`    DATE NOT NULL,
  `proposed_time`    TIME NULL,
  `reason`           VARCHAR(500) NOT NULL,
  `status`           ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `decided_by`       INT UNSIGNED NULL,
  `decided_at`       TIMESTAMP NULL,
  `decision_comment` VARCHAR(500) NULL,
  `old_due_date`     DATE NULL,
  `old_due_time`     TIME NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comms_ext_task` (`task_id`, `status`),
  CONSTRAINT `fk_comms_ext_task` FOREIGN KEY (`task_id`) REFERENCES `comms_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_deadline_history` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`      INT UNSIGNED NOT NULL,
  `kind`         ENUM('due','review_due') NOT NULL DEFAULT 'due',
  `old_date`     DATE NULL,
  `old_time`     TIME NULL,
  `new_date`     DATE NULL,
  `new_time`     TIME NULL,
  `changed_by`   INT UNSIGNED NULL,
  `source`       ENUM('edit','extension') NOT NULL,
  `extension_id` BIGINT UNSIGNED NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comms_dh_task` (`task_id`),
  CONSTRAINT `fk_comms_dh_task` FOREIGN KEY (`task_id`) REFERENCES `comms_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_reminders` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`      INT UNSIGNED NOT NULL,
  `fire_ts`      BIGINT UNSIGNED NOT NULL,
  `minutes`      INT UNSIGNED NOT NULL,
  `deadline_ts`  BIGINT UNSIGNED NOT NULL,
  `sent_at`      TIMESTAMP NULL,
  `cancelled_at` TIMESTAMP NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comms_rem_due` (`sent_at`, `cancelled_at`, `fire_ts`),
  KEY `idx_comms_rem_task` (`task_id`),
  CONSTRAINT `fk_comms_rem_task` FOREIGN KEY (`task_id`) REFERENCES `comms_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
