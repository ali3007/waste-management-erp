<?php
/** Create / edit a task template. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
comms_require('comms.template_manage');
$id = (int)query('id', (string)post('id'));
$cur = $id > 0 ? Database::fetch('SELECT * FROM comms_task_templates WHERE id = :i AND deleted_at IS NULL', ['i' => $id]) : null;
if ($id > 0 && $cur === null) {
    flash('error', 'Template not found.');
    redirect('modules/comms/templates.php');
}
$form = $cur ? ['name' => $cur['name'], 'title' => (string)$cur['title'], 'description' => (string)$cur['description'], 'priority' => $cur['priority'], 'evidence_required' => (string)$cur['evidence_required'], 'checklist' => json_decode((string)$cur['checklist_json'], true) ?: []]
             : ['name' => '', 'title' => '', 'description' => '', 'priority' => 'medium', 'evidence_required' => '0', 'checklist' => []];
if (is_post()) {
    Security::requireCsrf('modules/comms/template-edit.php' . ($id ? '?id=' . $id : ''));
    $form = ['name' => (string)($_POST['name'] ?? ''), 'title' => (string)($_POST['title'] ?? ''), 'description' => (string)($_POST['description'] ?? ''), 'priority' => (string)($_POST['priority'] ?? 'medium'),
             'evidence_required' => (string)($_POST['evidence_required'] ?? '0'), 'checklist' => array_values((array)($_POST['checklist'] ?? []))];
    $r = comms_template_save((int)Auth::id(), $id, $form);
    if ($r['ok']) {
        flash('success', 'Template saved.');
        redirect('modules/comms/templates.php');
    }
    flash('error', $r['message']);
}
$pageTitle = $id ? 'Edit template' : 'New template';
$layout = 'app';
$extraScripts = ['comms-tasks.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
?>
<form method="post" class="card" novalidate><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
    <h2 class="card__title"><?= $id ? 'Edit template' : 'New template' ?></h2>
    <div class="field"><label class="field__label" for="name">Template name <span class="field__required">*</span></label><div class="control"><input class="input" id="name" name="name" maxlength="120" required value="<?= e($form['name']) ?>"></div></div>
    <div class="field"><label class="field__label" for="title">Default task title</label><div class="control"><input class="input" id="title" name="title" maxlength="200" value="<?= e($form['title']) ?>"></div></div>
    <div class="field"><label class="field__label" for="description">Default description</label><div class="control"><textarea class="input" id="description" name="description" rows="4" maxlength="5000"><?= e($form['description']) ?></textarea></div></div>
    <div class="grid grid--2">
        <div class="field"><label class="field__label" for="priority">Default priority</label><div class="control"><select class="select" id="priority" name="priority"><?php foreach (COMMS_PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= $form['priority'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
        <div class="field"><label class="field__label" for="evidence_required">Evidence</label><div class="control"><select class="select" id="evidence_required" name="evidence_required"><option value="0"<?= $form['evidence_required'] === '0' ? ' selected' : '' ?>>Optional</option><option value="1"<?= $form['evidence_required'] === '1' ? ' selected' : '' ?>>Required</option></select></div></div>
    </div>
    <div class="field"><label class="field__label">Checklist</label>
        <div data-checklist-editor><div data-checklist-rows>
            <?php foreach ($form['checklist'] as $i => $it): ?><div class="comms-clrow">
                <input type="hidden" data-f="key" name="checklist[<?= (int)$i ?>][key]" value="<?= e((string)($it['key'] ?? '')) ?>">
                <input class="input" data-f="text" type="text" maxlength="255" name="checklist[<?= (int)$i ?>][text]" value="<?= e((string)$it['text']) ?>" placeholder="Step">
                <label class="comms-check"><input data-f="required" type="checkbox" name="checklist[<?= (int)$i ?>][required]" value="1"<?= !empty($it['required']) ? ' checked' : '' ?>> Required</label>
                <button type="button" class="comms-link" data-cl-up>↑</button><button type="button" class="comms-link" data-cl-down>↓</button><button type="button" class="comms-link" data-cl-del>Remove</button></div><?php endforeach; ?>
        </div><button type="button" class="btn btn--ghost" data-cl-add><span class="btn__label">Add a step</span></button></div></div>
    <div class="modal__actions"><a class="btn btn--ghost" href="<?= e(base_url('modules/comms/templates.php')) ?>">Cancel</a><button class="btn btn--primary" type="submit"><span class="btn__label">Save template</span></button></div>
</form>
<?php require INCLUDES_PATH . '/footer.php'; ?>
