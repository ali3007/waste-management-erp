<?php
/**
 * Generic helpers: URLs, escaping, flash messages, logging.
 * No module-specific logic belongs in this file.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/**
 * Detect (or return the configured) public base URL, without a trailing slash.
 * Works identically on XAMPP sub-folders and on a production document root.
 */
function base_url(string $path = ''): string
{
    static $base = null;

    if ($base === null) {
        if (defined('APP_URL') && APP_URL !== '') {
            $base = rtrim(APP_URL, '/');
        } else {
            $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;

            $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

            /* Work out the folder the application lives in. */
            $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
            $rootReal  = str_replace('\\', '/', realpath(ROOT_PATH) ?: ROOT_PATH);
            $docReal   = str_replace('\\', '/', realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '');

            if ($docReal !== '' && str_starts_with($rootReal, $docReal)) {
                $folder = substr($rootReal, strlen($docReal));
            } else {
                /* Fallback: strip known sub-directories from the script path. */
                $folder = preg_replace('#/(install|auth|admin|modules|includes)(/.*)?$#', '', $scriptDir) ?? '';
            }

            $folder = '/' . trim(str_replace('\\', '/', (string)$folder), '/');
            $folder = $folder === '/' ? '' : $folder;

            $base = ($https ? 'https://' : 'http://') . $host . $folder;
        }
    }

    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

/** URL to a versioned asset (cache-busted on every release). */
function asset(string $path): string
{
    return base_url('assets/' . ltrim($path, '/')) . '?v=' . APP_VERSION;
}

/** Escape for HTML output. Use for every dynamic value in a template. */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Send a redirect and stop. */
function redirect(string $url, int $status = 302): never
{
    if (!preg_match('#^https?://#i', $url)) {
        $url = base_url($url);
    }
    if (!headers_sent()) {
        header('Location: ' . $url, true, $status);
    } else {
        echo '<meta http-equiv="refresh" content="0;url=' . e($url) . '">';
    }
    exit;
}

/** Emit a JSON response and stop (used by AJAX endpoints). */
function json_response(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, $status);
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Read a POST value as a trimmed string. */
function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

/** Read a GET value as a trimmed string. */
function query(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function is_post(): bool
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

function is_ajax(): bool
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

/** Store a one-shot message for the next request. */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** Read and clear all pending flash messages. */
function take_flash(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($messages) ? $messages : [];
}

/** Remember submitted form values so a failed form can be repopulated. */
function keep_old(array $values): void
{
    $_SESSION['_old'] = $values;
}

function old(string $key, string $default = ''): string
{
    return (string)($_SESSION['_old'][$key] ?? $default);
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

/** Append a line to an application log file. */
function log_event(string $channel, string $message, array $context = []): void
{
    if (!is_dir(LOG_PATH)) {
        @mkdir(LOG_PATH, 0775, true);
    }
    $line = sprintf(
        "[%s] %s: %s %s%s",
        date('Y-m-d H:i:s'),
        strtoupper($channel),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : '',
        PHP_EOL
    );
    @file_put_contents(LOG_PATH . '/app-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
}

/** Client IP, proxy-aware but never trusted for security decisions alone. */
function client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', (string)$_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

function user_agent(): string
{
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/**
 * Read a system setting (cached per request).
 * Returns $default before installation or if the table is unavailable.
 */
function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        if (IS_INSTALLED) {
            try {
                foreach (Database::fetchAll('SELECT setting_key, setting_value FROM system_settings') as $row) {
                    $cache[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Throwable $e) {
                log_event('settings', 'Unable to load system settings', ['error' => $e->getMessage()]);
            }
        }
    }

    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/** Company profile captured during installation (name, logo, contact). */
function company(): array
{
    static $company = null;

    if ($company === null) {
        $company = [
            'name'    => setting('company_name', APP_PRODUCT_NAME) ?? APP_PRODUCT_NAME,
            'logo'    => setting('company_logo', '') ?? '',
            'address' => setting('company_address', '') ?? '',
            'phone'   => setting('company_phone', '') ?? '',
            'email'   => setting('company_email', '') ?? '',
            'website' => setting('company_website', '') ?? '',
        ];
    }

    return $company;
}

/** Public URL of the company logo, or '' when none was uploaded. */
function company_logo_url(): string
{
    $logo = company()['logo'];
    if ($logo === '' || !is_file(COMPANY_LOGO_PATH . '/' . $logo)) {
        return '';
    }
    return base_url(COMPANY_LOGO_URI . '/' . rawurlencode($logo));
}

/** Public URL of a user's profile picture, or '' when none exists. */
function avatar_url(?string $file): string
{
    /* A stored value is only ever a bare file name made by Security::storeImage(); anything with a path
       in it (a hand-edited row, a bad import) is treated as "no picture" rather than followed. */
    if (!$file || basename($file) !== $file || !is_file(AVATAR_PATH . '/' . $file)) {
        return '';
    }
    return base_url(AVATAR_URI . '/' . rawurlencode($file));
}

/** Initials fallback used wherever an avatar or logo is missing. */
function initials(string $name, int $max = 2): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $out .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        if (mb_strlen($out) >= $max) {
            break;
        }
    }
    return $out === '' ? '?' : $out;
}

/** Format a stored datetime for display. */
function format_datetime(?string $value, string $format = 'd M Y, H:i'): string
{
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format($format);
    } catch (Throwable) {
        return '—';
    }
}
