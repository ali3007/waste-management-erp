<?php
/**
 * Employee document uploads — validation, private storage, serving.
 *
 * Rules this file enforces (spec sections 9 and 28):
 *   - The file's TYPE is decided from its CONTENT (image header, %PDF
 *     signature, OLE/ZIP structure), never from the client-supplied name
 *     or MIME type. The stored extension comes from that detection.
 *   - Only a short whitelist is accepted; anything executable or
 *     scriptable (php, html, svg, js, exe …) is rejected, including a
 *     "shell.php.jpg" style name, even though its content check would
 *     already fail.
 *   - Stored names are generated (random), never taken from the client.
 *   - Files live in storage/employee-docs/, which Apache denies outright
 *     (storage/.htaccess), and are only ever delivered through
 *     modules/hr/file.php after a permission + area-scope check.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const HR_PHOTO_MAX_BYTES = 2 * 1024 * 1024;
const HR_DOC_MAX_BYTES   = 5 * 1024 * 1024;
const HR_IMAGE_MAX_SIDE  = 12000; // pixels — refuses decompression-bomb images

/** Extensions that must never appear anywhere in an uploaded file's name. */
const HR_BLOCKED_EXTENSIONS = [
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'inc',
    'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'scr', 'vbs', 'js', 'jse', 'mjs',
    'sh', 'bash', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'jar',
    'htaccess', 'htpasswd', 'html', 'htm', 'xhtml', 'svg', 'swf', 'hta',
];

const HR_MIME_BY_EXT = [
    'jpg'  => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

/** What each upload slot accepts. */
function hr_upload_rules(string $kind): array
{
    return match ($kind) {
        'photo' => ['exts' => ['jpg', 'png', 'gif', 'webp'], 'max' => HR_PHOTO_MAX_BYTES,
                    'label' => 'a JPG, PNG, GIF or WEBP image'],
        'cnic'  => ['exts' => ['jpg', 'png', 'gif', 'webp', 'pdf'], 'max' => HR_DOC_MAX_BYTES,
                    'label' => 'a JPG, PNG, GIF or WEBP image, or a PDF'],
        default => ['exts' => ['jpg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx'], 'max' => HR_DOC_MAX_BYTES,
                    'label' => 'a PDF, Word document (DOC/DOCX) or image'],
    };
}

/** Human size limit for hints: "2 MB". */
function hr_size_label(int $bytes): string
{
    return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB';
}

/** Create storage/employee-docs with a deny-all .htaccess on first use. */
function hr_doc_dir(): string
{
    $dir = HR_DOC_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "# Employee documents are private. They are served only by modules/hr/file.php.\n" .
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n" .
            "php_flag engine off\nOptions -Indexes -ExecCGI\n"
        );
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

/** Full path of a stored document, or null if the name looks wrong. */
function hr_doc_path(?string $file): ?string
{
    if ($file === null || $file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $file) || str_contains($file, '..')) {
        return null;
    }
    $path = HR_DOC_DIR . '/' . $file;
    return is_file($path) ? $path : null;
}

/** Display-safe version of the client's file name (label only — never a path). */
function hr_clean_original_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', $name) ?? '';
    return mb_substr(trim($name), 0, 150) ?: 'document';
}

/**
 * Validate one uploaded file for a slot (photo | cnic | doc).
 * Nothing is moved here.
 *
 * @return array{ok:bool, message?:string, ext?:string, mime?:string, size?:int}
 */
