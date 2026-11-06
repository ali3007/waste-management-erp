<?php
/**
 * Complaint detail / profile page.
 *
 * Everything on it is shown only if the server says the signed-in user may
 * see this complaint (UC scope) and every button is drawn from the same
 * cmp_actions() list the action endpoints enforce — so a button never
 * appears for something the server would refuse.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

$id = (int)query('id');
$complaint = cmp_require_access($id, 'complaint.view');
$actions = array_flip(cmp_actions($complaint));
$deleted = $complaint['deleted_at'] !== null;

$staff = cmp_uc_staff((int)$complaint['uc_id']);
$assignments = Database::fetchAll(
    'SELECT a.*, u.name AS uc_name, b.name AS beat_name, us.name AS by_name
       FROM complaint_assignments a JOIN ucs u ON u.id = a.uc_id LEFT JOIN beats b ON b.id = a.beat_id LEFT JOIN users us ON us.id = a.assigned_by
      WHERE a.complaint_id = :c ORDER BY a.id DESC',
    ['c' => $id]
);
$before = cmp_evidence_list($id, 'before');
$after = cmp_evidence_list($id, 'after');
$attachments = Database::fetchAll('SELECT a.*, u.name AS by_name FROM complaint_attachments a LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.complaint_id = :c ORDER BY a.id DESC', ['c' => $id]);
$resolutions = Database::fetchAll('SELECT r.*, u.name AS by_name FROM complaint_resolution r LEFT JOIN users u ON u.id = r.resolved_by WHERE r.complaint_id = :c ORDER BY r.id DESC', ['c' => $id]);
$reopens = Database::fetchAll('SELECT r.*, u.name AS by_name FROM complaint_reopen_history r LEFT JOIN users u ON u.id = r.reopened_by WHERE r.complaint_id = :c ORDER BY r.id DESC', ['c' => $id]);
$timeline = Database::fetchAll('SELECT t.*, u.name AS user_name FROM complaint_timeline t LEFT JOIN users u ON u.id = t.user_id WHERE t.complaint_id = :c ORDER BY t.created_at ASC, t.id ASC', ['c' => $id]);

$sla = cmp_sla_state($complaint);
$mapUrl = cmp_map_url($complaint['latitude'], $complaint['longitude']);
$logo = company_logo_url();
$company = company();

$canEvBefore = isset($actions['evidence_before']);
$canEvAfter = isset($actions['evidence_after']);
$post = base_url('modules/complaints/process/complaint-action.php');

$pageTitle = (string)$complaint['complaint_number'];
$layout = 'app';
$extraScripts = ['complaints.js', 'complaint-gallery.js'];
$extraStyles = array_merge($extraStyles, ['complaint-gallery.css']);

require INCLUDES_PATH . '/header.php';

/** Render one gallery (Before or After). */
$renderGallery = static function (string $kind, array $items, bool $canEdit) use ($id): void {
    $label = $kind === 'before' ? 'BEFORE EVIDENCE' : 'AFTER / RESOLUTION EVIDENCE';
    ?>
    <div class="cmp-gallery" data-gallery data-kind="<?= e($kind) ?>" data-editable="<?= $canEdit ? '1' : '0' ?>">
        <div class="cmp-gallery__head">
            <h4><?= e($label) ?> <span class="cmp-count" data-count><?= count($items) ?></span></h4>
            <?php if ($canEdit): ?>
                <div class="cmp-gallery__tools no-print">
                    <label class="btn"><?= icon('folder') ?><span class="btn__label">Upload <?= $kind === 'before' ? 'Before' : 'After' ?> Pictures — folder</span>
                        <input type="file" hidden webkitdirectory multiple data-upload="<?= e($kind) ?>"></label>
                    <label class="btn btn--ghost"><?= icon('image') ?><span class="btn__label">Add pictures</span>
                        <input type="file" hidden multiple accept="image/jpeg,image/png,image/webp" data-upload="<?= e($kind) ?>"></label>
                </div>
            <?php endif; ?>
        </div>
        <div class="cmp-progress" data-upload-progress hidden>
            <div class="cmp-progress__bar"><i data-progress-bar></i></div>
            <p class="field__hint" data-progress-text></p>
        </div>
        <div class="cmp-grid" data-grid>
            <?php foreach ($items as $n => $row): $it = cmp_evidence_item($row); ?>
                <figure class="cmp-thumb" data-id="<?= (int)$it['id'] ?>" data-preview="<?= e($it['preview']) ?>" data-full="<?= e($it['full']) ?>" data-name="<?= e($it['name']) ?>" <?= $canEdit ? 'draggable="true"' : '' ?>>
                    <img loading="lazy" decoding="async" src="<?= e($it['thumb']) ?>" alt="<?= e($label) ?> <?= $n + 1 ?>">
                    <span class="cmp-thumb__no" data-no><?= $n + 1 ?></span>
                    <?php if ($canEdit): ?><button type="button" class="cmp-thumb__del no-print" data-delete title="Remove this picture" aria-label="Remove picture"><?= icon('x') ?></button><?php endif; ?>
                </figure>
            <?php endforeach; ?>
        </div>
        <p class="cmp-empty" data-empty <?= $items ? 'hidden' : '' ?>>No <?= $kind === 'before' ? 'Before' : 'After' ?> pictures yet.</p>
        <?php if ($canEdit): ?><p class="field__hint no-print">Drag pictures to change their order. This order is used in the PowerPoint.</p><?php endif; ?>
    </div>
    <?php
};
?>

