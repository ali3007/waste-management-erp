<?php
/**
 * PowerPoint templates.
 *
 * Authorized users upload their OWN designed .pptx; the system never ships a
 * company design. Several templates can exist; only active ones are offered
 * when generating a complaint presentation. The original uploaded file is
 * stored privately and never modified — each generated deck is a new file
 * made from a temporary copy.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/ppt.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('complaint.ppt_templates');

$templates = Database::fetchAll(
    'SELECT t.*, u.name AS by_name FROM ppt_templates t LEFT JOIN users u ON u.id = t.uploaded_by ORDER BY t.status ASC, t.name ASC'
);
$editId = (int)query('replace');

$pageTitle = 'PowerPoint templates';
$layout = 'app';
require INCLUDES_PATH . '/header.php';
$post = base_url('modules/complaints/process/ppt-template-save.php');
?>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<div class="grid grid--2 cmp-config-grid">
    <section class="card">
        <h2 class="card__title"><?= $editId ? 'Replace template file' : 'Upload a template' ?></h2>
        <p class="card__subtitle">Design the look in PowerPoint (colours, fonts, logo, backgrounds, layouts) and upload the .pptx. The first slide of your file styles the Complaint Details slide and the second slide styles each Before/After slide. No special placeholders are needed.</p>
        <form method="post" action="<?= e($post) ?>" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="<?= $editId ? 'replace' : 'upload' ?>">
            <input type="hidden" name="id" value="<?= $editId ?>">
            <?php if (!$editId): ?>
                <div class="field"><label class="field__label" for="name">Template name <span class="field__required">*</span></label>
                    <div class="control"><input class="input" id="name" name="name" maxlength="120" required placeholder="e.g. Corporate light"></div></div>
                <div class="field"><label class="field__label" for="description">Description</label>
                    <div class="control"><input class="input" id="description" name="description" maxlength="255"></div></div>
            <?php endif; ?>
            <div class="field"><label class="field__label" for="file">PowerPoint file (.pptx) <span class="field__required">*</span></label>
                <input class="file-input" type="file" id="file" name="file" accept=".pptx,application/vnd.openxmlformats-officedocument.presentationml.presentation" required>
                <p class="field__hint">Up to <?= e(cmp_fmt_bytes(CMP_PPT_MAX_BYTES)) ?>. Macro-enabled files (.pptm) are refused.</p></div>
            <div class="toolbar" style="margin:12px 0 0">
                <button type="submit" class="btn btn--primary"><?= icon('upload') ?><span class="btn__label"><?= $editId ? 'Replace file' : 'Upload template' ?></span></button>
                <?php if ($editId): ?><a class="btn btn--ghost" href="<?= e(base_url('modules/complaints/ppt-templates.php')) ?>"><span class="btn__label">Cancel</span></a><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card">
        <h2 class="card__title">Templates (<?= count($templates) ?>)</h2>
        <?php if (!$templates): ?>
            <div class="empty-state"><?= icon('presentation') ?><h3 style="margin-bottom:6px">No templates yet</h3><p style="margin:0">Upload your designed .pptx to be able to generate complaint presentations.</p></div>
        <?php else: ?>
            <div class="doc-list">
                <?php foreach ($templates as $t): ?>
                    <div class="doc-item" style="align-items:flex-start;flex-wrap:wrap">
                        <span class="doc-item__thumb"><?= icon('presentation') ?></span>
                        <div class="doc-item__body">
                            <strong><?= e((string)$t['name']) ?> <span class="badge <?= $t['status'] === 'active' ? 'badge--active' : 'badge--inactive' ?>"><?= $t['status'] === 'active' ? 'Active' : 'Inactive' ?></span></strong>
                            <span><?= e((string)$t['original_name']) ?> · <?= e(cmp_fmt_bytes((int)$t['file_size'])) ?> · <?= (int)$t['slide_count'] ?> slide<?= (int)$t['slide_count'] === 1 ? '' : 's' ?>, <?= (int)$t['layout_count'] ?> layouts ·
                                <?= e(number_format($t['slide_width'] / CMP_EMU_IN, 2)) ?>″ × <?= e(number_format($t['slide_height'] / CMP_EMU_IN, 2)) ?>″</span>
                            <?php if ($t['description']): ?><span><?= e((string)$t['description']) ?></span><?php endif; ?>
                            <span>Uploaded <?= e(format_datetime((string)$t['uploaded_at'], 'd M Y')) ?><?= $t['by_name'] ? ' by ' . e((string)$t['by_name']) : '' ?></span>
                        </div>
                        <div class="doc-item__actions">
                            <a class="icon-btn" href="<?= e(base_url('modules/complaints/process/ppt-template-download.php?id=' . (int)$t['id'])) ?>" title="Download original" aria-label="Download original"><?= icon('download') ?></a>
                            <a class="icon-btn" href="<?= e(base_url('modules/complaints/ppt-templates.php?replace=' . (int)$t['id'])) ?>" title="Replace file" aria-label="Replace file"><?= icon('refresh') ?></a>
                            <form method="post" action="<?= e($post) ?>"><?= csrf_field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="icon-btn <?= $t['status'] === 'active' ? 'icon-btn--danger' : '' ?>" title="<?= $t['status'] === 'active' ? 'Deactivate' : 'Activate' ?>" aria-label="Toggle"><?= icon($t['status'] === 'active' ? 'ban' : 'check') ?></button></form>
                            <form method="post" action="<?= e($post) ?>" data-confirm="Delete the template '<?= e((string)$t['name']) ?>'? Presentations already generated are not affected."><?= csrf_field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="icon-btn icon-btn--danger" title="Delete" aria-label="Delete"><?= icon('trash') ?></button></form>
                        </div>
                        <form class="cmp-rename" method="post" action="<?= e($post) ?>" style="flex:0 0 100%;display:flex;gap:8px;margin-top:6px">
                            <?= csrf_field() ?><input type="hidden" name="op" value="rename"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <input class="input" name="name" maxlength="120" value="<?= e((string)$t['name']) ?>" aria-label="Template name" style="max-width:260px">
                            <button type="submit" class="btn btn--ghost"><span class="btn__label">Rename</span></button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
