<?php
/**
 * Complaints — Before/After evidence and normal attachments.
 *
 * Evidence images: every upload is validated by its CONTENT (decoded as an
 * image, size and pixel limits), then stored three ways so the browser never
 * has to download more than it needs:
 *     thumb   ~360 px   gallery grids (lazy-loaded)
 *     preview ~1600 px  the modal viewer
 *     full    original  "open full size" / download / PDF source
 * Order is an explicit `sort_order` per (complaint, kind), never inferred from
 * file names or upload times, so the Before and After sequences are
 * independent and the PowerPoint uses exactly the order saved here.
 *
 * Everything is stored under storage/complaints/<id>/ (denied to the web
 * server) and delivered only by file.php after a permission + UC-scope check.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const CMP_IMAGE_MAX_BYTES = 25 * 1024 * 1024;
const CMP_IMAGE_MAX_SIDE = 12000;
const CMP_IMAGE_MAX_PIXELS = 40000000;   // 40 megapixels — keeps GD within memory
const CMP_ATTACH_MAX_BYTES = 10 * 1024 * 1024;
const CMP_THUMB_SIDE = 360;
const CMP_PREVIEW_SIDE = 1600;

const CMP_BLOCKED_EXTENSIONS = [
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'inc', 'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'scr',
    'vbs', 'js', 'jse', 'mjs', 'sh', 'bash', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'jar', 'htaccess', 'htpasswd',
    'html', 'htm', 'xhtml', 'svg', 'swf', 'hta', 'xlsm', 'docm', 'pptm', 'xlam', 'dotm', 'lnk', 'ps1',
];

const CMP_ATTACH_MIME = [
    'pdf' => 'application/pdf', 'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'jpg' => 'image/jpeg', 'png' => 'image/png',
];

/** storage/complaints, created with a deny-all .htaccess on first use. */
function cmp_storage_root(): string
{
    $dir = CMP_STORAGE_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "# Complaint files are private. They are served only by modules/complaints/file.php.\n" .
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n" .
            "php_flag engine off\nOptions -Indexes -ExecCGI\n"
        );
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

function cmp_complaint_dir(int $complaintId): string
{
    $dir = cmp_storage_root() . '/' . $complaintId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/** Full path of a stored file of a complaint, or null for an unsafe/missing name. */
function cmp_file_path(int $complaintId, ?string $file): ?string
{
    if ($file === null || $file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $file) || str_contains($file, '..')) {
        return null;
    }
    $path = CMP_STORAGE_DIR . '/' . $complaintId . '/' . $file;
    return is_file($path) ? $path : null;
}

/** URL of the gatekeeper for a file. $type: thumb | preview | full | attachment */
function cmp_file_url(int $complaintId, string $type, int $id, bool $download = false): string
{
    $q = ['c' => $complaintId, 't' => $type, 'i' => $id];
    if ($download) {
        $q['dl'] = 1;
    }
    return base_url('modules/complaints/file.php') . '?' . http_build_query($q);
}

function cmp_clean_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', $name) ?? '';
    return mb_substr(trim($name), 0, 150) ?: 'file';
}

/** Map a PHP upload error to a friendly sentence. */
function cmp_upload_error(int $code, int $limitBytes): ?string
{
    return match ($code) {
        UPLOAD_ERR_OK => null,
        UPLOAD_ERR_NO_FILE => 'No file was selected.',
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File size exceeds allowed limit (' . cmp_fmt_bytes($limitBytes) . ').',
        default => 'The file could not be uploaded.',
    };
}

/* ------------------------------------------------------------------ *
 | Images                                                              |
 * ------------------------------------------------------------------ */

/**
 * Check an uploaded image without storing it.
 *
 * @return array{ok:bool, message?:string, ext?:string, mime?:string, w?:int, h?:int}
 */