<a class="btn btn--ghost no-print" href="<?= e(base_url('modules/complaints/index.php')) ?>" style="margin-bottom:16px">
    <?= icon('arrow-left') ?><span class="btn__label">Back to complaints</span>
</a>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<?php if ($deleted): ?>
    <div class="alert alert--warning"><?= icon('alert') ?><span>This complaint was deleted on <?= e(format_datetime((string)$complaint['deleted_at'])) ?> by <?= e((string)($complaint['deleted_by_name'] ?? '—')) ?><?= $complaint['delete_reason'] ? ' — ' . e((string)$complaint['delete_reason']) : '' ?>. Its history is preserved.</span></div>
<?php endif; ?>

<!-- Header -->
<section class="card cmp-head" id="complaint-root" data-complaint-id="<?= $id ?>" data-csrf="<?= e(Security::csrfToken()) ?>"
         data-evidence-url="<?= e(base_url('modules/complaints/process/evidence-upload.php')) ?>"
         data-order-url="<?= e(base_url('modules/complaints/process/evidence-order.php')) ?>">
    <div class="cmp-head__brand">
        <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt=""><?php endif; ?>
        <div><strong><?= e((string)$company['name']) ?></strong><span>Complaints Management</span></div>
    </div>
    <div class="cmp-head__main">
        <h2><?= e((string)$complaint['complaint_number']) ?></h2>
        <div class="cmp-head__badges">
            <?= cmp_status_badge((string)$complaint['status']) ?>
            <?= cmp_priority_badge((string)$complaint['priority_name'], (string)$complaint['priority_color']) ?>
            <?= cmp_sla_badge($sla) ?>
        </div>
        <p class="cmp-head__meta">
            Created <?= e(format_datetime((string)$complaint['created_at'])) ?><?= $complaint['created_by_name'] ? ' by ' . e((string)$complaint['created_by_name']) : '' ?>
            <?php if ($sla !== 'none'): ?> · <?= e(cmp_sla_remaining($complaint)) ?> · due <?= e(format_datetime((string)$complaint['sla_due_at'])) ?><?php endif; ?>
        </p>
        <div class="profile-actions no-print">
            <?php if (isset($actions['start'])): ?><button type="button" class="btn btn--primary" data-act="start" data-title="Start work" data-label="Note (optional)" data-submit="Start work"><?= icon('play') ?><span class="btn__label">Start work</span></button><?php endif; ?>
            <?php if (isset($actions['ready'])): ?><button type="button" class="btn btn--primary" data-act="ready" data-title="Mark Ready for Resolution" data-label="Note for the reviewer (optional)" data-submit="Mark ready"><?= icon('check-big') ?><span class="btn__label">Mark Ready for Resolution</span></button><?php endif; ?>
            <?php if (isset($actions['resolve'])): ?><button type="button" class="btn btn--primary" data-act="resolve" data-title="Resolve complaint" data-label="Resolution remarks" data-required="1" data-submit="Resolve complaint"><?= icon('check-big') ?><span class="btn__label">Resolve</span></button><?php endif; ?>
            <?php if (isset($actions['return'])): ?><button type="button" class="btn" data-act="return" data-title="Send back to the field" data-label="What still needs to be done?" data-required="1" data-submit="Send back"><?= icon('rotate') ?><span class="btn__label">Send back to field</span></button><?php endif; ?>
            <?php if (isset($actions['reopen'])): ?><button type="button" class="btn" data-act="reopen" data-title="Reopen complaint" data-label="Reason for reopening" data-required="1" data-submit="Reopen"><?= icon('rotate') ?><span class="btn__label">Reopen</span></button><?php endif; ?>
            <?php if (isset($actions['reassign'])): ?><button type="button" class="btn" data-open-modal="reassign-modal"><?= icon('layers') ?><span class="btn__label">Reassign</span></button><?php endif; ?>
            <?php if (isset($actions['edit'])): ?><a class="btn" href="<?= e(base_url('modules/complaints/complaint-edit.php?id=' . $id)) ?>"><?= icon('pencil') ?><span class="btn__label">Edit</span></a><?php endif; ?>
            <?php if (isset($actions['remark'])): ?><button type="button" class="btn btn--ghost" data-act="remark" data-title="Add remark" data-label="Remark" data-required="1" data-submit="Add remark"><?= icon('file-text') ?><span class="btn__label">Add remark</span></button><?php endif; ?>
            <?php if (isset($actions['reject'])): ?><button type="button" class="btn btn--ghost" data-act="reject" data-title="Reject complaint" data-label="Reason for rejection" data-required="1" data-submit="Reject complaint" data-danger="1"><?= icon('ban') ?><span class="btn__label">Reject</span></button><?php endif; ?>
            <?php if (!$deleted && can('complaint.reports')): ?><a class="btn btn--ghost" href="<?= e(base_url('modules/complaints/complaint-pdf.php?id=' . $id)) ?>"><?= icon('download') ?><span class="btn__label">Complaint PDF</span></a><?php endif; ?>
            <?php if (!$deleted && can('complaint.ppt')): ?><a class="btn btn--ghost" href="<?= e(base_url('modules/complaints/ppt-preview.php?id=' . $id)) ?>"><?= icon('presentation') ?><span class="btn__label">PowerPoint</span></a><?php endif; ?>
            <?php if (isset($actions['delete'])): ?><button type="button" class="btn btn--ghost" data-act="delete" data-title="Delete complaint" data-label="Reason for deleting" data-required="1" data-submit="Delete" data-danger="1"><?= icon('trash') ?><span class="btn__label">Delete</span></button><?php endif; ?>
            <?php if (isset($actions['restore'])): ?><button type="button" class="btn btn--primary" data-act="restore" data-title="Restore complaint" data-label="Note (optional)" data-submit="Restore"><?= icon('refresh') ?><span class="btn__label">Restore</span></button><?php endif; ?>
        </div>
    </div>
