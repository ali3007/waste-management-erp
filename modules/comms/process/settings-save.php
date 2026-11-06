<?php
/** Save Communication settings (form POST). Needs comms.access AND comms.settings. */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
comms_require('comms.settings');
if (!is_post()) {
    redirect('modules/comms/settings.php');
}
Security::requireCsrf('modules/comms/settings.php');

[$clean, $errors] = comms_validate_settings($_POST);
if ($errors) {
    keep_old(array_map(static fn($v): string => is_string($v) ? $v : '', $_POST));
    flash('error', implode(' ', $errors));
    redirect('modules/comms/settings.php');
}
$changed = comms_save_settings($clean, (int)Auth::id());
flash('success', $changed ? 'Settings saved.' : 'No changes to save.');
redirect('modules/comms/settings.php');
