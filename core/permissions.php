<?php
/**
 * Role-based access control.
 *
 * Permissions are data, not code: a future module registers its own
 * permission rows and then calls Permission::can('hr.employees.view').
 * No page should ever test a role name directly.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

final class Permission
{
    public const ROLE_SUPER_ADMIN = 'super-admin';

    /** Actions every module should describe itself with. */
    public const ACTIONS = ['view', 'create', 'update', 'delete', 'export', 'approve'];

    private static ?array $cache = null;

    /** Drop the per-request cache (called on login/logout). */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    /** All permission slugs granted to the signed-in user. */
    public static function forCurrentUser(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $user = Auth::user();
        if ($user === null) {
            return self::$cache = [];
        }

        /* Super Admin holds every permission, including ones added later. */
        if ((string)$user['role_slug'] === self::ROLE_SUPER_ADMIN) {
            return self::$cache = ['*'];
        }

        try {
            $rows = Database::fetchAll(
                'SELECT p.slug
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE rp.role_id = :role',
                ['role' => (int)$user['role_id']]
            );
            return self::$cache = array_column($rows, 'slug');
        } catch (Throwable $e) {
            log_event('permissions', 'Could not load permissions', ['error' => $e->getMessage()]);
            return self::$cache = [];
        }
    }

    /** Does the signed-in user hold this permission? */
    public static function can(string $slug): bool
    {
        $granted = self::forCurrentUser();

        if (in_array('*', $granted, true)) {
            return true;
        }

        if (in_array($slug, $granted, true)) {
            return true;
        }

        /* A module-level wildcard covers its actions, e.g. hr.* */
        $module = explode('.', $slug)[0] ?? '';
        return $module !== '' && in_array($module . '.*', $granted, true);
    }

    /** Stop the request when the permission is missing. */
    public static function require(string $slug): void
    {
        Auth::requireLogin();

        if (self::can($slug)) {
            return;
        }

        log_event('permissions', 'Access denied', ['user_id' => Auth::id(), 'permission' => $slug]);

        if (is_ajax()) {
            json_response(['ok' => false, 'message' => 'You do not have access to this action.'], 403);
        }

        http_response_code(403);
        require INCLUDES_PATH . '/components/forbidden.php';
        exit;
    }

    /**
     * Register a permission so a future module can grant it.
     * Safe to call repeatedly — existing rows are left alone.
     */
    public static function register(string $module, string $action, string $name, ?string $description = null): int
    {
        $slug = $module . '.' . $action;

        $existing = Database::fetchValue('SELECT id FROM permissions WHERE slug = :slug', ['slug' => $slug]);
        if ($existing !== null) {
            return (int)$existing;
        }

        return Database::insert('permissions', [
            'module'      => $module,
            'action'      => $action,
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description,
        ]);
    }

    /** Grant a permission slug to a role. */
    public static function grant(int $roleId, string $slug): void
    {
        $permissionId = Database::fetchValue('SELECT id FROM permissions WHERE slug = :slug', ['slug' => $slug]);
        if ($permissionId === null) {
            return;
        }

        Database::execute(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role, :permission)',
            ['role' => $roleId, 'permission' => (int)$permissionId]
        );
    }

    public static function revoke(int $roleId, string $slug): void
    {
        Database::execute(
            'DELETE rp FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = :role AND p.slug = :slug',
            ['role' => $roleId, 'slug' => $slug]
        );
    }

    /** Roles available for assignment, highest level first. */
    public static function roles(): array
    {
        try {
            return Database::fetchAll('SELECT * FROM roles ORDER BY level DESC, name ASC');
        } catch (Throwable) {
            return [];
        }
    }
}

/** Template shorthand. */
function can(string $slug): bool
{
    return Permission::can($slug);
}