</section>

<div class="profile-grid profile-section">
    <section class="card">
        <h3 class="card__title"><?= icon('user') ?>Complainant</h3>
        <dl class="definition">
            <dt>Name</dt><dd><?= e((string)$complaint['complainant_name']) ?></dd>
            <dt>Phone</dt><dd><?= e((string)($complaint['phone'] ?: '—')) ?></dd>
            <dt>Address</dt><dd><?= e((string)($complaint['complainant_address'] ?: '—')) ?></dd>
        </dl>
    </section>
    <section class="card">
        <h3 class="card__title"><?= icon('file-text') ?>Complaint</h3>
        <dl class="definition">
            <dt>Source</dt><dd><?= e((string)$complaint['source_name']) ?></dd>
            <dt>Type</dt><dd><?= e((string)$complaint['type_name']) ?></dd>
            <dt>Complaint date / time</dt><dd><?= e(cmp_fmt_date((string)$complaint['complaint_date'])) ?><?= $complaint['complaint_time'] ? ', ' . e(substr((string)$complaint['complaint_time'], 0, 5)) : '' ?></dd>
            <dt>Description</dt><dd><?= nl2br(e((string)$complaint['description'])) ?></dd>
        </dl>
    </section>
</div>

<div class="profile-grid profile-section">
    <section class="card">
        <h3 class="card__title"><?= icon('map-pin') ?>Location</h3>
        <dl class="definition">
            <dt>Region</dt><dd><?= e((string)$complaint['region_name']) ?></dd>
            <dt>District</dt><dd><?= e((string)$complaint['district_name']) ?></dd>
            <dt>Tehsil</dt><dd><?= e((string)$complaint['tehsil_name']) ?></dd>
            <dt>UC</dt><dd><?= e((string)$complaint['uc_name']) ?></dd>
            <dt>Beat</dt><dd><?= e((string)($complaint['beat_name'] ?: '—')) ?></dd>
            <dt>Address</dt><dd><?= e((string)($complaint['location_address'] ?: '—')) ?></dd>
            <?php if ($complaint['latitude'] !== null && $complaint['longitude'] !== null): ?>
                <dt>Latitude</dt><dd><?= e((string)$complaint['latitude']) ?></dd>
                <dt>Longitude</dt><dd><?= e((string)$complaint['longitude']) ?></dd>
            <?php endif; ?>
        </dl>
        <?php if ($mapUrl !== null): ?>
            <a class="btn btn--ghost no-print" href="<?= e($mapUrl) ?>" target="_blank" rel="noopener noreferrer"><?= icon('map-pin') ?><span class="btn__label">View Location on Map</span></a>
        <?php endif; ?>
    </section>

    <section class="card">
        <h3 class="card__title"><?= icon('users') ?>Assignment</h3>
        <dl class="definition">
            <dt>UC</dt><dd><?= e((string)$complaint['uc_name']) ?></dd>
            <dt>Beat</dt><dd><?= e((string)($complaint['beat_name'] ?: '—')) ?></dd>
            <dt>Supervisor</dt>
            <dd>
                <?php foreach ($staff['supervisors'] as $s): ?>
                    <div><?= e((string)$s['name']) ?> <span class="cell-sub"><?= e((string)$s['employee_code']) ?><?= $s['phone'] ? ' · ' . e((string)$s['phone']) : '' ?><?= $s['user_id'] === null ? ' · no login account' : '' ?></span></div>
                <?php endforeach; ?>
                <?php if (!$staff['supervisors']): ?>—<?php endif; ?>
            </dd>
            <dt>Monitoring Officer</dt>
            <dd>
                <?php foreach ($staff['monitors'] as $s): ?>
                    <div><?= e((string)$s['name']) ?> <span class="cell-sub"><?= e((string)$s['employee_code']) ?><?= $s['phone'] ? ' · ' . e((string)$s['phone']) : '' ?><?= $s['user_id'] === null ? ' · no login account' : '' ?></span></div>
                <?php endforeach; ?>
                <?php if (!$staff['monitors']): ?>—<?php endif; ?>
            </dd>
        </dl>
        <?php if (!$staff['supervisors'] && !$staff['monitors']): ?>
            <p class="field__hint">Nobody is currently assigned to this UC in HR (Employees → Assigned), so no one has been notified.</p>
        <?php endif; ?>
        <details style="margin-top:12px">
            <summary class="cell-sub" style="cursor:pointer">Assignment history (<?= count($assignments) ?>)</summary>
            <ul class="history-list">
                <?php foreach ($assignments as $a): ?>
                    <li>
                        <span><strong><?= e((string)$a['uc_name']) ?></strong><?= $a['beat_name'] ? ' / ' . e((string)$a['beat_name']) : '' ?><?= $a['reason'] ? ' — ' . e((string)$a['reason']) : '' ?>
                            <?= $a['by_name'] ? '<span class="cell-sub">by ' . e((string)$a['by_name']) . '</span>' : '' ?></span>
                        <small><?= e(format_datetime((string)$a['assigned_at'])) ?><?= $a['ended_at'] ? ' → ' . e(format_datetime((string)$a['ended_at'])) : ' → current' ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    </section>
