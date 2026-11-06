-- Chat extras (Phase 2b): attachments, voice, reactions, pins, edits, hide-for-me. Additive; idempotent.
-- @@
ALTER TABLE `comms_messages`
  ADD COLUMN IF NOT EXISTS `deleted_at`   TIMESTAMP NULL AFTER `edited_at`,
  ADD COLUMN IF NOT EXISTS `deleted_by`   INT UNSIGNED NULL AFTER `deleted_at`,
  ADD COLUMN IF NOT EXISTS `forwarded`    TINYINT(1) NOT NULL DEFAULT 0 AFTER `deleted_by`,
  ADD COLUMN IF NOT EXISTS `announcement` TINYINT(1) NOT NULL DEFAULT 0 AFTER `forwarded`,
  ADD COLUMN IF NOT EXISTS `changed_ms`   BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `announcement`;
-- @@
ALTER TABLE `comms_messages` ADD INDEX IF NOT EXISTS `idx_comms_msg_changed` (`conversation_id`, `changed_ms`);
-- @@
ALTER TABLE `comms_conversations` ADD COLUMN IF NOT EXISTS `photo` VARCHAR(64) NULL AFTER `name`;
-- @@
CREATE TABLE IF NOT EXISTS `comms_attachments` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id`       BIGINT UNSIGNED NOT NULL,
  `conversation_id`  INT UNSIGNED NOT NULL,
  `uploader_id`      INT UNSIGNED NULL,
  `kind`             ENUM('file','voice') NOT NULL DEFAULT 'file',
  `original_name`    VARCHAR(190) NOT NULL,
  `stored_name`      CHAR(32) NOT NULL,
  `mime`             VARCHAR(80) NOT NULL,
  `size_bytes`       BIGINT UNSIGNED NOT NULL,
  `sha256`           CHAR(64) NOT NULL,
  `duration_sec`     SMALLINT UNSIGNED NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comms_att_msg` (`message_id`),
  KEY `idx_comms_att_stored` (`stored_name`),
  CONSTRAINT `fk_comms_att_msg` FOREIGN KEY (`message_id`) REFERENCES `comms_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comms_att_uploader` FOREIGN KEY (`uploader_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_reactions` (
  `message_id` BIGINT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `emoji`      VARCHAR(16) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`message_id`, `user_id`),
  CONSTRAINT `fk_comms_react_msg` FOREIGN KEY (`message_id`) REFERENCES `comms_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comms_react_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_pins` (
  `conversation_id` INT UNSIGNED NOT NULL,
  `message_id`      BIGINT UNSIGNED NOT NULL,
  `pinned_by`       INT UNSIGNED NULL,
  `pinned_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`conversation_id`, `message_id`),
  CONSTRAINT `fk_comms_pin_msg` FOREIGN KEY (`message_id`) REFERENCES `comms_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comms_pin_conv` FOREIGN KEY (`conversation_id`) REFERENCES `comms_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_message_hidden` (
  `message_id` BIGINT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `hidden_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`message_id`, `user_id`),
  CONSTRAINT `fk_comms_hide_msg` FOREIGN KEY (`message_id`) REFERENCES `comms_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comms_hide_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_message_edits` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `old_body`   TEXT NOT NULL,
  `edited_by`  INT UNSIGNED NULL,
  `edited_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comms_edit_msg` (`message_id`),
  CONSTRAINT `fk_comms_edit_msg` FOREIGN KEY (`message_id`) REFERENCES `comms_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
