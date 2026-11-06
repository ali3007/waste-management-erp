<?php
/**
 * "Login account" card for the Edit Employee page (expects $id, $employee).
 * Posts to process/employee-link-user.php — a separate form, so the main
 * employee save is untouched.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

$linked = $employee['user_id'] !== null
    ? Database::fetch('SELECT id, name, email, status FROM users WHERE id = :id', ['id' => (int)$employee['user_id']])
    : null;
$candidates = Database::fetchAll(
    "SELECT u.id, u.name, u.email FROM users u
      WHERE u.status IN ('active','pending')
        AND u.id NOT IN (SELECT user_id FROM employees WHERE user_id IS NOT NULL AND id <> :e)
      ORDER BY u.name ASC LIMIT 500",
    ['e' => $id]
);
?>
<div class="card" style="max-width:760px;margin-top:16px">
    <h2 class="card__title">Login account</h2>
    <p class="field__hint" style="margin-bottom:12px">
        Links this employee to a user account so the system knows who is signed in — needed for Office Staff to mark their own attendance and for Supervisors to get their UC.
        One account can belong to one employee only.
    </p>
    <?php if ($linked !== null): ?>
        <p style="margin-bottom:12px">Currently linked: <strong><?= e($linked['name']) ?></strong> (<?= e($linked['email'] ?: 'CNIC login') ?>) — <?= e($linked['status']) ?></p>
    <?php else: ?>
        <p style="margin-bottom:12px">No login account is linked.</p>
    <?php endif; ?>
    <form method="post" action="<?= e(base_url('modules/hr/process/employee-link-user.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="employee_id" value="<?= (int)$id ?>">
        <div class="field">
            <label class="field__label" for="link_user_id">User account</label>
            <div class="control">
                <select class="select" id="link_user_id" name="user_id">
                    <option value="0">— No login account (unlink) —</option>
                    <?php if ($linked !== null): ?><option value="<?= (int)$linked['id'] ?>" selected><?= e($linked['name']) ?> (<?= e($linked['email'] ?: 'CNIC login') ?>)</option><?php endif; ?>
                    <?php foreach ($candidates as $u): if ($linked !== null && (int)$u['id'] === (int)$linked['id']) { continue; } ?>
                        <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['email'] ?: 'CNIC login') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p class="field__hint">Only Active or Pending accounts that are not already linked to another employee are listed.</p>
        </div>
        <button type="submit" class="btn btn--primary"><span class="btn__label">Save login account</span></button>
    </form>
</div>