</div>

<!-- Evidence -->
<section class="card profile-section cmp-evidence" id="evidence">
    <h3 class="card__title"><?= icon('image') ?>Evidence</h3>
    <div class="cmp-gallery-pair">
        <?php $renderGallery('before', $before, $canEvBefore); ?>
        <?php $renderGallery('after', $after, $canEvAfter); ?>
    </div>
    <?php if (count($before) !== count($after) && ($before || $after)): ?>
        <p class="field__hint">Before and After counts differ (<?= count($before) ?> / <?= count($after) ?>). That is fine — unmatched pictures are kept and appear in the PowerPoint without a partner.</p>
    <?php endif; ?>
</section>

<!-- Attachments -->
<section class="card profile-section" id="attachments">
    <h3 class="card__title"><?= icon('paperclip') ?>Attachments (<?= count($attachments) ?>)</h3>
    <?php if ($attachments): ?>
        <div class="doc-list">
            <?php foreach ($attachments as $a): ?>
                <div class="doc-item">
                    <span class="doc-item__thumb"><?= icon('file-text') ?></span>
                    <div class="doc-item__body"><strong><?= e((string)$a['original_name']) ?></strong>
                        <span><?= e(cmp_fmt_bytes((int)$a['file_size'])) ?> · <?= e(format_datetime((string)$a['uploaded_at'], 'd M Y')) ?><?= $a['by_name'] ? ' · ' . e((string)$a['by_name']) : '' ?></span></div>
                    <div class="doc-item__actions no-print">
                        <?php if (in_array(strtolower(pathinfo((string)$a['file_name'], PATHINFO_EXTENSION)), ['pdf', 'jpg', 'png'], true)): ?>
                            <a class="icon-btn" href="<?= e(cmp_file_url($id, 'attachment', (int)$a['id'])) ?>" target="_blank" rel="noopener" title="View" aria-label="View"><?= icon('eye') ?></a>
                        <?php endif; ?>
                        <a class="icon-btn" href="<?= e(cmp_file_url($id, 'attachment', (int)$a['id'], true)) ?>" title="Download" aria-label="Download"><?= icon('download') ?></a>
                        <?php if (isset($actions['attach'])): ?>
                            <form method="post" action="<?= e(base_url('modules/complaints/process/attachment-delete.php')) ?>" data-confirm="Remove this attachment?">
                                <?= csrf_field() ?><input type="hidden" name="complaint_id" value="<?= $id ?>"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button type="submit" class="icon-btn icon-btn--danger" title="Remove" aria-label="Remove"><?= icon('trash') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="card__subtitle" style="margin:0">No attachments.</p>
    <?php endif; ?>
    <?php if (isset($actions['attach'])): ?>
        <form class="upload-inline no-print" style="grid-template-columns:minmax(0,1fr) auto" method="post" action="<?= e(base_url('modules/complaints/process/attachment-upload.php')) ?>" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?><input type="hidden" name="complaint_id" value="<?= $id ?>">
            <div class="field"><label class="field__label" for="att_files">Add documents</label>
                <input class="file-input" type="file" id="att_files" name="files[]" multiple required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"></div>
            <button type="submit" class="btn btn--primary"><?= icon('upload') ?><span class="btn__label">Upload</span></button>
        </form>
    <?php endif; ?>
