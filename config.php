<?php
/**
 * Solid Waste Management System
 * Central bootstrap + configuration entry point.
 *
 * This is the ONLY file that every other entry point must include.
 * Environment-specific values are never written here by hand; the
 * installer generates database/config.generated.php instead.
 */

declare(strict_types=1);

if (defined('SWMS_BOOTSTRAPPED')) {
    return;
}
define('SWMS_BOOTSTRAPPED', true);

/* Guard constant used by included files to refuse direct execution. */
define('SWMS', true);

/* ------------------------------------------------------------------ *
 | Product identity (central version — never duplicate these strings)  |
 * ------------------------------------------------------------------ */
define('APP_PRODUCT_NAME', 'Solid Waste Management System');
define('APP_PRODUCT_SHORT', 'SWMS');
define('APP_VERSION', '1.13.0');
define('APP_RELEASE_DATE', '2026-01-01');

/* ------------------------------------------------------------------ *
 | Paths (all relative to this file — no absolute/Windows paths)       |
 * ------------------------------------------------------------------ */
define('ROOT_PATH', __DIR__);
define('CORE_PATH', ROOT_PATH . '/core');
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('MODULES_PATH', ROOT_PATH . '/modules');
define('DATABASE_PATH', ROOT_PATH . '/database');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('LOG_PATH', STORAGE_PATH . '/logs');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('COMPANY_LOGO_PATH', UPLOAD_PATH . '/company/logos');
define('AVATAR_PATH', UPLOAD_PATH . '/company/avatars');
define('UC_MAP_PATH', UPLOAD_PATH . '/maps');

/* Public (browser-visible) sub-paths, relative to the app base URL. */
define('COMPANY_LOGO_URI', 'uploads/company/logos');
define('AVATAR_URI', 'uploads/company/avatars');
define('UC_MAP_URI', 'uploads/maps');

/* ------------------------------------------------------------------ *
 | Installation state                                                  |
 * ------------------------------------------------------------------ */
define('INSTALL_CONFIG_FILE', DATABASE_PATH . '/config.generated.php');
define('INSTALL_LOCK_FILE', DATABASE_PATH . '/installed.lock');
define('SCHEMA_FILE', DATABASE_PATH . '/schema.sql');

define('IS_INSTALLED', is_file(INSTALL_CONFIG_FILE) && is_file(INSTALL_LOCK_FILE));

/* ------------------------------------------------------------------ *
 | Environment defaults (overridden by the generated config)           |
 * ------------------------------------------------------------------ */
if (IS_INSTALLED) {
    require INSTALL_CONFIG_FILE;
}

defined('DB_HOST')    || define('DB_HOST', '');
defined('DB_PORT')    || define('DB_PORT', '3306');
defined('DB_NAME')    || define('DB_NAME', '');
defined('DB_USER')    || define('DB_USER', '');
defined('DB_PASS')    || define('DB_PASS', '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

/* development | production — controls error display only. */
defined('APP_ENV') || define('APP_ENV', 'production');

/* Optional hard override of the public URL (useful behind proxies).
   Left empty means: detect it at runtime. */
defined('APP_URL') || define('APP_URL', '');

/* Session + security tuning. */
define('SESSION_NAME', 'swms_session');
define('SESSION_IDLE_TIMEOUT', 3600);      // seconds of inactivity
define('SESSION_ABSOLUTE_TIMEOUT', 43200); // hard cap regardless of activity
define('REMEMBER_ME_DAYS', 30);
define('LOGIN_MAX_ATTEMPTS', 6);
define('LOGIN_LOCKOUT_SECONDS', 900);
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_RESET_TTL', 3600);
define('MAX_UPLOAD_BYTES', 2 * 1024 * 1024);

/* ------------------------------------------------------------------ *
 | Error reporting                                                     |
 * ------------------------------------------------------------------ */
error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');
ini_set('log_errors', '1');
if (is_dir(LOG_PATH) && is_writable(LOG_PATH)) {
    ini_set('error_log', LOG_PATH . '/php-error.log');
}

date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'UTC');

/* ------------------------------------------------------------------ *
 | Core library                                                        |
 * ------------------------------------------------------------------ */
require_once CORE_PATH . '/helpers.php';
require_once CORE_PATH . '/security.php';
require_once CORE_PATH . '/database.php';
require_once CORE_PATH . '/session.php';
require_once CORE_PATH . '/auth.php';
require_once CORE_PATH . '/permissions.php';
