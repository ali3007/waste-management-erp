<?php
/**
 * Gatekeeper for private Transport files. They live in storage/transport-files/
 * (denied to the web server), so this script is the ONLY way to reach them.
 * Each request is checked for sign-in, the matching permission and the
 * user's area scope over the vehicle — a guessed or edited id gets a 403/404.
 *
 *   ?k=photo&id=<vehicle>            needs transport.view or transport.details
 *   ?k=doc&id=<document>[&dl=1]      needs transport.documents_view (download: documents_download)
 *   ?k=invoice&id=<maintenance>      needs transport.maintenance_view
 *   ?k=receipt&id=<fuel record>      needs transport.fuel_view
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    http_response_code(503);
    exit('Not installed.');
}
Auth::requireLogin();

$kind = query('k');
$id = (int)query('id');
$download = query('dl') === '1';
$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Record not found.');
};
if ($id <= 0 || !in_array($kind, ['photo', 'doc', 'invoice', 'receipt'], true)) {
    $notFound();
}

$stored = null;
$vehicleId = 0;
$label = 'file';

if ($kind === 'photo') {
    if (!can('transport.view') && !can('transport.details')) {
        Permission::require('transport.details');
    }
    $row = Database::fetch('SELECT id, registration_no, picture_file FROM vehicles WHERE id = :id', ['id' => $id]);
    if ($row === null) { $notFound(); }
    $vehicleId = (int)$row['id'];
    $stored = $row['picture_file'];
    $label = $row['registration_no'] . '-photo';
} elseif ($kind === 'doc') {
    Permission::require($download ? 'transport.documents_download' : 'transport.documents_view');
    $row = Database::fetch('SELECT d.*, t.name AS type_name FROM vehicle_documents d JOIN vehicle_document_types t ON t.id = d.document_type_id WHERE d.id = :id', ['id' => $id]);
    if ($row === null) { $notFound(); }
    $vehicleId = (int)$row['vehicle_id'];
    $stored = $row['file_name'];
    $label = (string)$row['original_name'];
} elseif ($kind === 'invoice') {
    Permission::require('transport.maintenance_view');
    $row = Database::fetch('SELECT * FROM vehicle_maintenance WHERE id = :id', ['id' => $id]);
    if ($row === null) { $notFound(); }
    $vehicleId = (int)$row['vehicle_id'];
    $stored = $row['invoice_file'];
    $label = (string)($row['invoice_original'] ?: 'invoice');
} else {
    Permission::require('transport.fuel_view');
    $row = Database::fetch('SELECT * FROM vehicle_fuel_records WHERE id = :id', ['id' => $id]);
    if ($row === null) { $notFound(); }
    $vehicleId = (int)$row['vehicle_id'];
    $stored = $row['receipt_file'];
    $label = (string)($row['receipt_original'] ?: 'receipt');
}

trn_require_vehicle($vehicleId); // area scope — 403 when outside the user's UCs

$path = trn_file_path($stored);
if ($path === null) {
    $notFound();
}
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = HR_MIME_BY_EXT[$ext] ?? 'application/octet-stream';
$inlineOk = in_array($ext, ['jpg', 'png', 'gif', 'webp', 'pdf'], true) && !$download;
$downloadName = hr_clean_original_name($label);
if (!preg_match('/\.' . preg_quote($ext, '/') . '$/i', $downloadName)) {
    $downloadName .= '.' . $ext;
}

if ($download) {
    try {
        Database::insert('activity_log', [
            'user_id' => Auth::id(), 'module' => 'transport', 'action' => $kind . '.downloaded', 'subject_type' => 'vehicle',
            'subject_id' => $vehicleId, 'description' => mb_substr('Downloaded ' . $downloadName, 0, 250), 'ip_address' => client_ip(),
        ]);
    } catch (Throwable $e) {
        log_event('transport', 'Download log failed', ['error' => $e->getMessage()]);
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
header('Content-Disposition: ' . ($inlineOk ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', '\\', "\r", "\n"], '_', $downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
readfile($path);
exit;