</section>

<!-- Resolution -->
<section class="card profile-section">
    <h3 class="card__title"><?= icon('check-big') ?>Resolution</h3>
    <?php if (!$resolutions && !$reopens): ?>
        <p class="card__subtitle" style="margin:0">Not resolved yet. Current status: <?= cmp_status_badge((string)$complaint['status']) ?></p>
    <?php else: ?>
        <ul class="history-list">
            <?php foreach ($resolutions as $r): ?>
                <li>
                    <span><strong>Resolution <?= (int)$r['cycle_no'] ?></strong> — <?= nl2br(e((string)$r['remarks'])) ?>
                        <span class="cell-sub">by <?= e((string)($r['by_name'] ?? '—')) ?><?= $r['state'] === 'reopened' ? ' · later reopened' : ' · current' ?></span></span>
                    <small><?= e(format_datetime((string)$r['resolved_at'])) ?></small>
                </li>
            <?php endforeach; ?>
            <?php foreach ($reopens as $r): ?>
                <li>
                    <span><strong>Reopened</strong> (<?= e(cmp_status_label((string)$r['previous_status'])) ?> → <?= e(cmp_status_label((string)$r['new_status'])) ?>) — <?= nl2br(e((string)$r['reason'])) ?>
                        <span class="cell-sub">by <?= e((string)($r['by_name'] ?? '—')) ?></span></span>
                    <small><?= e(format_datetime((string)$r['reopened_at'])) ?></small>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<!-- Timeline -->
