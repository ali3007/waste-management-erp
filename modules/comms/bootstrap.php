<?php
/**
 * Communication & Task Management — shared bootstrap.
 *
 * Reuses (never duplicates) users, roles/permissions, HR employees, system_settings and the ERP's
 * session/CSRF layer. Real-time updates use AJAX polling (no extra server process), so the module
 * runs on a stock XAMPP install with no internet access.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const COMMS_VERSION = '0.5.0';
/** Bump when a new file is added to migrations/. */
const COMMS_SCHEMA_VERSION = '5';

if (!IS_INSTALLED) {
    return; // pages redirect to the installer
}

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/schema.php';
comms_ensure_schema();
require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/chat.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/files.php';
require_once __DIR__ . '/lib/actions.php';
require_once __DIR__ . '/lib/notify.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/task_work.php';
require_once __DIR__ . '/lib/task_deadlines.php';
require_once __DIR__ . '/lib/task_review.php';
require_once __DIR__ . '/lib/task_links.php';
require_once __DIR__ . '/lib/task_ui.php';

$extraStyles = array_values(array_unique(array_merge($extraStyles ?? [], ['hr.css', 'comms.css'])));
