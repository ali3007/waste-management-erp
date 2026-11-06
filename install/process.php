<?php
/**
 * Installation endpoint.
 *
 * Handles three actions, each re-validated here regardless of what the
 * browser checked: `database`, `company` and `install`. Answers are held
 * in the installer session until the final step commits everything.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

if (IS_INSTALLED) {
    json_response(['ok' => false, 'message' => 'The application is already installed.'], 403);
}

Session::start();
Security::requireCsrf();

if (!is_post()) {
    json_response(['ok' => false, 'message' => 'Unsupported request.'], 405);
}

$action = post('action');

match ($action) {
    'database' => handleDatabase(),
    'company'  => handleCompany(),
    'install'  => handleInstall(),
    default    => json_response(['ok' => false, 'message' => 'Unknown installation step.'], 400),
};

/* ------------------------------------------------------------------ *
 | Step 2 — verify the database credentials before storing them        |
 * ------------------------------------------------------------------ */
function handleDatabase(): never
{
    $host = Security::cleanText(post('db_host'), 190);
    $port = Security::cleanText(post('db_port', '3306'), 5);
    $name = Security::cleanText(post('db_name'), 64);
    $user = Security::cleanText(post('db_user'), 64);
    $pass = (string)($_POST['db_pass'] ?? '');

    $errors = [];
    if ($host === '') { $errors['db_host'] = 'Enter the database host.'; }
    if ($name === '') { $errors['db_name'] = 'Enter the database name.'; }
    if ($user === '') { $errors['db_user'] = 'Enter the database username.'; }
    if ($port !== '' && !ctype_digit($port)) { $errors['db_port'] = 'The port must be a number.'; }
    if ($name !== '' && !preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
        $errors['db_name'] = 'Database names may contain letters, numbers, hyphens and underscores.';
    }

    if ($errors) {
        json_response(['ok' => false, 'message' => 'Check the database details.', 'errors' => $errors], 422);
    }

    try {
        $pdo = Database::connect($host, $port !== '' ? $port : '3306', $name, $user, $pass);

        /* Confirm the account can actually create tables. */
        $pdo->exec('CREATE TABLE IF NOT EXISTS `swms_install_probe` (`id` TINYINT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $pdo->exec('DROP TABLE IF EXISTS `swms_install_probe`');
    } catch (Throwable $e) {
        json_response([
            'ok' => false,
            'message' => Database::friendlyError($e),
            'errors' => ['db_name' => 'Connection failed.'],
        ], 422);
    }

    /* Refuse to overwrite an existing installation's data. */
    $existing = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    if ($existing !== false) {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM `users`')->fetchColumn();
        if ($count > 0) {
            json_response([
                'ok' => false,
                'message' => 'This database already contains a Solid Waste Management System installation. '
                    . 'Use an empty database, or restore the config file from your backup.',
            ], 409);
        }
    }

    $_SESSION['install']['database'] = [
        'host' => $host,
        'port' => $port !== '' ? $port : '3306',
        'name' => $name,
        'user' => $user,
        'pass' => $pass,
    ];

    json_response(['ok' => true, 'message' => 'Connected to the database successfully.']);
}

/* ------------------------------------------------------------------ *
 | Step 3 — company profile and optional logo                          |
 * ------------------------------------------------------------------ */
function handleCompany(): never
{
    if (empty($_SESSION['install']['database'])) {
        json_response(['ok' => false, 'message' => 'Complete the database step first.'], 409);
    }

    $name = Security::cleanText(post('company_name'), 120);
    $email = Security::cleanText(post('company_email'), 190);
    $website = Security::cleanText(post('company_website'), 190);

    $errors = [];
    if ($name === '') {
        $errors['company_name'] = 'Enter the company name.';
    }
    if ($email !== '' && !Security::isEmail($email)) {
        $errors['company_email'] = 'Enter a valid email address.';
    }
    if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
        $errors['company_website'] = 'Enter a full address, including https://';
    }

    if ($errors) {
        json_response(['ok' => false, 'message' => 'Check the company details.', 'errors' => $errors], 422);
    }

    $company = [
        'name'    => $name,
        'address' => Security::cleanMultiline(post('company_address'), 500),
        'phone'   => Security::cleanText(post('company_phone'), 30),
        'email'   => $email,
        'website' => $website,
        'logo'    => $_SESSION['install']['company']['logo'] ?? '',
    ];

    /* The logo is optional; store it now so a retry of a later step keeps it. */
    if (!empty($_FILES['company_logo']['name'])) {
        $stored = Security::storeImage($_FILES['company_logo'], COMPANY_LOGO_PATH, 'logo');
        if (!$stored['ok']) {
            json_response(['ok' => false, 'message' => $stored['message'], 'errors' => ['company_logo' => $stored['message']]], 422);
        }
        Security::deleteUpload(COMPANY_LOGO_PATH, $company['logo'] ?: null);
        $company['logo'] = $stored['file'];
    }

    $_SESSION['install']['company'] = $company;

    json_response(['ok' => true, 'message' => 'Company details saved.']);
}

