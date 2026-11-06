<?php
/**
 * Attendance module — shared bootstrap.
 *
 * One backend for both interfaces (Office self-attendance and Supervisor
 * marking). Reuses — never duplicates — users, roles/permissions, HR
 * employees + staff pools/types + employee_assignments, the Structure
 * geography, system_settings and the shared activity_log (audit).
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const ATT_VERSION = '1.2.0';
/** Bump when a new file is added to migrations/. */
const ATT_SCHEMA_VERSION = '2';
/** The attendance timezone. Not configurable: the business rule names it. */
const ATT_TZ = 'Asia/Karachi';

if (!IS_INSTALLED) {
    return; // pages redirect to the installer
}

require_once dirname(__DIR__) . '/hr/bootstrap.php';
require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/schema.php';
att_ensure_schema();
require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/timing.php';
att_sync_pool_permissions();
require_once __DIR__ . '/lib/records.php';
require_once __DIR__ . '/lib/marking.php';
require_once __DIR__ . '/lib/sweep.php';
require_once __DIR__ . '/lib/review.php';
require_once __DIR__ . '/lib/corrections.php';
require_once __DIR__ . '/lib/leave.php';
require_once __DIR__ . '/lib/holidays.php';
require_once __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/reports.php';
require_once __DIR__ . '/lib/ui.php';

$extraStyles = array_values(array_unique(array_merge($extraStyles ?? [], ['users.css', 'hr.css', 'attendance.css'])));