function cmp_validate_image(array $file): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'message' => 'Invalid upload.'];
    }
    if (($err = cmp_upload_error((int)$file['error'], CMP_IMAGE_MAX_BYTES)) !== null) {
        return ['ok' => false, 'message' => $err];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > CMP_IMAGE_MAX_BYTES) {
        return ['ok' => false, 'message' => 'File size exceeds allowed limit (' . cmp_fmt_bytes(CMP_IMAGE_MAX_BYTES) . ').'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Invalid upload.'];
    }
    foreach (explode('.', strtolower((string)($file['name'] ?? ''))) as $part) {
        if (in_array(trim($part), CMP_BLOCKED_EXTENSIONS, true)) {
            return ['ok' => false, 'message' => 'Invalid file format.'];
        }
    }
    $info = @getimagesize($tmp);
    if ($info === false) {
        return ['ok' => false, 'message' => 'Invalid file format — upload JPG, PNG or WEBP pictures.'];
    }
    $map = [IMAGETYPE_JPEG => ['jpg', 'image/jpeg'], IMAGETYPE_PNG => ['png', 'image/png'], IMAGETYPE_WEBP => ['webp', 'image/webp']];
    if (!isset($map[$info[2]])) {
        return ['ok' => false, 'message' => 'Invalid file format — upload JPG, PNG or WEBP pictures.'];
    }
    [$w, $h] = [(int)$info[0], (int)$info[1]];
    if ($w < 1 || $h < 1 || $w > CMP_IMAGE_MAX_SIDE || $h > CMP_IMAGE_MAX_SIDE || $w * $h > CMP_IMAGE_MAX_PIXELS) {
        return ['ok' => false, 'message' => 'The picture is too large (maximum ' . number_format(CMP_IMAGE_MAX_PIXELS / 1000000) . ' megapixels).'];
    }
    return ['ok' => true, 'ext' => $map[$info[2]][0], 'mime' => $map[$info[2]][1], 'w' => $w, 'h' => $h];
}

/** Load an image into GD with its EXIF rotation applied. */
function cmp_gd_load(string $path): ?GdImage
{
    $info = @getimagesize($path);
    if ($info === false) {
        return null;
    }
    $img = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false,
    };
    if ($img === false) {
        return null;
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $orientation = (int)($exif['Orientation'] ?? 1);
        $angle = match ($orientation) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($angle !== 0) {
            $rotated = @imagerotate($img, $angle, 0);
            if ($rotated !== false) {
                $img = $rotated;
            }
        }
    }
    return $img;
}

/** Write a resized JPEG (long side <= $side, never enlarged) to $dest. */
function cmp_gd_save_resized(GdImage $src, string $dest, int $side, int $quality): bool
{
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1.0, $side / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // flatten transparency
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = imagejpeg($dst, $dest, $quality);
    imagedestroy($dst);
    return $ok;
}

/**
 * Store one evidence picture at the END of the (complaint, kind) sequence.
 *
 * @return array{ok:bool, message?:string, item?:array}
 */
function cmp_add_evidence(int $complaintId, string $kind, array $file): array
{
    if (!in_array($kind, ['before', 'after'], true)) {
        return ['ok' => false, 'message' => 'Invalid evidence type.'];
    }
    $check = cmp_validate_image($file);
    if (!$check['ok']) {
        return $check;
    }
    @ini_set('memory_limit', '512M');
    @set_time_limit(120);

    $dir = cmp_complaint_dir($complaintId);
    if (!is_dir($dir) || !is_writable($dir)) {
        log_event('complaints', 'Evidence folder not writable', ['dir' => $dir]);
        return ['ok' => false, 'message' => 'The evidence folder is not writable. Contact the administrator.'];
    }

    $token = bin2hex(random_bytes(10));
    $fullName = $kind[0] . '-' . $token . '.' . $check['ext'];
    $thumbName = 't-' . $token . '.jpg';
    $previewName = 'p-' . $token . '.jpg';

    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $fullName)) {
        return ['ok' => false, 'message' => 'The file could not be saved.'];
    }
    @chmod($dir . '/' . $fullName, 0640);

    $img = cmp_gd_load($dir . '/' . $fullName);
    if ($img === null) {
        @unlink($dir . '/' . $fullName);
        return ['ok' => false, 'message' => 'The picture could not be read — it may be damaged.'];
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $okThumb = cmp_gd_save_resized($img, $dir . '/' . $thumbName, CMP_THUMB_SIDE, 78);
    $okPrev = cmp_gd_save_resized($img, $dir . '/' . $previewName, CMP_PREVIEW_SIDE, 84);
    imagedestroy($img);
    if (!$okThumb || !$okPrev) {
        foreach ([$fullName, $thumbName, $previewName] as $f) {
            @unlink($dir . '/' . $f);
        }
        return ['ok' => false, 'message' => 'Thumbnails could not be created.'];
    }

    try {
        Database::beginTransaction();
        /* Lock the complaint row so two simultaneous uploads can't take the same position. */
        Database::fetch('SELECT id FROM complaints WHERE id = :id FOR UPDATE', ['id' => $complaintId]);
        $next = (int)Database::fetchValue(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM complaint_evidence WHERE complaint_id = :c AND kind = :k',
            ['c' => $complaintId, 'k' => $kind]
        );
        $id = Database::insert('complaint_evidence', [
            'complaint_id' => $complaintId, 'kind' => $kind, 'sort_order' => $next,
            'file_name' => $fullName, 'thumb_name' => $thumbName, 'preview_name' => $previewName,
            'original_name' => cmp_clean_filename((string)($file['name'] ?? 'picture')), 'mime_type' => $check['mime'],
            'file_size' => (int)$file['size'], 'width' => $w, 'height' => $h,
            'uploaded_by' => Auth::id() ?: null, 'uploaded_at' => cmp_now(),
        ]);
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        foreach ([$fullName, $thumbName, $previewName] as $f) {
            @unlink($dir . '/' . $f);
        }
        log_event('complaints', 'Evidence insert failed', ['error' => $e->getMessage()]);
        return ['ok' => false, 'message' => 'The picture could not be saved.'];
    }

    return ['ok' => true, 'item' => cmp_evidence_item(['id' => $id, 'complaint_id' => $complaintId, 'kind' => $kind, 'sort_order' => $next,
        'original_name' => cmp_clean_filename((string)($file['name'] ?? 'picture')), 'width' => $w, 'height' => $h])];
}