<section class="card profile-section" id="timeline">
    <h3 class="card__title"><?= icon('clock') ?>Timeline (<?= count($timeline) ?>)</h3>
    <ol class="cmp-timeline">
        <?php foreach ($timeline as $t): ?>
            <li>
                <div class="cmp-timeline__dot"></div>
                <div class="cmp-timeline__body">
                    <strong><?= e((string)$t['title']) ?></strong>
                    <?php if ($t['old_value'] !== null || $t['new_value'] !== null): ?>
                        <span class="cmp-timeline__change"><?= $t['old_value'] !== null ? e((string)$t['old_value']) : '' ?><?= $t['old_value'] !== null && $t['new_value'] !== null ? ' → ' : '' ?><?= $t['new_value'] !== null ? e((string)$t['new_value']) : '' ?></span>
                    <?php endif; ?>
                    <?php if ($t['remarks']): ?><p><?= nl2br(e((string)$t['remarks'])) ?></p><?php endif; ?>
                    <small><?= e(format_datetime((string)$t['created_at'], 'd M Y, H:i:s')) ?> · <?= e((string)($t['user_name'] ?? 'System')) ?></small>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
</section>

<!-- Generic action dialog -->
<div class="cmp-modal" id="action-modal" hidden role="dialog" aria-modal="true" aria-labelledby="action-title">
    <form class="cmp-modal__box" method="post" action="<?= e($post) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="op" value="" data-op>
        <h3 id="action-title" data-title>Action</h3>
        <div class="field">
            <label class="field__label" for="action-reason" data-label>Reason</label>
            <textarea class="textarea" id="action-reason" name="reason" rows="4" maxlength="1000"></textarea>
        </div>
        <div class="toolbar" style="margin:var(--space-3) 0 0">
            <button type="submit" class="btn btn--primary" data-submit><span class="btn__label">Confirm</span></button>
            <button type="button" class="btn btn--ghost" data-close-modal><span class="btn__label">Cancel</span></button>
        </div>
    </form>
</div>

