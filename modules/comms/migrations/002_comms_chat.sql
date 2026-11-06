-- Chat core (Phase 2a). Additive; idempotent. Statements separated by "-- @@".

CREATE TABLE IF NOT EXISTS `comms_conversations` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`            ENUM('private','group') NOT NULL,
  `name`            VARCHAR(80) NULL,
  `private_key`     VARCHAR(30) NULL,
  `created_by`      INT UNSIGNED NULL,
  `last_message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_message_at` TIMESTAMP NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comms_conv_private` (`private_key`),
  KEY `idx_comms_conv_last` (`last_message_at`),
  CONSTRAINT `fk_comms_conv_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_members` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id`       INT UNSIGNED NOT NULL,
  `user_id`               INT UNSIGNED NOT NULL,
  `role`                  ENUM('member','manager') NOT NULL DEFAULT 'member',
  `joined_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `joined_after_msg_id`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `left_at`               TIMESTAMP NULL,
  `left_after_msg_id`     BIGINT UNSIGNED NULL,
  `added_by`              INT UNSIGNED NULL,
  `last_read_msg_id`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_delivered_msg_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_comms_member_user` (`user_id`, `left_at`),
  KEY `idx_comms_member_conv` (`conversation_id`, `left_at`),
  CONSTRAINT `fk_comms_member_conv` FOREIGN KEY (`conversation_id`) REFERENCES `comms_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comms_member_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comms_member_adder` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_messages` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` INT UNSIGNED NOT NULL,
  `sender_id`       INT UNSIGNED NULL,
  `client_uuid`     CHAR(36) NOT NULL,
  `body`            TEXT NOT NULL,
  `reply_to_id`     BIGINT UNSIGNED NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_at`       TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comms_msg_idem` (`sender_id`, `client_uuid`),
  KEY `idx_comms_msg_conv` (`conversation_id`, `id`),
  CONSTRAINT `fk_comms_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `comms_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comms_msg_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @@
CREATE TABLE IF NOT EXISTS `comms_presence` (
  `user_id`              INT UNSIGNED NOT NULL,
  `last_seen_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `typing_conversation_id` INT UNSIGNED NULL,
  `typing_at`            TIMESTAMP NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_comms_presence_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
