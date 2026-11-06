<?php
/**
 * User Management module (Phase 2) — shared bootstrap.
 *
 * Every page in this module includes this file right after config.php.
 * It records the module's presence in the `modules` table (idempotent,
 * for version tracking only — Phase 1 already seeded every permission
 * slug this module needs) and holds small helpers shared by the pages
 * and process handlers below.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

const USERS_MODULE_VERSION = '1.0.0';

/** Record/refresh this module's row. Safe to call on every request. */
function users_module_register(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        Database::execute(
            'INSERT INTO modules (slug, name, version) VALUES (:slug, :name, :version)
             ON DUPLICATE KEY UPDATE version = VALUES(version)',
            ['slug' => 'users', 'name' => 'User Management', 'version' => USERS_MODULE_VERSION]
        );
    } catch (Throwable $e) {
        log_event('users', 'Could not register module', ['error' => $e->getMessage()]);
    }
}

users_module_register();

/**
 * Make every installed module register its permissions. Modules only do so when one of their pages is
 * first opened (the installer seeds just the core ones), so without this the Roles screen could not
 * list — or grant — e.g. the Transport permissions until somebody happened to open Transport.
 * Each module bootstrap is idempotent; a failing module is logged and skipped, never fatal.
 */
function users_load_module_permissions(): void
{
    static $done = false;
    if ($done || !IS_INSTALLED) {
        return;
    }
    $done = true;
    foreach (['hr', 'structure', 'complaints', 'transport', 'attendance', 'comms'] as $module) {
        $file = MODULES_PATH . '/' . $module . '/bootstrap.php';
        if (!is_file($file)) {
            continue;
        }
        try {
            require_once $file;
        } catch (Throwable $e) {
            log_event('users', 'Module bootstrap failed while loading permissions', ['module' => $module, 'error' => $e->getMessage()]);
        }
    }
    Permission::flushCache();
}

/** Permission slugs held by a role. @return string[] */
function users_role_permission_slugs(int $roleId): array
{
    return array_column(Database::fetchAll(
        'SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = :r',
        ['r' => $roleId]
    ), 'slug');
}

/**
 * Delegation rule (least privilege): a Super Administrator may grant anything; everyone else may only
 * hand out permissions they hold themselves. Without it, any user who could edit roles or create users
 * could give themselves (or a new account) every permission in the system.
 *
 * @param string[] $slugs
 */
function users_actor_covers(array $slugs): bool
{
    return Auth::isSuperAdmin() || users_slugs_covered($slugs, Permission::forCurrentUser());
}

/** Pure form of the rule: is every wanted slug among the holder's ('*' = everything)? */
function users_slugs_covered(array $wanted, array $held): bool
{
    return in_array('*', $held, true) || array_diff($wanted, $held) === [];
}

/** May the signed-in user assign / edit / delete this role? (Its permissions must be a subset of theirs.) */
function users_actor_can_manage_role(int $roleId): bool
{
    return users_actor_covers(users_role_permission_slugs($roleId));
}

/** May the signed-in user change this account (details, role, status, activation link)? */
function users_actor_can_manage_user(array $user): bool
{
    return users_actor_can_manage_role((int)($user['role_id'] ?? 0));
}

const USERS_DELEGATION_MESSAGE = 'You can only manage roles and accounts whose permissions you hold yourself.';

/** The Super Administrator role is never edited or assigned from this UI. */
function users_is_locked_role(?string $roleSlug): bool
{
    return $roleSlug === Permission::ROLE_SUPER_ADMIN;
}

/** True when $targetUserId is the person currently signed in. */
function users_is_self(int $targetUserId): bool
{
    return $targetUserId === Auth::id();
}

/** Roles selectable in the create/edit user forms — never Super Admin. */
function users_assignable_roles(): array
{
    return array_values(array_filter(
        Permission::roles(),
        static fn(array $role): bool => !users_is_locked_role((string)$role['slug'])
    ));
}

/** A short, human label for an account status. */
function users_status_label(string $status): string
{
    return match ($status) {
        Auth::STATUS_ACTIVE   => 'Active',
        Auth::STATUS_PENDING  => 'Pending activation',
        Auth::STATUS_INACTIVE => 'Inactive',
        Auth::STATUS_BLOCKED  => 'Blocked',
        default               => ucfirst($status),
    };
}

/** The badge modifier class for a status. */
function users_status_badge(string $status): string
{
    return match ($status) {
        Auth::STATUS_ACTIVE   => 'badge--active',
        Auth::STATUS_PENDING  => 'badge--pending',
        Auth::STATUS_INACTIVE => 'badge--inactive',
        Auth::STATUS_BLOCKED  => 'badge--blocked',
        default               => '',
    };
}

/** Fetch one user with its role, or null. Shared by view/edit/process files. */
function users_find(int $id): ?array
{
    return Database::fetch(
        'SELECT u.*, r.name AS role_name, r.slug AS role_slug, r.level AS role_level
           FROM users u
           LEFT JOIN roles r ON r.id = u.role_id
          WHERE u.id = :id
          LIMIT 1',
        ['id' => $id]
    );
}

/** Build an absolute activation link for a raw (unhashed) token. */
function users_activation_link(string $token): string
{
    return base_url('auth/activate-account.php') . '?token=' . rawurlencode($token);
}

/**
 * Issue a fresh activation token for a pending user and store its hash.
 * Returns the raw token — the only place it exists outside the URL.
 */
function users_issue_activation(int $userId, int $days = 7): string
{
    $token = Security::randomToken();

    Database::update('users', [
        'activation_token_hash' => Security::hashToken($token),
        'activation_expires_at' => date('Y-m-d H:i:s', time() + ($days * 86400)),
    ], ['id' => $userId]);

    return $token;
}