<?php if (isset($actions['reassign'])): ?>
<div class="cmp-modal" id="reassign-modal" hidden role="dialog" aria-modal="true" aria-labelledby="reassign-title">
    <form class="cmp-modal__box cmp-modal__box--wide" method="post" action="<?= e($post) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="op" value="reassign">
        <h3 id="reassign-title">Reassign complaint</h3>
        <p class="card__subtitle">The old UC stays in the history. The new UC's Supervisor and Monitoring Officer(s) are notified and gain access; the previous UC's staff lose it.</p>
        <?php $regionsAll = Database::fetchAll("SELECT id, name FROM regions WHERE status = 'active' ORDER BY name ASC"); ?>
        <div class="field-row cmp-cascade" data-cascade-url="<?= e(base_url('modules/complaints/process/cascade.php')) ?>">
            <div class="field"><label class="field__label" for="ra_region">Region</label><div class="control"><select class="select" id="ra_region" name="region_id" data-cascade-child="district" required>
                <option value="">Select region</option><?php foreach ($regionsAll as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e((string)$r['name']) ?></option><?php endforeach; ?></select></div></div>
            <div class="field"><label class="field__label" for="ra_district">District</label><div class="control"><select class="select" id="ra_district" name="district_id" data-cascade-child="tehsil" required><option value="">Select district</option></select></div></div>
            <div class="field"><label class="field__label" for="ra_tehsil">Tehsil</label><div class="control"><select class="select" id="ra_tehsil" name="tehsil_id" data-cascade-child="uc" required><option value="">Select tehsil</option></select></div></div>
            <div class="field"><label class="field__label" for="ra_uc">UC</label><div class="control"><select class="select" id="ra_uc" name="uc_id" data-cascade-child="beat" required><option value="">Select UC</option></select></div></div>
            <div class="field"><label class="field__label" for="ra_beat">Beat (optional)</label><div class="control"><select class="select" id="ra_beat" name="beat_id"><option value="">No beat</option></select></div></div>
        </div>
        <div class="field"><label class="field__label" for="ra_reason">Reason for reassignment <span class="field__required">*</span></label>
            <textarea class="textarea" id="ra_reason" name="reason" rows="3" maxlength="1000" required></textarea></div>
        <div class="toolbar" style="margin:var(--space-3) 0 0">
            <button type="submit" class="btn btn--primary"><span class="btn__label">Reassign</span></button>
            <button type="button" class="btn btn--ghost" data-close-modal><span class="btn__label">Cancel</span></button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Image viewer (modal; no page navigation) -->
<div class="cmp-viewer" id="viewer" hidden role="dialog" aria-modal="true" aria-label="Picture viewer">
    <div class="cmp-viewer__bar">
        <span class="cmp-viewer__title" data-v-title></span>
        <span class="cmp-viewer__counter" data-v-counter></span>
        <div class="cmp-viewer__tools">
            <button type="button" data-v="zoom-out" aria-label="Zoom out"><?= icon('zoom-out') ?></button>
            <button type="button" data-v="zoom-in" aria-label="Zoom in"><?= icon('zoom-in') ?></button>
            <button type="button" data-v="reset" aria-label="Reset zoom"><?= icon('refresh') ?></button>
            <a data-v-full href="#" target="_blank" rel="noopener" aria-label="Open full-size original"><?= icon('download') ?></a>
            <button type="button" data-v="close" aria-label="Close"><?= icon('x') ?></button>
        </div>
    </div>
    <button type="button" class="cmp-viewer__nav cmp-viewer__nav--prev" data-v="prev" aria-label="Previous picture"><?= icon('chevron-left') ?></button>
    <div class="cmp-viewer__stage" data-v-stage><img alt="" data-v-img draggable="false"></div>
    <button type="button" class="cmp-viewer__nav cmp-viewer__nav--next" data-v="next" aria-label="Next picture"><?= icon('chevron-right') ?></button>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