function hr_validate_upload(array $file, string $kind): array
{
    $rules = hr_upload_rules($kind);

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
            return ['ok' => false, 'message' => 'File size exceeds allowed limit (' . hr_size_label($rules['max']) . ').'];
        default:
            return ['ok' => false, 'message' => 'The file could not be uploaded.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'message' => 'The selected file is empty.'];
    }
    if ($size > $rules['max']) {
        return ['ok' => false, 'message' => 'File size exceeds allowed limit (' . hr_size_label($rules['max']) . ').'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Invalid upload.'];
    }

    /* Any executable/scriptable extension anywhere in the name is refused
       outright — this catches "photo.php.jpg" and "x.jpg.exe". */
    $clientName = strtolower((string)($file['name'] ?? ''));
    foreach (explode('.', $clientName) as $part) {
        if (in_array(trim($part), HR_BLOCKED_EXTENSIONS, true)) {
            return ['ok' => false, 'message' => 'Invalid document format.'];
        }
    }

    $ext = hr_detect_type($tmp, strtolower(pathinfo($clientName, PATHINFO_EXTENSION)));
    if ($ext === null || !in_array($ext, $rules['exts'], true)) {
        return ['ok' => false, 'message' => 'Invalid document format. Upload ' . $rules['label'] . '.'];
    }

    return ['ok' => true, 'ext' => $ext, 'mime' => HR_MIME_BY_EXT[$ext], 'size' => $size];
}

/** Detect the real type from content. Returns a whitelisted extension or null. */
function hr_detect_type(string $path, string $clientExt): ?string
{
    $head = (string)@file_get_contents($path, false, null, 0, 16);
    if ($head === '') {
        return null;
    }

    /* Images: getimagesize reads the real header and rejects anything
       that merely has an image extension. */
    $info = @getimagesize($path);
    if ($info !== false) {
        $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
        if (!isset($map[$info[2]])) {
            return null;
        }
        if ($info[0] > HR_IMAGE_MAX_SIDE || $info[1] > HR_IMAGE_MAX_SIDE || $info[0] < 1 || $info[1] < 1) {
            return null;
        }
        return $map[$info[2]];
    }

    if (str_starts_with($head, '%PDF-')) {
        return 'pdf';
    }

    /* Legacy Word (OLE2 container). The signature alone is shared by every
       old Office format, so the client's extension must also say .doc. */
    if (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") && $clientExt === 'doc') {
        return 'doc';
    }

    /* DOCX is a ZIP: confirm the Word package structure, not just "PK". */
    if (str_starts_with($head, "PK\x03\x04") && $clientExt === 'docx') {
        if (!class_exists('ZipArchive')) {
            return null; // cannot verify it safely — refuse rather than guess
        }
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $isWord = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false;
            $zip->close();
            return $isWord ? 'docx' : null;
        }
    }

    return null;
}

/**
 * Validate and store one file privately.
 *
 * @return array{ok:bool, message?:string, file?:string, original?:string, mime?:string, size?:int}
 */
function hr_store_upload(array $file, string $kind, int $employeeId): array
{
    $check = hr_validate_upload($file, $kind);
    if (!$check['ok']) {
        return $check;
    }

    $dir = hr_doc_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        log_event('hr', 'Employee document folder is not writable', ['dir' => $dir]);
        return ['ok' => false, 'message' => 'The document folder is not writable. Contact the administrator.'];
    }

    $name = 'e' . $employeeId . '-' . $kind . '-' . date('Ymd') . '-' . bin2hex(random_bytes(10)) . '.' . $check['ext'];
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) {
        return ['ok' => false, 'message' => 'The file could not be saved.'];
    }
    @chmod($dir . '/' . $name, 0640);

    return [
        'ok' => true, 'file' => $name, 'mime' => $check['mime'], 'size' => $check['size'],
        'original' => hr_clean_original_name((string)($file['name'] ?? 'document')),
    ];
}

/** Remove a stored file; ignores anything that isn't a plain generated name. */
function hr_delete_stored_file(?string $file): void
{
    $path = hr_doc_path($file);
    if ($path !== null) {
        @unlink($path);
    }
}

/**
 * Turn PHP's awkward multi-file $_FILES shape
 * ($_FILES['x']['name'][0], …) into a list of single-file arrays.
 * Empty slots (no file chosen) are dropped.
 */
function hr_files_list(string $field): array
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
        $list[] = [
            'name' => $entry['name'][$i], 'type' => $entry['type'][$i] ?? '', 'tmp_name' => $entry['tmp_name'][$i] ?? '',
            'error' => $entry['error'][$i], 'size' => $entry['size'][$i] ?? 0,
        ];
    }
    return $list;
}

/** Was a single-file field actually filled in? */
function hr_has_upload(string $field): bool
{
    return isset($_FILES[$field]['error']) && !is_array($_FILES[$field]['error'])
        && $_FILES[$field]['error'] !== UPLOAD_ERR_NO_FILE;
}

/**
 * URL of the gatekeeper that serves a private file.
 * $kind: photo | cnic | doc ($docId required for doc).
 */
function hr_file_url(int $employeeId, string $kind, ?int $docId = null, bool $download = false): string
{
    $q = ['e' => $employeeId, 'k' => $kind];
    if ($docId !== null) {
        $q['d'] = $docId;
    }
    if ($download) {
        $q['dl'] = 1;
    }
    return base_url('modules/hr/file.php') . '?' . http_build_query($q);
}

/** Is a stored file an image the browser can show inline? */
function hr_is_image_file(?string $file): bool
{
    return $file !== null && (bool)preg_match('/\.(jpg|png|gif|webp)$/i', $file);
}