/* ------------------------------------------------------------------ *
 | Step 4 — create everything, then lock the installer                 |
 * ------------------------------------------------------------------ */
function handleInstall(): never
{
    $database = $_SESSION['install']['database'] ?? null;
    $company  = $_SESSION['install']['company'] ?? null;

    if (!$database || !$company) {
        json_response(['ok' => false, 'message' => 'Some steps are missing. Start the wizard again.'], 409);
    }

    $name = Security::cleanText(post('admin_name'), 120);
    $email = mb_strtolower(Security::cleanText(post('admin_email'), 190));
    $password = (string)($_POST['admin_password'] ?? '');
    $confirm = (string)($_POST['admin_password_confirm'] ?? '');

    $errors = [];
    if ($name === '') {
        $errors['admin_name'] = 'Enter the administrator name.';
    }
    if (!Security::isEmail($email)) {
        $errors['admin_email'] = 'Enter a valid email address.';
    }
    if (($problem = Security::passwordProblem($password)) !== null) {
        $errors['admin_password'] = $problem;
    }
    if ($password !== $confirm) {
        $errors['admin_password_confirm'] = 'Passwords do not match.';
    }

    if ($errors) {
        json_response(['ok' => false, 'message' => 'Check the administrator details.', 'errors' => $errors], 422);
    }

    /* Connect with the verified credentials. */
    try {
        $pdo = Database::connect($database['host'], $database['port'], $database['name'], $database['user'], $database['pass']);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => Database::friendlyError($e)], 500);
    }

    $picture = null;
    if (!empty($_FILES['admin_picture']['name'])) {
        $stored = Security::storeImage($_FILES['admin_picture'], AVATAR_PATH, 'avatar');
        if (!$stored['ok']) {
            json_response(['ok' => false, 'message' => $stored['message'], 'errors' => ['admin_picture' => $stored['message']]], 422);
        }
        $picture = $stored['file'];
    }

    try {
        runSchema($pdo);

        /* A duplicate address is only possible if someone raced the wizard. */
        $duplicate = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $duplicate->execute([$email]);
        if ($duplicate->fetchColumn() !== false) {
            json_response([
                'ok' => false,
                'message' => 'An account with that email address already exists in this database.',
                'errors' => ['admin_email' => 'This email address is already in use.'],
            ], 409);
        }

        $pdo->beginTransaction();

        $roleId = seedRoles($pdo);
        seedPermissions($pdo, $roleId);
        seedSettings($pdo, $company);

        $insert = $pdo->prepare(
            'INSERT INTO users (role_id, name, email, password_hash, login_type, status, profile_picture,
                                activated_at, password_changed_at, created_at)
             VALUES (:role_id, :name, :email, :hash, :login_type, :status, :picture, NOW(), NOW(), NOW())'
        );
        $insert->execute([
            'role_id'    => $roleId,
            'name'       => $name,
            'email'      => $email,
            'hash'       => Security::hashPassword($password),
            'login_type' => Auth::LOGIN_EMAIL,
            'status'     => Auth::STATUS_ACTIVE,
            'picture'    => $picture,
        ]);

        $pdo->prepare(
            'INSERT INTO activity_log (user_id, module, action, description, ip_address)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            (int)$pdo->lastInsertId(), 'core', 'install.completed',
            'Installation completed for ' . $company['name'], client_ip(),
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['ok' => false, 'message' => Database::friendlyError($e)], 500);
    }

    /* Write the config file only once the database work has succeeded. */
    if (!writeConfigFile($database)) {
        json_response([
            'ok' => false,
            'message' => 'The database is ready, but database/config.generated.php could not be written. '
                . 'Give the database/ folder write permission and run the wizard again.',
        ], 500);
    }

    if (!writeLockFile($company['name'], $email)) {
        json_response(['ok' => false, 'message' => 'The installation lock file could not be written.'], 500);
    }

    $_SESSION['install_summary'] = [
        'company'  => $company['name'],
        'database' => $database['name'],
        'email'    => $email,
        'at'       => date('Y-m-d H:i:s'),
    ];
    unset($_SESSION['install']);

    json_response(['ok' => true, 'redirect' => base_url('install/complete.php')]);
}

/* ------------------------------------------------------------------ *
 | Helpers                                                             |
 * ------------------------------------------------------------------ */

/** Execute database/schema.sql statement by statement. */
function runSchema(PDO $pdo): void
{
    if (!is_file(SCHEMA_FILE)) {
        throw new RuntimeException('database/schema.sql is missing.');
    }

    $sql = (string)file_get_contents(SCHEMA_FILE);

    /* Strip comment lines, then split on statement terminators. */
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }
}

