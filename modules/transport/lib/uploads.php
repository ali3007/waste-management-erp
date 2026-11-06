<?php
/**
 * Transport file uploads — vehicle pictures, vehicle documents, maintenance
 * invoices and fuel receipts.
 *
 * Built on the HR module's upload checks rather than re-inventing them:
 *   - the file TYPE comes from its CONTENT (hr_detect_type), never from the
 *     client's name or MIME type;
 *   - any executable/scriptable extension anywhere in the name is refused
 *     (HR_BLOCKED_EXTENSIONS), which also catches "shell.php.jpg";
 *   - stored names are generated, never taken from the client;
 *   - files live in storage/transport-files/, which the web server denies,
 *     and are delivered only through modules/transport/file.php after a
 *     permission + area-scope check.
 * Vehicle pictures are additionally re-encoded through GD (bounded size,
 * metadata stripped), which both optimises them and guarantees the stored
 * bytes really are an image.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const TRN_FILE_DIR = STORAGE_PATH . '/transport-files';
const TRN_PHOTO_MAX_BYTES = 4 * 1024 * 1024;
const TRN_DOC_MAX_BYTES = 8 * 1024 * 1024;
const TRN_PHOTO_MAX_SIDE = 1280;

/** What each upload slot accepts. */
function trn_upload_rules(string $kind): array
{
    return match ($kind) {
        'photo' => ['exts' => ['jpg', 'png', 'gif', 'webp'], 'max' => TRN_PHOTO_MAX_BYTES, 'label' => 'a JPG, PNG, GIF or WEBP image'],
        default => ['exts' => ['jpg', 'png', 'gif', 'webp', 'pdf'], 'max' => TRN_DOC_MAX_BYTES, 'label' => 'a PDF or a JPG, PNG, GIF or WEBP image'],
    };
}

/** Create storage/transport-files with a deny-all .htaccess on first use. */
function trn_file_dir(): string
{
    $dir = TRN_FILE_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "# Transport files are private. They are served only by modules/transport/file.php.\n" .
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n" .
            "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n" .
            "<IfModule mod_php7.c>\n    php_flag engine off\n</IfModule>\n" .
            "<IfModule mod_php8.c>\n    php_flag engine off\n</IfModule>\n"
        );
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

/** Full path of a stored file, or null if the name looks wrong or is missing. */
function trn_file_path(?string $file): ?string
{
    if ($file === null || $file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $file) || str_contains($file, '..')) {
        return null;
    }
    $path = TRN_FILE_DIR . '/' . $file;
    return is_file($path) ? $path : null;
}

function trn_delete_file(?string $file): void
{
    $path = trn_file_path($file);
    if ($path !== null) {
        @unlink($path);
    }
}

/**
 * Validate one uploaded file. Nothing is moved here.
 *
 * @return array{ok:bool, message?:string, ext?:string, mime?:string, size?:int}
 */