/** The JSON/HTML-friendly description of one evidence row. */
function cmp_evidence_item(array $row): array
{
    $cid = (int)$row['complaint_id'];
    $id = (int)$row['id'];
    return [
        'id' => $id, 'kind' => (string)$row['kind'], 'order' => (int)$row['sort_order'], 'name' => (string)$row['original_name'],
        'width' => isset($row['width']) ? (int)$row['width'] : null, 'height' => isset($row['height']) ? (int)$row['height'] : null,
        'thumb' => cmp_file_url($cid, 'thumb', $id), 'preview' => cmp_file_url($cid, 'preview', $id), 'full' => cmp_file_url($cid, 'full', $id),
    ];
}

/** Evidence rows of one kind in saved order. */
function cmp_evidence_list(int $complaintId, string $kind): array
{
    return Database::fetchAll(
        'SELECT * FROM complaint_evidence WHERE complaint_id = :c AND kind = :k ORDER BY sort_order ASC, id ASC',
        ['c' => $complaintId, 'k' => $kind]
    );
}

/** Close any gaps so positions are 1..n again (after a delete). */
function cmp_resequence_evidence(int $complaintId, string $kind): void
{
    $n = 0;
    foreach (cmp_evidence_list($complaintId, $kind) as $row) {
        $n++;
        if ((int)$row['sort_order'] !== $n) {
            Database::execute('UPDATE complaint_evidence SET sort_order = :s WHERE id = :id', ['s' => $n, 'id' => (int)$row['id']]);
        }
    }
}

/**
 * Save a new order. $ids is the full list of this kind's picture ids in the
 * new order; ids that don't belong to this complaint/kind are ignored and any
 * picture missing from the list keeps its place at the end — nothing is lost.
 */
function cmp_reorder_evidence(int $complaintId, string $kind, array $ids): int
{
    $current = array_map('intval', array_column(cmp_evidence_list($complaintId, $kind), 'id'));
    $ordered = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if (in_array($id, $current, true) && !in_array($id, $ordered, true)) {
            $ordered[] = $id;
        }
    }
    foreach ($current as $id) {
        if (!in_array($id, $ordered, true)) {
            $ordered[] = $id;
        }
    }
    Database::beginTransaction();
    try {
        Database::fetch('SELECT id FROM complaints WHERE id = :id FOR UPDATE', ['id' => $complaintId]);
        foreach ($ordered as $i => $id) {
            Database::execute('UPDATE complaint_evidence SET sort_order = :s WHERE id = :id AND complaint_id = :c AND kind = :k', ['s' => $i + 1, 'id' => $id, 'c' => $complaintId, 'k' => $kind]);
        }
        Database::commit();
    } catch (Throwable $e) {
        Database::rollBack();
        throw $e;
    }
    return count($ordered);
}

/** Remove one picture (files + row) and close the gap it leaves. */
function cmp_delete_evidence(array $row): void
{
    $cid = (int)$row['complaint_id'];
    Database::execute('DELETE FROM complaint_evidence WHERE id = :id', ['id' => (int)$row['id']]);
    foreach (['file_name', 'thumb_name', 'preview_name'] as $col) {
        $path = cmp_file_path($cid, (string)$row[$col]);
        if ($path !== null) {
            @unlink($path);
        }
    }
    cmp_resequence_evidence($cid, (string)$row['kind']);
}

