<?php
/**
 * Updates the system-wide map tile source (URL + attribution).
 *
 * Super Admin only — this isn't a per-UC or even a structure.* concern,
 * it changes what every UC's map viewer loads. Free tile providers have
 * changed their terms without warning twice in quick succession (OSM's
 * usage policy blocking embedded apps, then CARTO requiring a key from
 * Aug 2026), so this exists specifically so fixing it again is "paste a
 * URL here" rather than a manual database edit.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Auth::requireLogin();

if (!Auth::isSuperAdmin()) {
    http_response_code(403);
    require INCLUDES_PATH . '/components/forbidden.php';
    exit;
}

if (!is_post()) {
    redirect('modules/structure/index.php');
}

$backTo = 'modules/structure/index.php';
Security::requireCsrf($backTo);

$tileUrl = trim(post('map_tile_url'));
$attribution = trim(post('map_tile_attribution'));

if ($tileUrl === '' || !str_contains($tileUrl, '{z}') || !str_contains($tileUrl, '{x}') || !str_contains($tileUrl, '{y}')) {
    flash('error', 'The tile URL must contain {z}, {x} and {y} placeholders.');
    redirect($backTo);
}
if ($attribution === '') {
    flash('error', 'Enter an attribution line — most tile providers require one.');
    redirect($backTo);
}

/* Free-form HTML is expected here (an <a> link to the provider), same
   trust level as this setting always had when it lived only in the
   database — only a Super Admin can reach this endpoint. */
Database::execute(
    "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('map_tile_url', :url, 'structure')
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
    ['url' => $tileUrl]
);
Database::execute(
    "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('map_tile_attribution', :attr, 'structure')
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
    ['attr' => $attribution]
);

Database::insert('activity_log', [
    'user_id' => Auth::id(), 'module' => 'structure', 'action' => 'map_tile_source_changed',
    'description' => 'Map tile source updated to ' . $tileUrl,
    'ip_address' => client_ip(),
]);

flash('success', 'Map tile source updated. Every UC\'s map now uses it.');
redirect($backTo);
