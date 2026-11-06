<?php
/**
 * Vehicle Documents — every current document across the fleet, with expiry
 * status; upload, replace, update, delete (soft). Replaced / deleted copies
 * are kept and visible in each vehicle's Documents tab.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}
Permission::require('transport.documents_view');
$canManage = can('transport.documents_manage');
$canDownload = can('transport.documents_download');

$f = trn_read_vehicle_filters($_GET);
$f['doc_type'] = max(0, (int)query('doc_type'));
$docStatusSel = in_array(query('doc_status'), ['valid', 'expiring', 'expired', 'none'], true) ? query('doc_status') : '';
$pv = (int)query('vehicle');

[$vw, $vp] = trn_vehicle_filter_sql($f);
$st = trn_doc_status_sql('d', 't');
$where = "d.current_flag = 1 AND d.vehicle_id IN (SELECT v.id " . trn_vehicle_from() . " WHERE {$vw})";
$params = $vp;
if ($f['doc_type'] > 0) { $where .= ' AND d.document_type_id = :dt'; $params['dt'] = $f['doc_type']; }
if ($pv > 0) { $where .= ' AND d.vehicle_id = :pv'; $params['pv'] = $pv; }
if ($docStatusSel !== '') { $where .= " AND ({$st}) = :ds"; $params['ds'] = $docStatusSel; }

$base = "FROM vehicle_documents d JOIN vehicle_document_types t ON t.id = d.document_type_id JOIN vehicles v ON v.id = d.vehicle_id WHERE {$where}";
$total = (int)Database::fetchValue("SELECT COUNT(*) {$base}", $params);
$pg = trn_paginate($total, 25);
$rows = Database::fetchAll("SELECT d.*, t.name AS type_name, t.requires_expiry, v.registration_no, v.deleted_at AS v_deleted, {$st} AS st {$base}
    ORDER BY d.expiry_date IS NULL ASC, d.expiry_date ASC, v.registration_no ASC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);

$pre = null;
if ($canManage && $pv > 0) {
    $pre = trn_find_vehicle($pv);
    if ($pre !== null && !trn_vehicle_in_scope($pv)) { $pre = null; }
}
$docTypes = trn_options('document_type');
$qsKeep = static fn(array $extra = []): string => trn_report_query($f + ['doc_type' => $f['doc_type']], $extra + ['doc_status' => $docStatusSel, 'vehicle' => $pv]);
$here = 'modules/transport/documents.php' . $qsKeep(['page' => $pg['page'] > 1 ? $pg['page'] : null]);
$filterAction = base_url('modules/transport/documents.php');
$filterDocTypes = true;
$filterDocStatus = true;
$filterShowSearch = true;
$filterKeep = $pv > 0 ? ['vehicle' => $pv] : [];

$pageTitle = 'Vehicle Documents';
$layout = 'app';
$extraScripts = ['hr.js', 'transport.js'];
require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/components/alerts.php';
if ($canManage && !$docTypes): trn_setup_notice(['document_type' => 'document types']); endif;
trn_search_bar();

if ($canManage && $docTypes): ?>
<section class="card">
    <h3 class="card__title">Upload a document</h3>
    <form method="post" action="<?= e(base_url('modules/transport/process/document-save.php')) ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?><input type="hidden" name="action" value="upload"><input type="hidden" name="return_to" value="<?= e($here) ?>">
        <div class="field-row">
            <?php trn_picker('vehicle', 'vehicle_id', 'Vehicle', $pre ? (int)$pre['id'] : ((int)old('vehicle_id', '') ?: null), $pre ? (string)$pre['registration_no'] : '', true); ?>
            <?php trn_field_select('up_type', 'Document type', $docTypes, old('document_type_id', ''), 'Choose a type…', ['name' => 'document_type_id', 'required' => true]); ?>
        </div>
        <div class="field-row">
            <?php field_input('up_issue', 'Issue date (optional)', ['name' => 'issue_date', 'type' => 'date', 'value' => old('issue_date', '')]); ?>
            <?php field_input('up_expiry', 'Expiry date', ['name' => 'expiry_date', 'type' => 'date', 'value' => old('expiry_date', ''), 'hint' => 'Required for document types that expire.']); ?>
        </div>
        <div class="field-row">
            <div class="field"><label class="field__label" for="up_file">File <span class="field__required">*</span></label>
                <div class="control"><input class="input" type="file" id="up_file" name="document_file" accept="application/pdf,image/jpeg,image/png,image/gif,image/webp"></div>
                <p class="field__hint">PDF, JPG, PNG, GIF or WEBP, up to <?= e(hr_size_label(TRN_DOC_MAX_BYTES)) ?>.</p></div>
            <?php field_input('up_remarks', 'Remarks (optional)', ['name' => 'remarks', 'value' => old('remarks', ''), 'maxlength' => 500]); ?>
        </div>
        <button type="submit" class="btn btn--primary"><span class="btn__label">Upload document</span></button>
    </form>
</section>
<?php clear_old(); endif; ?>

<?php require __DIR__ . '/partials/vehicle-filter.php'; ?>

<?php if (!$rows): ?>
    <div class="card"><div class="empty-state"><?= icon('file-text') ?><h3 style="margin-bottom:6px">No documents found</h3><p style="margin:0">Upload one above, or relax the filters.</p></div></div>
<?php else: ?>
    <div class="table-meta"><span><?= number_format($total) ?> document<?= $total === 1 ? '' : 's' ?></span><span>Page <?= $pg['page'] ?> of <?= $pg['pages'] ?></span></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Vehicle</th><th>Document</th><th>Issued</th><th>Expires</th><th>Status</th><th>File</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="<?= e(base_url('modules/transport/vehicle-view.php?id=' . (int)$r['vehicle_id'] . '&tab=documents')) ?>"><strong><?= e((string)$r['registration_no']) ?></strong></a></td>
                <td><?= e((string)$r['type_name']) ?></td>
                <td><?= e(trn_date($r['issue_date'])) ?></td><td><?= e(trn_date($r['expiry_date'])) ?></td>
                <td><span class="badge <?= e(trn_doc_status_badge((string)$r['st'])) ?>"><?= e(trn_doc_status_label((string)$r['st'])) ?></span></td>
                <td class="is-wrap"><?= e((string)$r['original_name']) ?><span class="cell-sub"><?= e(hr_size_label((int)$r['file_size'])) ?></span></td>
                <td class="is-wrap">
                    <div class="cell-actions">
                        <a class="icon-btn" target="_blank" rel="noopener" href="<?= e(trn_file_url('doc', (int)$r['id'])) ?>" title="View" aria-label="View"><?= icon('eye') ?></a>
                        <?php if ($canDownload): ?><a class="icon-btn" href="<?= e(trn_file_url('doc', (int)$r['id'], true)) ?>" title="Download" aria-label="Download"><?= icon('download') ?></a><?php endif; ?>
                    </div>
                    <?php if ($canManage && $r['v_deleted'] === null): ?>
                    <details class="trn-more"><summary>Manage</summary>
                        <form method="post" action="<?= e(base_url('modules/transport/process/document-save.php')) ?>" enctype="multipart/form-data">
                            <?= csrf_field() ?><input type="hidden" name="document_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <?php field_input('md_issue_' . (int)$r['id'], 'Issue date', ['name' => 'issue_date', 'type' => 'date', 'value' => (string)$r['issue_date']]); ?>
                            <?php field_input('md_exp_' . (int)$r['id'], 'Expiry date', ['name' => 'expiry_date', 'type' => 'date', 'value' => (string)$r['expiry_date']]); ?>
                            <?php field_input('md_rem_' . (int)$r['id'], 'Remarks', ['name' => 'remarks', 'value' => (string)$r['remarks'], 'maxlength' => 500]); ?>
                            <div class="field"><label class="field__label" for="md_file_<?= (int)$r['id'] ?>">New file (only to replace)</label>
                                <div class="control"><input class="input" type="file" id="md_file_<?= (int)$r['id'] ?>" name="document_file" accept="application/pdf,image/jpeg,image/png,image/gif,image/webp"></div></div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <button type="submit" name="action" value="update" class="btn btn--ghost"><span class="btn__label">Save details</span></button>
                                <button type="submit" name="action" value="replace" class="btn btn--primary"><span class="btn__label">Replace file</span></button>
                            </div>
                        </form>
                        <form method="post" action="<?= e(base_url('modules/transport/process/document-delete.php')) ?>" data-confirm="Delete this document? It stays in Document History." style="margin-top:8px">
                            <?= csrf_field() ?><input type="hidden" name="document_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <button type="submit" class="btn btn--danger"><span class="btn__label">Delete</span></button>
                        </form>
                    </details>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php trn_pagination($pg, static fn(int $p): string => $filterAction . $qsKeep(['page' => $p])); ?>
<?php endif; ?>
<?php require INCLUDES_PATH . '/footer.php'; ?>