/* ------------------------------------------------------------------ *
 | Normal attachments                                                  |
 * ------------------------------------------------------------------ */

/** Detect an attachment's real type from its content. Whitelisted extension or null. */
function cmp_detect_attachment(string $path, string $clientExt): ?string
{
    $head = (string)@file_get_contents($path, false, null, 0, 16);
    if ($head === '') {
        return null;
    }
    $info = @getimagesize($path);
    if ($info !== false) {
        return match ($info[2]) { IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', default => null };
    }
    if (str_starts_with($head, '%PDF-')) {
        return 'pdf';
    }
    if (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") && in_array($clientExt, ['doc', 'xls'], true)) {
        return $clientExt;
    }
    if (str_starts_with($head, "PK\x03\x04") && in_array($clientExt, ['docx', 'xlsx'], true) && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $marker = $clientExt === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
            $ok = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($marker) !== false;
            $zip->close();
            return $ok ? $clientExt : null;
        }
    }
    return null;
}

/** @return array{ok:bool, message?:string, id?:int} */
function cmp_add_attachment(int $complaintId, array $file): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'message' => 'Invalid upload.'];
    }
    if (($err = cmp_upload_error((int)$file['error'], CMP_ATTACH_MAX_BYTES)) !== null) {
        return ['ok' => false, 'message' => $err];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > CMP_ATTACH_MAX_BYTES) {
        return ['ok' => false, 'message' => 'File size exceeds allowed limit (' . cmp_fmt_bytes(CMP_ATTACH_MAX_BYTES) . ').'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Invalid upload.'];
    }
    $clientName = strtolower((string)($file['name'] ?? ''));
    foreach (explode('.', $clientName) as $part) {
        if (in_array(trim($part), CMP_BLOCKED_EXTENSIONS, true)) {
            return ['ok' => false, 'message' => 'Invalid file format.'];
        }
    }
    $ext = cmp_detect_attachment($tmp, strtolower(pathinfo($clientName, PATHINFO_EXTENSION)));
    if ($ext === null) {
        return ['ok' => false, 'message' => 'Invalid file format — allowed: PDF, DOC/DOCX, XLS/XLSX, JPG, PNG.'];
    }
    $dir = cmp_complaint_dir($complaintId);
    if (!is_dir($dir) || !is_writable($dir)) {
        return ['ok' => false, 'message' => 'The attachment folder is not writable. Contact the administrator.'];
    }
    $name = 'a-' . bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
        return ['ok' => false, 'message' => 'The file could not be saved.'];
    }
    @chmod($dir . '/' . $name, 0640);
    $id = Database::insert('complaint_attachments', [
        'complaint_id' => $complaintId, 'file_name' => $name, 'original_name' => cmp_clean_filename((string)$file['name']),
        'mime_type' => CMP_ATTACH_MIME[$ext], 'file_size' => $size, 'uploaded_by' => Auth::id() ?: null, 'uploaded_at' => cmp_now(),
    ]);
    return ['ok' => true, 'id' => $id];
}

/** Normalise PHP's multi-file $_FILES shape into a list of single-file arrays. */
function cmp_files_list(string $field): array
{
    $entry = $_FILES[$field] ?? null;
    if (!is_array($entry) || !isset($entry['name'])) {
        return [];
    }
    if (!is_array($entry['name'])) {
        return ($entry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$entry];
    }
    $list = [];
    foreach (array_keys($entry['name']) as $i) {
        if (($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $list[] = ['name' => $entry['name'][$i], 'type' => $entry['type'][$i] ?? '', 'tmp_name' => $entry['tmp_name'][$i] ?? '',
                   'error' => $entry['error'][$i], 'size' => $entry['size'][$i] ?? 0];
    }
    return $list;
}

/**
 * True when PHP discarded the request because it exceeded post_max_size
 * (the form arrives completely empty, so a CSRF check would otherwise
 * report a misleading "session expired").
 */
function cmp_request_too_large(): bool
{
    return is_post() && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

function cmp_too_large_message(): string
{
    return 'The upload was larger than this server allows (post_max_size = ' . (string)ini_get('post_max_size')
        . ', upload_max_filesize = ' . (string)ini_get('upload_max_filesize') . '). Choose fewer or smaller pictures, or ask the administrator to raise these PHP limits.';
}
