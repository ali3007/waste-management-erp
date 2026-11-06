<?php
/** Apply any pending attendance migrations by hand:  php modules/attendance/cron/migrate.php */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/bootstrap.php';
$applied = att_run_migrations();
echo $applied ? 'Applied: ' . implode(', ', $applied) . "\n" : "Nothing to apply — the database is up to date.\n";
