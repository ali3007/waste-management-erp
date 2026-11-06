<?php
/** Task templates list (comms.template_manage). Deleting a template never touches tasks created from it. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
comms_require('comms.template_manage');
if (is_post()) {
    Security::requireCsrf('modules/comms/templates.php');
    $r = comms_template_delete((int)Auth::id(), (int)post('id'));
    flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Template deleted. Existing tasks are unaffected.' : $r['message']);
    redirect('modules/comms/templates.php');
}
$rows = comms_template_list();
$pageTitle = 'Task templates';
$layout = 'app';
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<div class="toolbar"><a class="btn btn--ghost" href="<?= e(base_url('modules/comms/tasks.php')) ?>">← Tasks</a><div class="toolbar__spacer"></div>
    <a class="btn btn--primary" href="<?= e(base_url('modules/comms/template-edit.php')) ?>"><?= icon('plus') ?><span class="btn__label">New template</span></a></div>
<?php if (!$rows): ?><div class="card"><div class="empty-state"><h3>No templates yet</h3><p class="comms-muted">Templates pre-fill the task form: description, priority, evidence rule and checklist.</p></div></div>
<?php else: ?><div class="card" style="padding:0"><div class="table-wrap"><table class="table">
    <thead><tr><th>Name</th><th>Priority</th><th>Checklist steps</th><th>Evidence</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr>
        <td class="is-wrap"><strong><?= e($r['name']) ?></strong><span class="cell-sub"><?= e((string)$r['title']) ?></span></td>
        <td><span class="badge <?= e(comms_priority_badge($r['priority'])) ?>"><?= e(COMMS_PRIORITIES[$r['priority']]) ?></span></td>
        <td><?= count($r['checklist']) ?></td><td><?= $r['evidence_required'] ? 'Required' : 'Optional' ?></td>
        <td><div class="cell-actions"><a class="btn btn--ghost" href="<?= e(base_url('modules/comms/template-edit.php?id=' . (int)$r['id'])) ?>"><span class="btn__label">Edit</span></a>
            <form method="post" data-confirm="Delete this template? Tasks already created from it are not affected."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn--danger" type="submit"><span class="btn__label">Delete</span></button></form></div></td>
    </tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