/** Create the system roles and return the Super Admin role id. */
function seedRoles(PDO $pdo): int
{
    $roles = [
        ['Super Admin', Permission::ROLE_SUPER_ADMIN, 'Full access to every module and setting.', 100, 1],
        ['Administrator', 'admin', 'Day-to-day administration, without system-level settings.', 80, 1],
    ];

    $statement = $pdo->prepare(
        'INSERT INTO roles (name, slug, description, level, is_system)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), level = VALUES(level)'
    );

    foreach ($roles as $role) {
        $statement->execute($role);
    }

    $lookup = $pdo->prepare('SELECT id FROM roles WHERE slug = ? LIMIT 1');
    $lookup->execute([Permission::ROLE_SUPER_ADMIN]);

    return (int)$lookup->fetchColumn();
}

/**
 * Seed the permissions the authentication foundation needs.
 * Future modules append their own rows through Permission::register().
 */
function seedPermissions(PDO $pdo, int $superAdminRoleId): void
{
    $permissions = [
        ['system', 'settings', 'Manage system settings'],
        ['users', 'view', 'View users'],
        ['users', 'create', 'Create users'],
        ['users', 'update', 'Edit users'],
        ['users', 'delete', 'Deactivate users'],
        ['roles', 'view', 'View roles and permissions'],
        ['roles', 'update', 'Change role permissions'],
    ];

    $insert = $pdo->prepare(
        'INSERT INTO permissions (module, action, slug, name)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name)'
    );

    $grant = $pdo->prepare(
        'INSERT IGNORE INTO role_permissions (role_id, permission_id)
         SELECT ?, id FROM permissions WHERE slug = ?'
    );

    foreach ($permissions as [$module, $action, $label]) {
        $slug = $module . '.' . $action;
        $insert->execute([$module, $action, $slug, $label]);
        $grant->execute([$superAdminRoleId, $slug]);
    }

    $pdo->prepare(
        'INSERT INTO modules (slug, name, version) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE version = VALUES(version)'
    )->execute(['core', 'Authentication & system core', APP_VERSION]);
}

/** Store the company profile and system defaults. */
function seedSettings(PDO $pdo, array $company): void
{
    $settings = [
        ['company_name', $company['name'], 'company'],
        ['company_logo', $company['logo'], 'company'],
        ['company_address', $company['address'], 'company'],
        ['company_phone', $company['phone'], 'company'],
        ['company_email', $company['email'], 'company'],
        ['company_website', $company['website'], 'company'],
        ['app_version', APP_VERSION, 'system'],
        ['installed_at', date('Y-m-d H:i:s'), 'system'],
        ['default_theme', 'system', 'appearance'],
        ['date_format', 'd M Y', 'system'],
    ];

    $statement = $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value, setting_group)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    foreach ($settings as $setting) {
        $statement->execute($setting);
    }
}

/** Generate database/config.generated.php. */
function writeConfigFile(array $database): bool
{
    $export = static fn(string $value): string => var_export($value, true);

    $contents = <<<PHP
<?php
/**
 * Generated by the installation wizard on {DATE}.
 *
 * Environment-specific values live here and nowhere else. Edit this file
 * when you move the application between servers; do not copy it into
 * version control.
 */
declare(strict_types=1);
defined('SWMS_BOOTSTRAPPED') || exit('Direct access is not allowed.');

define('DB_HOST', {HOST});
define('DB_PORT', {PORT});
define('DB_NAME', {NAME});
define('DB_USER', {USER});
define('DB_PASS', {PASS});
define('DB_CHARSET', 'utf8mb4');

/* 'development' shows errors on screen; 'production' logs them instead. */
define('APP_ENV', 'production');

/* Leave empty to detect the URL automatically, or set it explicitly,
   for example: define('APP_URL', 'https://erp.example.com'); */
define('APP_URL', '');

define('APP_TIMEZONE', 'Asia/Karachi');

PHP;

    $contents = strtr($contents, [
        '{DATE}' => date('Y-m-d H:i:s'),
        '{HOST}' => $export($database['host']),
        '{PORT}' => $export($database['port']),
        '{NAME}' => $export($database['name']),
        '{USER}' => $export($database['user']),
        '{PASS}' => $export($database['pass']),
    ]);

    if (@file_put_contents(INSTALL_CONFIG_FILE, $contents, LOCK_EX) === false) {
        return false;
    }

    @chmod(INSTALL_CONFIG_FILE, 0640);
    return true;
}

/** Write the lock file that stops the wizard from ever running again. */
function writeLockFile(string $company, string $email): bool
{
    $payload = json_encode([
        'installed_at' => date('c'),
        'version'      => APP_VERSION,
        'company'      => $company,
        'super_admin'  => $email,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (@file_put_contents(INSTALL_LOCK_FILE, $payload, LOCK_EX) === false) {
        return false;
    }

    @chmod(INSTALL_LOCK_FILE, 0640);
    return true;
}
