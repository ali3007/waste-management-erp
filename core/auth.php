<?php
/**
 * Authentication service.
 *
 * One unified identifier field: an email address resolves to an account
 * that logs in by email (Super Admin), anything else is treated as a CNIC
 * (normal ERP users). There is no public registration — accounts are
 * created from inside the ERP by a future User Management module.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

final class Auth
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_PENDING  = 'pending';
    public const STATUS_BLOCKED  = 'blocked';

    public const LOGIN_EMAIL = 'email';
    public const LOGIN_CNIC  = 'cnic';

    private static ?array $user = null;

    /* ---------------------------------------------------------------- *
     | Identifier handling                                               |
     * ---------------------------------------------------------------- */

    /**
     * Decide what the person typed into the single "Email / CNIC" field.
     *
     * @return array{type:string, value:string}|null
     */
    public static function resolveIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (str_contains($identifier, '@')) {
            return Security::isEmail($identifier)
                ? ['type' => self::LOGIN_EMAIL, 'value' => mb_strtolower($identifier)]
                : null;
        }

        $cnic = Security::normalizeCnic($identifier);
        return Security::isCnic($cnic)
            ? ['type' => self::LOGIN_CNIC, 'value' => $cnic]
            : null;
    }

    /* ---------------------------------------------------------------- *
     | Login                                                             |
     * ---------------------------------------------------------------- */

    /**
     * Attempt a login.
     *
     * @return array{ok:bool, message?:string, redirect?:string}
     */
    public static function attempt(string $identifier, string $password, bool $remember = false): array
    {
        $generic = 'Those credentials do not match an account.';

        if (self::isThrottled()) {
            return [
                'ok' => false,
                'message' => 'Too many failed attempts. Try again in ' . ceil(LOGIN_LOCKOUT_SECONDS / 60) . ' minutes.',
            ];
        }

        $resolved = self::resolveIdentifier($identifier);
        if ($resolved === null || $password === '') {
            self::recordAttempt($identifier, false);
            return ['ok' => false, 'message' => 'Enter a valid email address or 13-digit CNIC, and your password.'];
        }

        $column = $resolved['type'] === self::LOGIN_EMAIL ? 'email' : 'cnic';
        $user = Database::fetch(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.level AS role_level
               FROM users u
               LEFT JOIN roles r ON r.id = u.role_id
              WHERE u.' . $column . ' = :value
              LIMIT 1',
            ['value' => $resolved['value']]
        );

        /* Always spend time hashing so a missing account is not detectable. */
        if ($user === null) {
            password_verify($password, '$2y$12$usesomesillystringforsalt0000000000000000000000000000000000');
            self::recordAttempt($identifier, false);
            return ['ok' => false, 'message' => $generic];
        }

        if (!Security::verifyPassword($password, (string)$user['password_hash'])) {
            self::recordAttempt($identifier, false, (int)$user['id']);
            return ['ok' => false, 'message' => $generic];
        }

        /* The login method is tied to the account type, not to what was typed. */
        if ((string)$user['login_type'] !== $resolved['type']) {
            self::recordAttempt($identifier, false, (int)$user['id']);
            return ['ok' => false, 'message' => $generic];
        }

        if (($problem = self::statusProblem((string)$user['status'])) !== null) {
            self::recordAttempt($identifier, false, (int)$user['id']);
            return ['ok' => false, 'message' => $problem];
        }

        /* Upgrade the stored hash if PHP's default algorithm moved on. */
        if (Security::passwordNeedsRehash((string)$user['password_hash'])) {
            Database::update('users', ['password_hash' => Security::hashPassword($password)], ['id' => (int)$user['id']]);
        }

        self::login($user, $remember);
        self::recordAttempt($identifier, true, (int)$user['id']);

        return ['ok' => true, 'redirect' => self::homeFor($user)];
    }

    /** Human-readable reason a non-active account cannot sign in. */
    public static function statusProblem(string $status): ?string
    {
        return match ($status) {
            self::STATUS_ACTIVE   => null,
            self::STATUS_PENDING  => 'This account has not been activated yet. Use the activation link sent to you.',
            self::STATUS_INACTIVE => 'This account is inactive. Ask an administrator to reactivate it.',
            self::STATUS_BLOCKED  => 'This account is blocked. Contact your system administrator.',
            default               => 'This account cannot sign in.',
        };
    }

    /** Establish the authenticated session for a user row. */
    public static function login(array $user, bool $remember = false): void
    {
        Session::start();
        Session::regenerate();

        $_SESSION['user_id']     = (int)$user['id'];
        $_SESSION['role_id']     = (int)($user['role_id'] ?? 0);
        $_SESSION['role_slug']   = (string)($user['role_slug'] ?? '');
        $_SESSION['login_type']  = (string)$user['login_type'];
        $_SESSION['_created_at'] = time();
        $_SESSION['_last_activity'] = time();

        Database::update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => client_ip(),
        ], ['id' => (int)$user['id']]);

        Permission::flushCache();
        self::$user = null;

        if ($remember) {
            self::issueRememberCookie((int)$user['id']);
        }

        log_event('auth', 'Login succeeded', ['user_id' => (int)$user['id'], 'ip' => client_ip()]);
    }

    /** Clear the session, the remember-me token and the cookie. */
    public static function logout(): void
    {
        Session::start();
        $userId = (int)($_SESSION['user_id'] ?? 0);

        self::clearRememberCookie();

        if ($userId > 0) {
            log_event('auth', 'Logout', ['user_id' => $userId]);
        }

        Session::destroy();
        self::$user = null;
    }

    /* ---------------------------------------------------------------- *
     | State                                                             |
     * ---------------------------------------------------------------- */

    public static function check(): bool
    {
        Session::start();

        if (empty($_SESSION['user_id'])) {
            self::restoreFromRememberCookie();
        }

        if (empty($_SESSION['user_id'])) {
            return false;
        }

        $user = self::user();
        if ($user === null || $user['status'] !== self::STATUS_ACTIVE) {
            self::logout();
            return false;
        }

        return true;
    }

    /** The signed-in user row, or null. Cached per request. */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $userId = (int)(Session::get('user_id') ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $user = Database::fetch(
            'SELECT u.*, r.slug AS role_slug, r.name AS role_name, r.level AS role_level
               FROM users u
               LEFT JOIN roles r ON r.id = u.role_id
              WHERE u.id = :id
              LIMIT 1',
            ['id' => $userId]
        );

        return self::$user = $user;
    }

    public static function id(): int
    {
        return (int)(Session::get('user_id') ?? 0);
    }

    public static function isSuperAdmin(): bool
    {
        $user = self::user();
        return $user !== null && (string)$user['role_slug'] === Permission::ROLE_SUPER_ADMIN;
    }

    /** True when the signed-in user holds exactly the Administrator role. */
    public static function isAdministrator(): bool
    {
        $user = self::user();
        return $user !== null && (string)$user['role_slug'] === 'admin';
    }

    /** Redirect a guest to the login page, remembering where they wanted to go. */
    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }

        $intended = (string)($_SERVER['REQUEST_URI'] ?? '');
        if ($intended !== '' && !str_contains($intended, 'login.php')) {
            Session::start();
            Session::set('_intended', $intended);
        }

        flash('info', 'Sign in to continue.');
        redirect('auth/login.php');
    }

    /**
     * Restrict a page to an explicit set of role slugs.
     *
     * A guest is sent to the login page first, exactly like requireLogin().
     * A signed-in user whose role is not in the list sees the shared 403
     * page — the same one Permission::require() uses — so a denial looks
     * identical regardless of which check produced it.
     */
    public static function requireRole(array $allowedSlugs): void
    {
        self::requireLogin();

        $user = self::user();
        if ($user !== null && in_array((string)$user['role_slug'], $allowedSlugs, true)) {
            return;
        }

        log_event('auth', 'Role-restricted page denied', ['user_id' => self::id(), 'allowed' => $allowedSlugs]);

        if (is_ajax()) {
            json_response(['ok' => false, 'message' => 'You do not have access to this page.'], 403);
        }

        http_response_code(403);
        require INCLUDES_PATH . '/components/forbidden.php';
        exit;
    }

    /**
     * Where a user lands after signing in.
     *
     * Everyone shares one login form; this is the only place that branches
     * by role. The Administrator role gets its own dashboard — every other
     * role, including Super Admin, keeps the destination this always had.
     */
    public static function homeFor(array $user): string
    {
        $intended = (string)(Session::get('_intended') ?? '');
        Session::forget('_intended');

        if ($intended !== '' && !preg_match('#^https?://#i', $intended)) {
            return $intended;
        }

        if ((string)($user['role_slug'] ?? '') === 'admin') {
            return base_url('admin/administrator-dashboard.php');
        }

        return base_url('admin/dashboard.php');
    }

    /* ---------------------------------------------------------------- *
     | Rate limiting                                                     |
     * ---------------------------------------------------------------- */

    private static function throttleKey(): string
    {
        return hash('sha256', client_ip());
    }

    /** True when this IP has burned through the allowed failed attempts. */
    public static function isThrottled(): bool
    {
        try {
            $failures = (int)Database::fetchValue(
                'SELECT COUNT(*) FROM login_attempts
                  WHERE ip_hash = :ip AND successful = 0 AND attempted_at > (NOW() - INTERVAL :seconds SECOND)',
                ['ip' => self::throttleKey(), 'seconds' => LOGIN_LOCKOUT_SECONDS]
            );
            return $failures >= LOGIN_MAX_ATTEMPTS;
        } catch (Throwable) {
            return false;
        }
    }

    private static function recordAttempt(string $identifier, bool $successful, ?int $userId = null): void
    {
        try {
            Database::insert('login_attempts', [
                'identifier'   => mb_substr($identifier, 0, 190),
                'user_id'      => $userId,
                'ip_hash'      => self::throttleKey(),
                'user_agent'   => user_agent(),
                'successful'   => $successful ? 1 : 0,
                'attempted_at' => date('Y-m-d H:i:s'),
            ]);

            if ($successful) {
                Database::execute(
                    'DELETE FROM login_attempts WHERE ip_hash = :ip AND successful = 0',
                    ['ip' => self::throttleKey()]
                );
            }

            /* Opportunistic cleanup of old rows. */
            if (random_int(1, 20) === 1) {
                Database::execute('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 30 DAY)');
            }
        } catch (Throwable $e) {
            log_event('auth', 'Could not record login attempt', ['error' => $e->getMessage()]);
        }
    }

    /* ---------------------------------------------------------------- *
     | Remember me (split selector/validator tokens)                     |
     * ---------------------------------------------------------------- */

    private const REMEMBER_COOKIE = 'swms_remember';

    private static function issueRememberCookie(int $userId): void
    {
        try {
            $selector = bin2hex(random_bytes(9));
            $validator = Security::randomToken(32);
            $expires = time() + (REMEMBER_ME_DAYS * 86400);

            Database::insert('remember_tokens', [
                'user_id'        => $userId,
                'selector'       => $selector,
                'validator_hash' => Security::hashToken($validator),
                'user_agent'     => user_agent(),
                'expires_at'     => date('Y-m-d H:i:s', $expires),
            ]);

            setcookie(self::REMEMBER_COOKIE, $selector . ':' . $validator, [
                'expires'  => $expires,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } catch (Throwable $e) {
            log_event('auth', 'Could not issue remember token', ['error' => $e->getMessage()]);
        }
    }

    private static function restoreFromRememberCookie(): void
    {
        $cookie = (string)($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($cookie === '' || !str_contains($cookie, ':')) {
            return;
        }

        [$selector, $validator] = explode(':', $cookie, 2);

        try {
            $token = Database::fetch(
                'SELECT * FROM remember_tokens WHERE selector = :selector AND expires_at > NOW() LIMIT 1',
                ['selector' => $selector]
            );

            if ($token === null || !hash_equals((string)$token['validator_hash'], Security::hashToken($validator))) {
                self::clearRememberCookie();
                return;
            }

            $user = Database::fetch(
                'SELECT u.*, r.slug AS role_slug FROM users u
                   LEFT JOIN roles r ON r.id = u.role_id
                  WHERE u.id = :id AND u.status = :status LIMIT 1',
                ['id' => (int)$token['user_id'], 'status' => self::STATUS_ACTIVE]
            );

            if ($user === null) {
                self::clearRememberCookie();
                return;
            }

            /* Single-use: rotate the token on every automatic sign-in. */
            Database::execute('DELETE FROM remember_tokens WHERE id = :id', ['id' => (int)$token['id']]);
            self::login($user, true);
        } catch (Throwable $e) {
            log_event('auth', 'Remember-me restore failed', ['error' => $e->getMessage()]);
            self::clearRememberCookie();
        }
    }

    private static function clearRememberCookie(): void
    {
        $cookie = (string)($_COOKIE[self::REMEMBER_COOKIE] ?? '');
        if ($cookie !== '' && str_contains($cookie, ':')) {
            [$selector] = explode(':', $cookie, 2);
            try {
                Database::execute('DELETE FROM remember_tokens WHERE selector = :selector', ['selector' => $selector]);
            } catch (Throwable) {
                /* Nothing to clean up. */
            }
        }

        if (isset($_COOKIE[self::REMEMBER_COOKIE])) {
            setcookie(self::REMEMBER_COOKIE, '', [
                'expires'  => time() - 42000,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    /* ---------------------------------------------------------------- *
     | Password reset + activation foundation                            |
     * ---------------------------------------------------------------- */

    /**
     * Create a single-use reset token for an identifier.
     * The caller must not reveal whether the account existed.
     *
     * @return array{token:string, user:array}|null
     */
    public static function createPasswordReset(string $identifier): ?array
    {
        $resolved = self::resolveIdentifier($identifier);
        if ($resolved === null) {
            return null;
        }

        $column = $resolved['type'] === self::LOGIN_EMAIL ? 'email' : 'cnic';
        $user = Database::fetch(
            'SELECT * FROM users WHERE ' . $column . ' = :value LIMIT 1',
            ['value' => $resolved['value']]
        );

        if ($user === null || in_array((string)$user['status'], [self::STATUS_BLOCKED], true)) {
            return null;
        }

        $token = Security::randomToken();

        Database::execute(
            'UPDATE password_resets SET used_at = NOW() WHERE user_id = :id AND used_at IS NULL',
            ['id' => (int)$user['id']]
        );

        Database::insert('password_resets', [
            'user_id'    => (int)$user['id'],
            'token_hash' => Security::hashToken($token),
            'ip_hash'    => hash('sha256', client_ip()),
            'expires_at' => date('Y-m-d H:i:s', time() + PASSWORD_RESET_TTL),
        ]);

        log_event('auth', 'Password reset requested', ['user_id' => (int)$user['id']]);

        return ['token' => $token, 'user' => $user];
    }

    /** Look up a reset token, returning the user it belongs to. */
    public static function findPasswordReset(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        return Database::fetch(
            'SELECT pr.*, u.name, u.email, u.cnic, u.status
               FROM password_resets pr
               JOIN users u ON u.id = pr.user_id
              WHERE pr.token_hash = :hash AND pr.used_at IS NULL AND pr.expires_at > NOW()
              LIMIT 1',
            ['hash' => Security::hashToken($token)]
        );
    }

    /** Consume a reset token and set the new password. */
    public static function completePasswordReset(array $reset, string $newPassword): bool
    {
        try {
            Database::beginTransaction();

            Database::update('users', [
                'password_hash'       => Security::hashPassword($newPassword),
                'must_change_password' => 0,
                'password_changed_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int)$reset['user_id']]);

            Database::update('password_resets', ['used_at' => date('Y-m-d H:i:s')], ['id' => (int)$reset['id']]);

            /* Any remembered device must sign in again. */
            Database::execute('DELETE FROM remember_tokens WHERE user_id = :id', ['id' => (int)$reset['user_id']]);

            Database::commit();
            log_event('auth', 'Password reset completed', ['user_id' => (int)$reset['user_id']]);
            return true;
        } catch (Throwable $e) {
            Database::rollBack();
            log_event('auth', 'Password reset failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** Find a pending account by its activation token. */
    public static function findActivation(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        return Database::fetch(
            'SELECT * FROM users
              WHERE activation_token_hash = :hash
                AND status = :status
                AND (activation_expires_at IS NULL OR activation_expires_at > NOW())
              LIMIT 1',
            ['hash' => Security::hashToken($token), 'status' => self::STATUS_PENDING]
        );
    }

    /**
     * Activate a pending account: the user chooses their own password,
     * which is why the administrator never handles one.
     */
    public static function completeActivation(array $user, string $password): bool
    {
        try {
            Database::update('users', [
                'password_hash'         => Security::hashPassword($password),
                'status'                => self::STATUS_ACTIVE,
                'activation_token_hash' => null,
                'activation_expires_at' => null,
                'activated_at'          => date('Y-m-d H:i:s'),
                'must_change_password'  => 0,
                'password_changed_at'   => date('Y-m-d H:i:s'),
            ], ['id' => (int)$user['id']]);

            log_event('auth', 'Account activated', ['user_id' => (int)$user['id']]);
            return true;
        } catch (Throwable $e) {
            log_event('auth', 'Activation failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
