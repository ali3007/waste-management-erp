<?php
/**
 * PowerPoint preview for one complaint.
 *
 * Before generating, the user chooses a template, reviews the details that
 * will fill slide 1 and sees exactly which Before picture sits opposite
 * which After picture on each following slide (the order saved in the
 * galleries). Unmatched pictures are shown, not hidden.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/ppt.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

$id = (int)query('id');
$c = cmp_require_access($id, 'complaint.ppt');
if ($c['deleted_at'] !== null) {
    flash('error', 'A deleted complaint cannot be exported.');
    redirect('modules/complaints/complaint-view.php?id=' . $id);
}

$templates = Database::fetchAll("SELECT * FROM ppt_templates WHERE status = 'active' ORDER BY name ASC");
$before = cmp_evidence_list($id, 'before');
$after = cmp_evidence_list($id, 'after');
$pairs = max(count($before), count($after));
$selected = (int)query('template') ?: (int)($templates[0]['id'] ?? 0);
$latest = Database::fetch('SELECT remarks, resolved_at FROM complaint_resolution WHERE complaint_id = :c ORDER BY id DESC LIMIT 1', ['c' => $id]);

$pageTitle = 'PowerPoint — ' . $c['complaint_number'];
$layout = 'app';
$extraScripts = ['complaint-ppt.js'];
require INCLUDES_PATH . '/header.php';
?>

<a class="btn btn--ghost no-print" href="<?= e(base_url('modules/complaints/complaint-view.php?id=' . $id)) ?>" style="margin-bottom:16px">
    <?= icon('arrow-left') ?><span class="btn__label">Back to <?= e((string)$c['complaint_number']) ?></span>
</a>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<form method="post" action="<?= e(base_url('modules/complaints/process/ppt-generate.php')) ?>" id="ppt-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <section class="card" style="margin-bottom:var(--space-4)">
        <h2 class="card__title">1 · Choose a design</h2>
        <?php if (!$templates): ?>
            <div class="empty-state"><?= icon('presentation') ?><h3 style="margin-bottom:6px">No active template</h3>
                <p style="margin:0 0 12px">Upload your designed PowerPoint (.pptx) first.</p>
                <?php if (can('complaint.ppt_templates')): ?><a class="btn btn--primary" href="<?= e(base_url('modules/complaints/ppt-templates.php')) ?>"><span class="btn__label">Manage templates</span></a><?php endif; ?></div>
        <?php else: ?>
            <div class="cmp-ppt-grid">
                <?php foreach ($templates as $t): ?>
                    <label class="cmp-ppt-card <?= (int)$t['id'] === $selected ? 'is-selected' : '' ?>">
                        <input type="radio" name="template_id" value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $selected ? 'checked' : '' ?> hidden>
                        <h4><?= icon('presentation') ?> <?= e((string)$t['name']) ?></h4>
                        <span class="cell-sub"><?= (int)$t['slide_count'] ?> design slide<?= (int)$t['slide_count'] === 1 ? '' : 's' ?> · <?= (int)$t['layout_count'] ?> layouts · <?= e(number_format($t['slide_width'] / CMP_EMU_IN, 1)) ?>″ × <?= e(number_format($t['slide_height'] / CMP_EMU_IN, 1)) ?>″</span>
                        <?php if ($t['description']): ?><span class="cell-sub" style="display:block"><?= e((string)$t['description']) ?></span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card" style="margin-bottom:var(--space-4)">
        <h2 class="card__title">2 · Slide 1 — complaint details</h2>
        <dl class="definition">
            <dt>Company</dt><dd><?= e((string)company()['name']) ?> <span class="cell-sub">(logo is added top-right)</span></dd>
            <dt>Complaint No.</dt><dd><?= e((string)$c['complaint_number']) ?></dd>
            <dt>Complainant</dt><dd><?= e((string)$c['complainant_name']) ?></dd>
            <dt>Source / Type</dt><dd><?= e((string)$c['source_name']) ?> / <?= e((string)$c['type_name']) ?></dd>
            <dt>Date</dt><dd><?= e(cmp_fmt_date((string)$c['complaint_date'])) ?></dd>
            <dt>Area</dt><dd><?= e((string)$c['region_name']) ?> › <?= e((string)$c['district_name']) ?> › <?= e((string)$c['tehsil_name']) ?> › <?= e((string)$c['uc_name']) ?><?= $c['beat_name'] ? ' › ' . e((string)$c['beat_name']) : '' ?></dd>
            <dt>Location</dt><dd><?= e((string)($c['location_address'] ?: '—')) ?></dd>
            <dt>Priority / Status</dt><dd><?= e((string)$c['priority_name']) ?> / <?= e(cmp_status_label((string)$c['status'])) ?></dd>
            <dt>Description</dt><dd><?= nl2br(e(mb_strimwidth((string)$c['description'], 0, 400, '…'))) ?></dd>
            <?php if ($latest && $c['status'] === CMP_ST_RESOLVED): ?><dt>Resolution</dt><dd><?= e(mb_strimwidth((string)$latest['remarks'], 0, 300, '…')) ?></dd><?php endif; ?>
        </dl>
    </section>

    <section class="card" style="margin-bottom:var(--space-4)">
        <h2 class="card__title">3 · Slides 2–<?= $pairs + 1 ?> — Before | After (<?= $pairs ?> slide<?= $pairs === 1 ? '' : 's' ?>)</h2>
        <?php if ($pairs === 0): ?>
            <p class="card__subtitle" style="margin:0">This complaint has no Before or After pictures, so the presentation will contain only the details slide.</p>
        <?php else: ?>
            <p class="card__subtitle">One Before and one After picture per slide, in the order saved on the complaint (change it by dragging pictures in the galleries).
                <?php if (count($before) !== count($after)): ?><strong>Counts differ (<?= count($before) ?> Before / <?= count($after) ?> After)</strong> — unmatched pictures appear on their own slide with an empty partner.<?php endif; ?></p>
            <div class="cmp-ppt-pairs">
                <?php for ($i = 0; $i < $pairs; $i++): ?>
                    <div class="cmp-ppt-pair">
                        <b>Slide <?= $i + 2 ?></b>
                        <div>
                            <?php if (isset($before[$i])): ?><img loading="lazy" src="<?= e(cmp_file_url($id, 'thumb', (int)$before[$i]['id'])) ?>" alt="Before <?= $i + 1 ?>"><?php else: ?><span class="cmp-ppt-none">no Before</span><?php endif; ?>
                            <?php if (isset($after[$i])): ?><img loading="lazy" src="<?= e(cmp_file_url($id, 'thumb', (int)$after[$i]['id'])) ?>" alt="After <?= $i + 1 ?>"><?php else: ?><span class="cmp-ppt-none">no After</span><?php endif; ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="toolbar">
        <button type="submit" class="btn btn--primary" data-generate <?= $templates ? '' : 'disabled' ?>><span class="spinner"></span><?= icon('download') ?><span class="btn__label">Generate PowerPoint (<?= $pairs + 1 ?> slide<?= $pairs + 1 === 1 ? '' : 's' ?>)</span></button>
        <span class="field__hint" data-generate-hint hidden>Building the presentation… large picture sets can take a minute.</span>
    </div>
</form>

<?php require INCLUDES_PATH . '/footer.php'; ?>
