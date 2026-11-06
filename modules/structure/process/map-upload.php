<?php
/**
 * Uploads, replaces, or removes a UC's boundary file (KML/KMZ).
 * The heavy lifting — content validation, XXE and zip-bomb defence,
 * KMZ→KML normalisation — all lives in Security::storeMapFile();
 * this file is only the permission/CSRF gate and the database write.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('structure.map');

if (!is_post()) {
    redirect('modules/structure/index.php');
}

$ucId = (int)post('uc_id');
$backTo = base_url('modules/structure/uc-map.php') . '?id=' . $ucId;

/*
 * When an upload exceeds PHP's own post_max_size, PHP silently empties
 * the ENTIRE $_POST and $_FILES — including the CSRF token — leaving no
 * clean way to tell "the file was too big" from "the form was tampered
 * with". CONTENT_LENGTH is the one signal PHP still gives us in this
 * case, so it's checked before the CSRF check would otherwise produce a
 * confusing "session expired" for what is really just a large file.
 */
if (empty($_POST) && empty($_FILES)) {
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxBytes = map_upload_parse_ini_size((string)ini_get('post_max_size'));
    if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
        flash('error', 'That file is larger than this server currently allows ('
            . round($postMaxBytes / 1048576) . ' MB). Ask whoever manages the server to raise post_max_size in php.ini.');
        redirect($ucId > 0 ? $backTo : 'modules/structure/index.php');
    }
}

Security::requireCsrf($backTo);

$uc = structure_find('uc', $ucId);
if ($uc === null) {
    flash('error', 'That UC could not be found.');
    redirect('modules/structure/index.php');
}

$action = post('action', 'upload');

if ($action === 'remove') {
    Security::deleteUpload(UC_MAP_PATH, $uc['kml_file'] ?? null);
    Security::deleteUpload(UC_MAP_PATH, $uc['kmz_file'] ?? null);

    Database::update('ucs', [
        'kml_file' => null, 'kmz_file' => null,
        'map_uploaded_at' => null, 'map_uploaded_by' => null,
        'updated_by' => Auth::id(),
    ], ['id' => $ucId]);

    Database::insert('activity_log', [
        'user_id' => Auth::id(), 'module' => 'structure', 'action' => 'uc.map_removed',
        'subject_type' => 'uc', 'subject_id' => $ucId,
        'description' => 'Boundary file removed for ' . $uc['name'],
        'ip_address' => client_ip(),
    ]);

    flash('success', 'The boundary file was removed.');
    redirect($backTo);
}

/* Upload or replace. */
$result = Security::storeMapFile($_FILES['map_file'] ?? [], UC_MAP_PATH);

if (!$result['ok']) {
    flash('error', $result['message']);
    redirect($backTo);
}

$hadExisting = !empty($uc['kml_file']);

/* Clean up whatever was there before, only after the new files saved
   successfully — never leave a UC with no map because of a failed swap. */
Security::deleteUpload(UC_MAP_PATH, $uc['kml_file'] ?? null);
Security::deleteUpload(UC_MAP_PATH, $uc['kmz_file'] ?? null);

Database::update('ucs', [
    'kml_file' => $result['kml_file'],
    'kmz_file' => $result['kmz_file'] ?? null,
    'map_uploaded_at' => date('Y-m-d H:i:s'),
    'map_uploaded_by' => Auth::id(),
    'updated_by' => Auth::id(),
], ['id' => $ucId]);

Database::insert('activity_log', [
    'user_id' => Auth::id(), 'module' => 'structure',
    'action' => $hadExisting ? 'uc.map_replaced' : 'uc.map_uploaded',
    'subject_type' => 'uc', 'subject_id' => $ucId,
    'description' => ($hadExisting ? 'Boundary file replaced' : 'Boundary file uploaded') . ' for ' . $uc['name']
        . ' (' . strtoupper($result['kind']) . ')',
    'ip_address' => client_ip(),
]);

flash('success', 'Boundary file ' . ($hadExisting ? 'replaced' : 'uploaded') . '.');
redirect($backTo);

/** Parse a php.ini size string ("8M", "512K", "2G") into bytes. */
function map_upload_parse_ini_size(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '0') {
        return 0;
    }

    $unit = strtoupper(substr($value, -1));
    $number = (int)$value;

    return match ($unit) {
        'G' => $number * 1024 * 1024 * 1024,
        'M' => $number * 1024 * 1024,
        'K' => $number * 1024,
        default => (int)$value,
    };
}
