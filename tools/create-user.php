<?php
/**
 * Command-line user creator — a stopgap until the User Management module
 * arrives in a later phase. It uses the same Auth/Security foundation the
 * ERP uses, so nothing it creates has to be migrated later.
 *
 * Usage (from the project root):
 *
 *   php tools/create-user.php --name="Ali Raza" --cnic=36302-1234567-1 --role=admin
 *   php tools/create-user.php --name="Sara Khan" --email=sara@company.com --role=admin
 *   php tools/create-user.php --list-roles
 *
 * Add --url=https://erp.example.com if APP_URL is not set, so the printed
 * activation link is the one people can actually open.
 *
 * The account is created as `pending` with no usable password. The script
 * prints an activation link; the person opens it and sets their own
 * password. No administrator ever handles someone else's password.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This tool runs from the command line only.');
}

require_once dirname(__DIR__) . '/config.php';

if (!IS_INSTALLED) {
    fail('The application is not installed yet. Run the installation wizard first.');
}

$options = getopt('', ['name:', 'email:', 'cnic:', 'role:', 'phone:', 'days:', 'url:', 'list-roles', 'help']);

if (isset($options['help'])) {
    echo trim((string)file_get_contents(__FILE__, false, null, 0, 900)), PHP_EOL;
    exit(0);
}

if (isset($options['list-roles'])) {
    echo PHP_EOL, "Available roles", PHP_EOL, str_repeat('-', 60), PHP_EOL;
    foreach (Permission::roles() as $role) {
        printf("  %-14s %-22s level %d%s", $role['slug'], $role['name'], $role['level'], PHP_EOL);
    }
    echo PHP_EOL;
    exit(0);
}

/* ------------------------------------------------------------------ *
 | Validate                                                            |
 * ------------------------------------------------------------------ */

$name  = Security::cleanText((string)($options['name'] ?? ''), 120);
$email = mb_strtolower(Security::cleanText((string)($options['email'] ?? ''), 190));
$cnic  = Security::normalizeCnic((string)($options['cnic'] ?? ''));
$phone = Security::cleanText((string)($options['phone'] ?? ''), 30);
$roleSlug = Security::cleanText((string)($options['role'] ?? 'admin'), 80);
$days  = max(1, min(30, (int)($options['days'] ?? 7)));

if ($name === '') {
    fail('Pass a name: --name="Ali Raza"');
}

if ($email === '' && $cnic === '') {
    fail('Pass either --cnic=36302-1234567-1 (staff) or --email=someone@company.com (administrator).');
}

if ($email !== '' && $cnic !== '') {
    fail('Pass one identifier, not both. The account signs in with whichever you choose.');
}

$loginType = $email !== '' ? Auth::LOGIN_EMAIL : Auth::LOGIN_CNIC;

if ($loginType === Auth::LOGIN_EMAIL && !Security::isEmail($email)) {
    fail('That email address is not valid.');
}

if ($loginType === Auth::LOGIN_CNIC && !Security::isCnic($cnic)) {
    fail('A CNIC must be 13 digits, with or without dashes.');
}

$role = Database::fetch('SELECT * FROM roles WHERE slug = :slug LIMIT 1', ['slug' => $roleSlug]);

if ($role === null) {
    fail('No role with the slug "' . $roleSlug . '". Run with --list-roles to see them.');
}

if ($role['slug'] === Permission::ROLE_SUPER_ADMIN) {
    fail('The Super Administrator is created by the installation wizard and should stay unique.');
}

$column = $loginType === Auth::LOGIN_EMAIL ? 'email' : 'cnic';
$value = $loginType === Auth::LOGIN_EMAIL ? $email : $cnic;

if (Database::exists('SELECT id FROM users WHERE ' . $column . ' = :value', ['value' => $value])) {
    fail('An account with that ' . strtoupper($column) . ' already exists.');
}

/* ------------------------------------------------------------------ *
 | Create                                                              |
 * ------------------------------------------------------------------ */

$token = Security::randomToken();

try {
    $userId = Database::insert('users', [
        'role_id'               => (int)$role['id'],
        'name'                  => $name,
        'email'                 => $loginType === Auth::LOGIN_EMAIL ? $email : null,
        'cnic'                  => $loginType === Auth::LOGIN_CNIC ? $cnic : null,
        'phone'                 => $phone !== '' ? $phone : null,
        'password_hash'         => '',
        'login_type'            => $loginType,
        'status'                => Auth::STATUS_PENDING,
        'activation_token_hash' => Security::hashToken($token),
        'activation_expires_at' => date('Y-m-d H:i:s', time() + ($days * 86400)),
    ]);

    Database::insert('activity_log', [
        'user_id'     => $userId,
        'module'      => 'core',
        'action'      => 'user.created',
        'subject_type' => 'user',
        'subject_id'  => $userId,
        'description' => 'Account created from the command line',
        'ip_address'  => 'cli',
    ]);
} catch (Throwable $e) {
    fail(Database::friendlyError($e));
}

/*
 * There is no request to detect a URL from on the command line, so the
 * link is built from APP_URL, or from --url, or left as a placeholder.
 */
$siteUrl = APP_URL !== '' ? APP_URL : Security::cleanText((string)($options['url'] ?? ''), 190);
$path = '/auth/activate-account.php?token=' . rawurlencode($token);
$link = $siteUrl !== '' ? rtrim($siteUrl, '/') . $path : '<your site URL>' . $path;

echo PHP_EOL, "Account created", PHP_EOL, str_repeat('-', 68), PHP_EOL;
printf("  Name        %s%s", $name, PHP_EOL);
printf("  Signs in    %s%s", $loginType === Auth::LOGIN_EMAIL ? $email : Security::formatCnic($cnic), PHP_EOL);
printf("  Role        %s%s", $role['name'], PHP_EOL);
printf("  Status      pending until activated%s", PHP_EOL);
printf("  Expires     %s (%d days)%s", date('d M Y, H:i', time() + ($days * 86400)), $days, PHP_EOL);
echo str_repeat('-', 68), PHP_EOL, PHP_EOL;
echo "Send this activation link to the person. It works once.", PHP_EOL, PHP_EOL;
echo '  ', $link, PHP_EOL, PHP_EOL;
if ($siteUrl === '') {
    echo "Replace <your site URL> with the address people use to reach the ERP,", PHP_EOL;
    echo "or avoid this next time with --url=https://erp.example.com, or by setting", PHP_EOL;
    echo "APP_URL in database/config.generated.php.", PHP_EOL, PHP_EOL;
}

/** Print an error and stop. */
function fail(string $message): never
{
    fwrite(STDERR, PHP_EOL . '  ' . $message . PHP_EOL . PHP_EOL);
    exit(1);
}
