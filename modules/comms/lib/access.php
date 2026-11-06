<?php
/**
 * Communication — who may take part. The ERP's User Access & Permissions stays the single source of
 * authorization: a person is eligible for a capability only if their ACTIVE account's role holds the
 * matching comms.* permission (Super Admin holds all). Evaluated live on every call, so a role change
 * or deactivation takes effect on the next request.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/**
 * Active users whose role currently grants $permission (e.g. 'comms.task_review'), with their linked
 * employee (if any). Used for assignee / reviewer / chat-directory pickers; the caller still checks
 * the actor's own right to assign or message.
 *
 * @return array<int,array{id:int,name:string,role:string,employee_id:?int,employee_code:?string}>
 */
function comms_eligible_users(string $permission, string $search = '', int $limit = 50): array
{
    $params = ['perm' => $permission, 'access' => 'comms.access', 'super' => Permission::ROLE_SUPER_ADMIN];
    $where = '';
    if ($search !== '') {
        $where = ' AND (u.name LIKE :q1 OR e.employee_code LIKE :q2)';
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
    }
    $limit = max(1, min(200, $limit));
    return Database::fetchAll(
        "SELECT u.id, u.name, r.name AS role, e.id AS employee_id, e.employee_code
           FROM users u
           JOIN roles r ON r.id = u.role_id
           LEFT JOIN employees e ON e.user_id = u.id AND e.status = 'active'
          WHERE u.status = 'active'
            AND (r.slug = :super
                 OR (EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
                              WHERE rp.role_id = r.id AND p.slug = :perm)
                 AND EXISTS (SELECT 1 FROM role_permissions rp2 JOIN permissions p2 ON p2.id = rp2.permission_id
                              WHERE rp2.role_id = r.id AND p2.slug = :access)))
            $where
          ORDER BY u.name ASC LIMIT $limit",
        $params
    );
}

/** Is this specific user currently eligible for the permission? (server-side recheck for one id) */
function comms_user_can(int $userId, string $permission): bool
{
    foreach (comms_eligible_users($permission, '', 200) as $u) {
        if ((int)$u['id'] === $userId) {
            return true;
        }
    }
    return false;
}
