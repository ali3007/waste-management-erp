<?php
/**
 * Optional scheduler for Communication reminders, overdue notices and reviewer checks.
 *
 * The module already runs these automatically (at most once a minute) whenever someone uses the system,
 * so this script is only needed if you want them to fire even when nobody is logged in.
 *   Windows Task Scheduler:  C:\xampp\php\php.exe C:\xampp\htdocs\<project>\tools\comms-cron.php   (every 5 minutes)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require_once dirname(__DIR__) . '/config.php';
require_once MODULES_PATH . '/comms/bootstrap.php';
$r = comms_run_scheduled(true);
echo date('Y-m-d H:i:s') . ' ' . json_encode($r) . "\n";
exit(isset($r['error']) ? 1 : 0);