function trn_validate_upload(array $file, string $kind): array
{
    $rules = trn_upload_rules($kind);

    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'message' => 'Invalid upload.'];
    }
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return ['ok' => false, 'message' => 'No file was selected.'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['ok' => false, 'message' => 'File size exceeds the allowed limit (' . hr_size_label($rules['max']) . ').'];
        default:
            return ['ok' => false, 'message' => 'The file could not be uploaded.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'message' => 'The selected file is empty.'];
    }
    if ($size > $rules['max']) {
        return ['ok' => false, 'message' => 'File size exceeds the allowed limit (' . hr_size_label($rules['max']) . ').'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Invalid upload.'];
    }

    /* An executable/scriptable extension ANYWHERE in the name is refused. */
    $clientName = strtolower((string)($file['name'] ?? ''));
    foreach (explode('.', $clientName) as $part) {
        if (in_array(trim($part), HR_BLOCKED_EXTENSIONS, true)) {
            return ['ok' => false, 'message' => 'Invalid file format. Upload ' . $rules['label'] . '.'];
        }
    }

    $ext = hr_detect_type($tmp, strtolower(pathinfo($clientName, PATHINFO_EXTENSION)));
    if ($ext === null || !in_array($ext, $rules['exts'], true)) {
        return ['ok' => false, 'message' => 'Invalid file format. Upload ' . $rules['label'] . '.'];
    }

    return ['ok' => true, 'ext' => $ext, 'mime' => HR_MIME_BY_EXT[$ext], 'size' => $size];
}

/**
 * Re-encode an image to a bounded-size JPEG (white background under any
 * transparency). Returns the temp file path, or null if GD cannot do it.
 */
function trn_reencode_photo(string $path): ?string
{
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $bytes = @file_get_contents($path);
    $src = $bytes !== false ? @imagecreatefromstring($bytes) : false;
    if ($src === false) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 1 || $h < 1) {
        imagedestroy($src);
        return null;
    }
    $scale = min(1, TRN_PHOTO_MAX_SIDE / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $tmp = tempnam(sys_get_temp_dir(), 'trnimg');
    if ($tmp === false) {
        imagedestroy($dst);
        return null;
    }
    $ok = imagejpeg($dst, $tmp, 86);
    imagedestroy($dst);
    if (!$ok) {
        @unlink($tmp);
        return null;
    }
    return $tmp;
}

/**
 * Validate and store one file privately.
 *
 * @return array{ok:bool, message?:string, file?:string, original?:string, mime?:string, size?:int}
 */
function trn_store_upload(array $file, string $kind, int $vehicleId): array
{
    $check = trn_validate_upload($file, $kind);
    if (!$check['ok']) {
        return $check;
    }

    $dir = trn_file_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        log_event('transport', 'Transport file folder is not writable', ['dir' => $dir]);
        return ['ok' => false, 'message' => 'The file folder is not writable. Contact the administrator.'];
    }

    $ext = $check['ext'];
    $mime = $check['mime'];
    $size = $check['size'];
    $tmp = (string)$file['tmp_name'];
    $name = 'v' . $vehicleId . '-' . $kind . '-' . date('Ymd') . '-' . bin2hex(random_bytes(10)) . '.' . $ext;

    if ($kind === 'photo') {
        $re = trn_reencode_photo($tmp);
        if ($re !== null) {
            $name = 'v' . $vehicleId . '-photo-' . date('Ymd') . '-' . bin2hex(random_bytes(10)) . '.jpg';
            if (!@copy($re, $dir . '/' . $name)) {
                @unlink($re);
                return ['ok' => false, 'message' => 'The file could not be saved.'];
            }
            $size = (int)filesize($dir . '/' . $name);
            $mime = 'image/jpeg';
            @unlink($re);
            @chmod($dir . '/' . $name, 0640);
            return ['ok' => true, 'file' => $name, 'mime' => $mime, 'size' => $size,
                'original' => hr_clean_original_name((string)($file['name'] ?? 'photo'))];
        }
    }

    if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
        return ['ok' => false, 'message' => 'The file could not be saved.'];
    }
    @chmod($dir . '/' . $name, 0640);

    return ['ok' => true, 'file' => $name, 'mime' => $mime, 'size' => $size,
        'original' => hr_clean_original_name((string)($file['name'] ?? 'document'))];
}

/**
 * URL of the gatekeeper for a private file.
 * $kind: photo (vehicle id) | doc (document id) | invoice (maintenance id) | receipt (fuel id).
 */
function trn_file_url(string $kind, int $id, bool $download = false): string
{
    $q = ['k' => $kind, 'id' => $id];
    if ($download) {
        $q['dl'] = 1;
    }
    return base_url('modules/transport/file.php') . '?' . http_build_query($q);
}

function trn_is_image_file(?string $file): bool
{
    return $file !== null && (bool)preg_match('/\.(jpg|png|gif|webp)$/i', $file);
}
