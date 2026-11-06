<?php
/**
 * Migration 002 — per-category attendance timing (extends attendance_timing_configs; no new table).
 *
 *   • scope_key  VARCHAR(24) → VARCHAR(40)   room for 'pool-<id>' and 'uc-<id>-pool-<id>'
 *   • staff_pool_id (nullable, FK hr_staff_pools) records which category a row belongs to
 *
 * Existing rows are untouched: 'company' and legacy 'uc-<id>' rows keep working
 * (company = fallback for every category; legacy uc-<id> = UC override for all
 * Supervisor-marked categories). Idempotent: each step checks information_schema first.
 * Equivalent SQL, for reference / manual use:
 *   ALTER TABLE attendance_timing_configs MODIFY scope_key VARCHAR(40) NOT NULL COMMENT 'company | pool-<id> | uc-<id> | uc-<id>-pool-<id>';
 *   ALTER TABLE attendance_timing_configs ADD COLUMN staff_pool_id INT UNSIGNED NULL AFTER uc_id,
 *     ADD KEY idx_att_timing_pool (staff_pool_id),
 *     ADD CONSTRAINT fk_att_timing_pool FOREIGN KEY (staff_pool_id) REFERENCES hr_staff_pools (id) ON DELETE RESTRICT;
 */
declare(strict_types=1);

return static function (PDO $pdo): void {
    $one = static function (string $sql, array $p = []) use ($pdo) {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchColumn();
    };

    $len = (int)$one("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance_timing_configs' AND COLUMN_NAME = 'scope_key'");
    if ($len > 0 && $len < 40) {
        $pdo->exec("ALTER TABLE `attendance_timing_configs` MODIFY `scope_key` VARCHAR(40) NOT NULL COMMENT 'company | pool-<id> | uc-<id> | uc-<id>-pool-<id>'");
    }
    if ((int)$one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'attendance_timing_configs' AND COLUMN_NAME = 'staff_pool_id'") === 0) {
        $pdo->exec('ALTER TABLE `attendance_timing_configs` ADD COLUMN `staff_pool_id` INT UNSIGNED NULL AFTER `uc_id`');
    }
    if ((int)$one("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'attendance_timing_configs' AND INDEX_NAME = 'idx_att_timing_pool'") === 0) {
        $pdo->exec('ALTER TABLE `attendance_timing_configs` ADD KEY `idx_att_timing_pool` (`staff_pool_id`)');
    }
    if ((int)$one("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'attendance_timing_configs' AND CONSTRAINT_NAME = 'fk_att_timing_pool'") === 0) {
        $pdo->exec('ALTER TABLE `attendance_timing_configs` ADD CONSTRAINT `fk_att_timing_pool`
                    FOREIGN KEY (`staff_pool_id`) REFERENCES `hr_staff_pools` (`id`) ON DELETE RESTRICT');
    }
};
