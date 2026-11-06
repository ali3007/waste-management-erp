<?php
/**
 * Security primitives: CSRF, validation, hashing, secure uploads.
 * Every form and upload in the system routes through this file.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

final class Security
{
    public const CSRF_KEY = '_csrf_token';

    /* ---------------------------------------------------------------- *
     | CSRF                                                              |
     * ---------------------------------------------------------------- */

    /** Return the session CSRF token, creating it on first use. */
    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            Session::start();
        }
        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION[self::CSRF_KEY];
    }

    /** Hidden input to drop inside every state-changing form. */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="' . self::CSRF_KEY . '" value="' . e(self::csrfToken()) . '">';
    }

    /** Constant-time comparison of a submitted token. */
    public static function verifyCsrf(?string $token = null): bool
    {
        $token ??= (string)($_POST[self::CSRF_KEY] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expected = (string)($_SESSION[self::CSRF_KEY] ?? '');
        return $expected !== '' && $token !== '' && hash_equals($expected, $token);
    }

    /**
     * Abort the request when the CSRF token is missing or wrong.
     * JSON endpoints get a JSON error; page requests get a flash + redirect.
     */
    public static function requireCsrf(string $redirectTo = 'index.php'): void
    {
        if (self::verifyCsrf()) {
            return;
        }
        log_event('security', 'CSRF token rejected', ['ip' => client_ip(), 'uri' => $_SERVER['REQUEST_URI'] ?? '']);

        if (is_ajax()) {
            json_response(['ok' => false, 'message' => 'Your session expired. Reload the page and try again.'], 419);
        }
        flash('error', 'Your session expired. Please try again.');
        redirect($redirectTo);
    }

    /* ---------------------------------------------------------------- *
     | Passwords                                                         |
     * ---------------------------------------------------------------- */

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $plain, string $hash): bool
    {
        return $hash !== '' && password_verify($plain, $hash);
    }

    /** True when the stored hash should be upgraded to the current algorithm. */
    public static function passwordNeedsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /**
     * Password policy. Returns an error message, or null when acceptable.
     */
    public static function passwordProblem(string $password): ?string
    {
        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Password must contain at least one letter and one number.';
        }
        return null;
    }

    /** URL-safe random token for activation and password reset links. */
    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Tokens are stored hashed so a database leak cannot be replayed. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /* ---------------------------------------------------------------- *
     | Validation                                                        |
     * ---------------------------------------------------------------- */

    public static function isEmail(string $email): bool
    {
        return (bool)filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190;
    }

    /** Strip formatting from a CNIC, leaving digits only. */
    public static function normalizeCnic(string $cnic): string
    {
        return preg_replace('/\D+/', '', $cnic) ?? '';
    }

    /** Pakistani CNIC: 13 digits, optionally entered with dashes. */
    public static function isCnic(string $cnic): bool
    {
        return (bool)preg_match('/^\d{13}$/', self::normalizeCnic($cnic));
    }

    /** Display form: 00000-0000000-0 */
    public static function formatCnic(string $cnic): string
    {
        $digits = self::normalizeCnic($cnic);
        if (strlen($digits) !== 13) {
            return $cnic;
        }
        return substr($digits, 0, 5) . '-' . substr($digits, 5, 7) . '-' . substr($digits, 12, 1);
    }

    /** Collapse whitespace and strip control characters from free text. */
    public static function cleanText(string $value, int $maxLength = 255): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        return mb_substr(trim($value), 0, $maxLength);
    }

    /** Multi-line free text (address, notes). */
    public static function cleanMultiline(string $value, int $maxLength = 1000): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = preg_replace('/\r\n?/', "\n", $value) ?? '';
        return mb_substr(trim($value), 0, $maxLength);
    }

    /* ---------------------------------------------------------------- *
     | File uploads                                                      |
     * ---------------------------------------------------------------- */

    /**
     * Validate and store an uploaded image.
     *
     * Checks the real image type (not the client-supplied name or MIME),
     * forces a safe generated filename and a whitelisted extension.
     *
     * @return array{ok:bool, file?:string, message?:string}
     */
    public static function storeImage(array $file, string $targetDir, string $prefix = 'img'): array
    {
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
                return ['ok' => false, 'message' => 'The file is larger than the server allows.'];
            default:
                return ['ok' => false, 'message' => 'The file could not be uploaded.'];
        }

        if (($file['size'] ?? 0) <= 0 || $file['size'] > MAX_UPLOAD_BYTES) {
            return ['ok' => false, 'message' => 'Choose an image smaller than ' . round(MAX_UPLOAD_BYTES / 1048576, 1) . ' MB.'];
        }

        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            return ['ok' => false, 'message' => 'Invalid upload.'];
        }

        $allowed = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG  => 'png',
            IMAGETYPE_GIF  => 'gif',
            IMAGETYPE_WEBP => 'webp',
        ];

        $info = @getimagesize($file['tmp_name']);
        if ($info === false || !isset($allowed[$info[2]])) {
            return ['ok' => false, 'message' => 'Upload a JPG, PNG, GIF or WEBP image.'];
        }

        /* SVG and other scriptable formats are intentionally rejected above. */
        $extension = $allowed[$info[2]];
        $name = $prefix . '-' . date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return ['ok' => false, 'message' => 'The upload folder could not be created.'];
        }
        if (!is_writable($targetDir)) {
            return ['ok' => false, 'message' => 'The upload folder is not writable.'];
        }

        $destination = rtrim($targetDir, '/\\') . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['ok' => false, 'message' => 'The file could not be saved.'];
        }

        @chmod($destination, 0644);
        return ['ok' => true, 'file' => $name];
    }

    /** Delete a previously stored upload, ignoring traversal attempts. */
    public static function deleteUpload(string $directory, ?string $file): void
    {
        if (!$file || str_contains($file, '/') || str_contains($file, '\\') || str_contains($file, '..')) {
            return;
        }
        $path = rtrim($directory, '/\\') . '/' . $file;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Baseline response headers for every HTML page. */
    /* ---------------------------------------------------------------- *
     | Map files (KML/KMZ) — UC boundary uploads                        |
     * ---------------------------------------------------------------- */

    private const MAP_MAX_BYTES = 10 * 1024 * 1024;      // 10 MB — boundary data can be large
    private const MAP_MAX_ZIP_ENTRIES = 200;              // a legitimate KMZ has one KML, maybe a few images
    private const MAP_MAX_UNCOMPRESSED_BYTES = 20 * 1024 * 1024; // zip-bomb guard

    /**
     * Confirm a string is a well-formed KML document, safely.
     *
     * The XXE defence here is not the file extension or a MIME sniff —
     * it's that external entities are never resolved. Modern libxml2
     * (bundled with PHP 5.4+) already refuses external entities unless a
     * caller explicitly re-enables them; this method deliberately never
     * passes LIBXML_NOENT (entity substitution) or a custom entity
     * loader, and adds LIBXML_NONET so parsing can't touch the network
     * even if a future PHP/libxml change altered the default.
     */
    private static function isValidKml(string $contents): bool
    {
        if (!class_exists('DOMDocument')) {
            log_event('security', 'DOMDocument unavailable — cannot validate KML uploads');
            return false;
        }

        if ($contents === '' || !str_contains($contents, '<')) {
            return false;
        }

        /*
         * Belt and suspenders beyond the LIBXML_NONET/no-NOENT defence
         * below: legitimate KML never declares a DOCTYPE or internal DTD
         * subset. Rejecting one outright costs nothing (no real KML file
         * needs it) and closes off entity-based attacks entirely,
         * independent of whatever a given server's libxml build defaults
         * to — rather than relying only on that default holding.
         */
        if (preg_match('/<!DOCTYPE/i', $contents) === 1) {
            return false;
        }

        $previousErrorMode = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = @$doc->loadXML($contents, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);

        return $loaded
            && $doc->documentElement !== null
            && strtolower($doc->documentElement->localName) === 'kml';
    }

    /**
     * Pull the first .kml entry out of a KMZ (a KMZ is just a ZIP).
     * Guards against zip bombs (entry count and total uncompressed size)
     * and path traversal in entry names before anything is read.
     *
     * @return array{ok:bool, message?:string, contents?:string}
     */
    private static function extractKmlFromKmz(string $kmzPath): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'message' => 'The server is missing the PHP zip extension needed to read KMZ files.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($kmzPath) !== true) {
            return ['ok' => false, 'message' => 'That file is not a valid KMZ archive.'];
        }

        if ($zip->numFiles > self::MAP_MAX_ZIP_ENTRIES) {
            $zip->close();
            return ['ok' => false, 'message' => 'That archive contains too many files to be a normal KMZ.'];
        }

        $kmlEntryName = null;
        $totalUncompressed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $totalUncompressed += (int)$stat['size'];
            if ($totalUncompressed > self::MAP_MAX_UNCOMPRESSED_BYTES) {
                $zip->close();
                return ['ok' => false, 'message' => 'That archive is too large once extracted.'];
            }

            $name = (string)$stat['name'];
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                continue; // never trust an entry name for anything, including which one we pick
            }

            if ($kmlEntryName === null && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'kml') {
                $kmlEntryName = $name;
            }
        }

        if ($kmlEntryName === null) {
            $zip->close();
            return ['ok' => false, 'message' => 'That KMZ archive does not contain a KML file.'];
        }

        $contents = $zip->getFromName($kmlEntryName);
        $zip->close();

        if ($contents === false || $contents === '') {
            return ['ok' => false, 'message' => 'The KML inside that archive could not be read.'];
        }

        return ['ok' => true, 'contents' => $contents];
    }

    /**
     * Validate and store a UC boundary upload — KML or KMZ, decided by
     * actual file content, never by the extension the browser reported.
     *
     * A KMZ is normalised on upload: its inner KML is extracted and
     * stored as its own file, so every UC has a plain .kml the map
     * viewer can load the same way regardless of what was uploaded. The
     * original KMZ is also kept, for anyone who wants the raw file back.
     *
     * @return array{ok:bool, message?:string, kind?:string, kml_file?:string, kmz_file?:string}
     */
    public static function storeMapFile(array $file, string $targetDir): array
    {
        if (!class_exists('DOMDocument')) {
            return ['ok' => false, 'message' => 'The server is missing PHP\'s XML support, needed to safely read KML files.'];
        }

        if (!isset($file['error']) || is_array($file['error'])) {
            return ['ok' => false, 'message' => 'Invalid upload.'];
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return ['ok' => false, 'message' => 'Choose a KML or KMZ file first.'];
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return ['ok' => false, 'message' => 'The file is larger than the server allows.'];
            default:
                return ['ok' => false, 'message' => 'The file could not be uploaded.'];
        }

        if (($file['size'] ?? 0) <= 0 || $file['size'] > self::MAP_MAX_BYTES) {
            return ['ok' => false, 'message' => 'Choose a file smaller than ' . round(self::MAP_MAX_BYTES / 1048576) . ' MB.'];
        }

        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            return ['ok' => false, 'message' => 'Invalid upload.'];
        }

        $tmpPath = $file['tmp_name'];
        $header = (string)@file_get_contents($tmpPath, false, null, 0, 4);
        $looksLikeZip = str_starts_with($header, "PK\x03\x04") || str_starts_with($header, "PK\x05\x06");

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return ['ok' => false, 'message' => 'The upload folder could not be created.'];
        }
        if (!is_writable($targetDir)) {
            return ['ok' => false, 'message' => 'The upload folder is not writable.'];
        }

        $stamp = date('Ymd') . '-' . bin2hex(random_bytes(8));

        if ($looksLikeZip) {
            $extracted = self::extractKmlFromKmz($tmpPath);
            if (!$extracted['ok']) {
                return $extracted;
            }
            if (!self::isValidKml($extracted['contents'])) {
                return ['ok' => false, 'message' => 'The KML inside that archive is not valid.'];
            }

            $kmzName = 'map-' . $stamp . '.kmz';
            $kmlName = 'map-' . $stamp . '.kml';

            if (!move_uploaded_file($tmpPath, $targetDir . '/' . $kmzName)) {
                return ['ok' => false, 'message' => 'The file could not be saved.'];
            }
            if (@file_put_contents($targetDir . '/' . $kmlName, $extracted['contents']) === false) {
                @unlink($targetDir . '/' . $kmzName);
                return ['ok' => false, 'message' => 'The extracted KML could not be saved.'];
            }

            @chmod($targetDir . '/' . $kmzName, 0644);
            @chmod($targetDir . '/' . $kmlName, 0644);

            return ['ok' => true, 'kind' => 'kmz', 'kml_file' => $kmlName, 'kmz_file' => $kmzName];
        }

        /* Not a ZIP — must be plain KML, and it's read into memory (never
           trusted from disk-by-extension) and validated before it's kept. */
        $contents = (string)@file_get_contents($tmpPath, false, null, 0, self::MAP_MAX_BYTES + 1);
        if (!self::isValidKml($contents)) {
            return ['ok' => false, 'message' => 'Choose a valid KML or KMZ file.'];
        }

        $kmlName = 'map-' . $stamp . '.kml';
        if (!move_uploaded_file($tmpPath, $targetDir . '/' . $kmlName)) {
            return ['ok' => false, 'message' => 'The file could not be saved.'];
        }
        @chmod($targetDir . '/' . $kmlName, 0644);

        return ['ok' => true, 'kind' => 'kml', 'kml_file' => $kmlName];
    }

    public static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        header('X-XSS-Protection: 0');
        header_remove('X-Powered-By');
    }
}

/* Short aliases used throughout the templates. */
function csrf_field(): string { return Security::csrfField(); }
function csrf_token(): string { return Security::csrfToken(); }
