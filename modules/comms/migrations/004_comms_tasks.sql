-- Tasks (Phase 3). Additive; idempotent. Statements separated by "-- @@".

CREATE TABLE IF NOT EXISTS `comms_task_templates` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`              VARCHAR(120) NOT NULL,
  `title`             VARCHAR(200) NULL,
  `description`       TEXT NULL,
  `priority`          ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `evidence_required` TINYINT(1) NOT NULL DEFAULT 0,
  `checklist_json`    TEXT NULL,
  `created_by`        INT UNSIGNED NULL,
  `updated_by`        INT UNSIGNED NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`        TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comms_tpl_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_tasks` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_no`             VARCHAR(20) NULL,
  `create_uuid`         CHAR(36) NOT NULL,
  `title`               VARCHAR(200) NOT NULL,
  `description`         TEXT NOT NULL,
  `priority`            ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `status`              ENUM('active','partially_completed','completed','cancelled') NOT NULL DEFAULT 'active',
  `due_date`            DATE NOT NULL,
  `due_time`            TIME NULL,
  `original_due_date`   DATE NOT NULL,
  `original_due_time`   TIME NULL,
  `review_due_date`     DATE NULL,
  `review_due_time`     TIME NULL,
  `reminder_minutes`    INT UNSIGNED NULL,
  `evidence_required`   TINYINT(1) NOT NULL DEFAULT 0,
  `assigner_id`         INT UNSIGNED NULL,
  `reviewer_id`         INT UNSIGNED NULL,
  `template_id`         INT UNSIGNED NULL,
  `link_type`           VARCHAR(20) NULL,
  `link_id`             INT UNSIGNED NULL,
  `conversation_id`     INT UNSIGNED NULL,
  `cancelled_at`        TIMESTAMP NULL,
  `cancelled_by`        INT UNSIGNED NULL,
  `cancel_reason`       VARCHAR(500) NULL,
  `archived_at`         TIMESTAMP NULL,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comms_task_uuid` (`create_uuid`),
  UNIQUE KEY `uq_comms_task_no` (`task_no`),
  KEY `idx_comms_task_status` (`status`, `due_date`),
  KEY `idx_comms_task_assigner` (`assigner_id`),
  KEY `idx_comms_task_reviewer` (`reviewer_id`),
  CONSTRAINT `fk_comms_task_assigner` FOREIGN KEY (`assigner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_comms_task_reviewer` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_comms_task_template` FOREIGN KEY (`template_id`) REFERENCES `comms_task_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_assignees` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`        INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `status`         ENUM('pending_acceptance','accepted','declined','in_progress','submitted','rejected','approved','cancelled') NOT NULL DEFAULT 'pending_acceptance',
  `progress`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `self_assigned`  TINYINT(1) NOT NULL DEFAULT 0,
  `assigned_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `accepted_at`    TIMESTAMP NULL,
  `declined_at`    TIMESTAMP NULL,
  `decline_reason` VARCHAR(500) NULL,
  `started_at`     TIMESTAMP NULL,
  `removed_at`     TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comms_ta_user` (`task_id`, `user_id`),
  KEY `idx_comms_ta_user` (`user_id`, `status`),
  CONSTRAINT `fk_comms_ta_task` FOREIGN KEY (`task_id`) REFERENCES `comms_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comms_ta_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_progress` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`    INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `old_pct`    TINYINT UNSIGNED NOT NULL,
  `new_pct`    TINYINT UNSIGNED NOT NULL,
  `kind`       ENUM('manual','checklist','system') NOT NULL DEFAULT 'manual',
  `comment`    VARCHAR(1000) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_at`  TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comms_tp_task` (`task_id`, `user_id`, `id`),
  CONSTRAINT `fk_comms_tp_task` FOREIGN KEY (`task_id`) REFERENCES `comms_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_checklists` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`    INT UNSIGNED NOT NULL,
  `version`    INT UNSIGNED NOT NULL,
  `change_note` VARCHAR(500) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comms_cl_ver` (`task_id`, `version`),
  CONSTRAINT `fk_comms_cl_task` FOREIGN KEY (`task_id`) REFERENCES `comms_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_checklist_items` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `checklist_id` INT UNSIGNED NOT NULL,
  `item_key`     CHAR(12) NOT NULL,
  `position`     SMALLINT UNSIGNED NOT NULL,
  `text`         VARCHAR(255) NOT NULL,
  `required`     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_comms_cli_cl` (`checklist_id`, `position`),
  CONSTRAINT `fk_comms_cli_cl` FOREIGN KEY (`checklist_id`) REFERENCES `comms_task_checklists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_step_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`    INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `item_key`   CHAR(12) NOT NULL,
  `done`       TINYINT(1) NOT NULL,
  `version`    INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comms_sl` (`task_id`, `user_id`, `item_key`, `id`),
  CONSTRAINT `fk_comms_sl_task` FOREIGN KEY (`task_id`) REFERENCES `comms_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_comments` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`    INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NULL,
  `parent_id`  BIGINT UNSIGNED NULL,
  `body`       TEXT NOT NULL,
  `important`  TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_at`  TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comms_tc_task` (`task_id`, `id`),
  CONSTRAINT `fk_comms_tc_task` FOREIGN KEY (`task_id`) REFERENCES `comms_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_mentions` (
  `comment_id` BIGINT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  PRIMARY KEY (`comment_id`, `user_id`),
  CONSTRAINT `fk_comms_tm_c` FOREIGN KEY (`comment_id`) REFERENCES `comms_task_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_edits` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` ENUM('comment','progress') NOT NULL,
  `entity_id`   BIGINT UNSIGNED NOT NULL,
  `old_text`    TEXT NULL,
  `new_text`    TEXT NULL,
  `edited_by`   INT UNSIGNED NULL,
  `edited_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comms_te` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_task_events` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`    INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NULL,
  `event`      VARCHAR(40) NOT NULL,
  `old_values` JSON NULL,
  `new_values` JSON NULL,
  `note`       VARCHAR(500) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comms_ev_task` (`task_id`, `id`),
  CONSTRAINT `fk_comms_ev_task` FOREIGN KEY (`task_id`) REFERENCES `comms_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_notifications` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `type`         VARCHAR(40) NOT NULL,
  `priority`     ENUM('normal','high') NOT NULL DEFAULT 'normal',
  `title`        VARCHAR(160) NOT NULL,
  `subject_type` VARCHAR(20) NULL,
  `subject_id`   BIGINT UNSIGNED NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at`      TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comms_n_user` (`user_id`, `read_at`, `id`),
  CONSTRAINT `fk_comms_n_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
