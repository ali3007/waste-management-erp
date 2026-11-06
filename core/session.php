<?php
/**
 * Session lifecycle: hardened cookie settings, fixation protection,
 * idle/absolute timeouts and fingerprint checks.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

final class Session
{
    /** Start the session with secure parameters. Safe to call repeatedly. */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string)SESSION_ABSOLUTE_TIMEOUT);

        session_start();

        self::enforceFingerprint();
        self::enforceTimeouts();
    }

    /** Rotate the session id, keeping the data. Called after every login. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            $_SESSION['_created_at'] = time();
        }
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Destroy the session and its cookie completely. */
    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    /** Record a stable fingerprint; a mismatch means the cookie moved. */
    private static function enforceFingerprint(): void
    {
        $fingerprint = hash('sha256', user_agent() . '|' . SESSION_NAME);

        if (!isset($_SESSION['_fingerprint'])) {
            $_SESSION['_fingerprint'] = $fingerprint;
            $_SESSION['_created_at'] = time();
            return;
        }

        if (!hash_equals((string)$_SESSION['_fingerprint'], $fingerprint)) {
            log_event('security', 'Session fingerprint mismatch', ['ip' => client_ip()]);
            self::destroy();
            self::start();
        }
    }

    /** Expire idle sessions and cap total session age. */
    private static function enforceTimeouts(): void
    {
        $now = time();
        $last = (int)($_SESSION['_last_activity'] ?? $now);
        $created = (int)($_SESSION['_created_at'] ?? $now);

        $idleExpired = isset($_SESSION['user_id']) && ($now - $last) > SESSION_IDLE_TIMEOUT;
        $ageExpired  = isset($_SESSION['user_id']) && ($now - $created) > SESSION_ABSOLUTE_TIMEOUT;

        if ($idleExpired || $ageExpired) {
            self::destroy();
            self::start();
            flash('info', 'You were signed out because the session expired.');
            return;
        }

        $_SESSION['_last_activity'] = $now;
    }
}
