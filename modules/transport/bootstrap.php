<?php
/**
 * Transport module — shared bootstrap.
 *
 * Reuses (never duplicates) Phase 1–4: sign-in/permissions, company
 * branding, Structure geography, HR employees (drivers) and the HR upload
 * and area-scope helpers, and the shared activity_log (audit).
 * Trips / Route management is deliberately NOT part of this module; the
 * vehicle id is the single hook a future Trips module will reference.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const TRN_MODULE_VERSION = '1.0.0';

if (!IS_INSTALLED) {
    return; // pages redirect to the installer
}

require_once dirname(__DIR__) . '/hr/bootstrap.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/uploads.php';
require_once __DIR__ . '/lib/ui.php';
trn_ensure_schema();
require_once __DIR__ . '/lib/vehicles.php';
require_once __DIR__ . '/lib/assignments.php';
require_once __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/reports.php';

$extraStyles = array_values(array_unique(array_merge($extraStyles ?? [], ['users.css', 'hr.css', 'transport.css'])));
