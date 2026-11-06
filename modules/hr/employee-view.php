<?php
/**
 * Employee Profile — the corporate HR record for one employee.
 *
 * Header (company branding, photo, name, ID, status), personal and
 * employment information, registered area, current administrative
 * assignment(s) with history, and documents. Printable (see hr.css) and
 * the starting point for the profile PDF and employment letter.
 *
 * Documents are shown only to users with employee.doc_view and are
 * delivered through file.php, never by a direct URL.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('employee.view');

$id = (int)query('id');
$employee = $id > 0 ? hr_find_employee($id) : null;

if ($employee === null) {
    flash('error', 'Record not found.');
    redirect('modules/hr/employees.php');
}

/* Never trust the URL's id alone — a field-linked user can't open an
   employee outside their own accessible UCs by typing a different id. */
hr_require_employee_scope($id);

$assignments = Database::fetchAll(
    'SELECT a.*, u.name AS uc_name, b.name AS beat_name,
            t.name AS tehsil_name, d.name AS district_name, r.name AS region_name
       FROM employee_assignments a
       JOIN ucs u ON u.id = a.uc_id
       JOIN tehsils t ON t.id = u.tehsil_id
       JOIN districts d ON d.id = t.district_id
       JOIN regions r ON r.id = d.region_id
       LEFT JOIN beats b ON b.id = a.beat_id
      WHERE a.employee_id = :id
      ORDER BY (a.unassigned_at IS NULL) DESC, a.assigned_at DESC',
    ['id' => $id]
);
$current = array_values(array_filter($assignments, static fn($a) => $a['unassigned_at'] === null));
$past = array_values(array_filter($assignments, static fn($a) => $a['unassigned_at'] !== null));
$isAssigned = $current !== [];

$canDocView = can('employee.doc_view');
$documents = $canDocView
    ? Database::fetchAll('SELECT * FROM employee_documents WHERE employee_id = :id ORDER BY uploaded_at DESC', ['id' => $id])
    : [];

$company = company();
$logoUrl = company_logo_url();
$mode = (string)$employee['assignment_mode'];
$fmtDate = static fn(?string $v): string => $v ? format_datetime($v, 'd M Y') : '—';

$pageTitle = (string)$employee['name'];
$layout = 'app';

require INCLUDES_PATH . '/header.php';
?>

<a class="btn btn--ghost no-print" href="<?= e(base_url('modules/hr/employees.php')) ?>" style="margin-bottom:16px">
    <?= icon('arrow-left') ?><span class="btn__label">Back to employees</span>
</a>

<?php require INCLUDES_PATH . '/components/alerts.php'; ?>

<!-- Header -->
<section class="card profile-head">
    <div class="profile-head__brand">
        <?php if ($logoUrl !== ''): ?>
            <img src="<?= e($logoUrl) ?>" alt="">
        <?php endif; ?>
        <div>
            <strong><?= e((string)$company['name']) ?></strong>
            <span>Human Resources · Employee Profile</span>
        </div>
    </div>

    <?php if (!empty($employee['employee_picture']) && hr_doc_path((string)$employee['employee_picture']) !== null): ?>
        <img class="profile-photo" src="<?= e(hr_file_url($id, 'photo')) ?>" alt="Photo of <?= e((string)$employee['name']) ?>">
    <?php else: ?>
        <div class="profile-photo" aria-hidden="true"><?= e(initials((string)$employee['name'])) ?></div>
    <?php endif; ?>

    <div class="profile-head__main">
        <h2><?= e((string)$employee['name']) ?></h2>
        <div class="profile-head__code"><?= e((string)($employee['employee_code'] ?: '—')) ?> · <?= e((string)$employee['staff_type_name']) ?></div>
        <div class="profile-head__badges">
            <span class="badge <?= e(hr_status_badge((string)$employee['status'])) ?>"><?= e(hr_status_label((string)$employee['status'])) ?></span>
            <?php if ($mode === 'none'): ?>
                <span class="badge badge--inactive">No UC/Beat needed</span>
            <?php else: ?>
                <span class="badge <?= $isAssigned ? 'badge--active' : 'badge--pending' ?>"><?= $isAssigned ? 'Assigned' : 'Unassigned' ?></span>
            <?php endif; ?>
            <span class="badge"><?= e((string)$employee['staff_pool_name']) ?></span>
        </div>

        <div class="profile-actions no-print">
            <?php if (can('employee.update')): ?>
                <a class="btn" href="<?= e(base_url('modules/hr/employee-edit.php?id=' . $id)) ?>"><?= icon('pencil') ?><span class="btn__label">Edit</span></a>
            <?php endif; ?>
            <?php if ($mode !== 'none' && $employee['status'] === 'active' && (can('assignment.create') || can('assignment.update'))): ?>
                <a class="btn" href="<?= e(base_url('modules/hr/employee-assign.php?id=' . $id)) ?>">
                    <?= icon('layers') ?><span class="btn__label"><?= $isAssigned ? ($mode === 'multi' ? 'Manage assignments' : 'Reassign') : 'Assign' ?></span>
                </a>
            <?php endif; ?>
            <?php if (can('employee.pdf')): ?>
                <a class="btn btn--primary" href="<?= e(base_url('modules/hr/employee-pdf.php?id=' . $id)) ?>"><?= icon('download') ?><span class="btn__label">Download Employee Profile PDF</span></a>
            <?php endif; ?>
            <?php if (can('employee.letter')): ?>
                <?php if ($employee['status'] === 'active'): ?>
                    <a class="btn btn--primary" href="<?= e(base_url('modules/hr/employee-letter.php?id=' . $id)) ?>"><?= icon('file-text') ?><span class="btn__label">Generate Employment Letter</span></a>
                <?php else: ?>
                    <span class="btn" style="opacity:.55;cursor:not-allowed" title="Employment letters can only be generated for active employees"><?= icon('file-text') ?><span class="btn__label">Employment Letter (inactive)</span></span>
                <?php endif; ?>
            <?php endif; ?>
            <button type="button" class="btn btn--ghost" onclick="window.print()"><?= icon('printer') ?><span class="btn__label">Print</span></button>
        </div>
    </div>
</section>

<div class="profile-grid profile-section">
    <!-- Personal information -->
    <section class="card">
        <h3 class="card__title"><?= icon('user') ?>Personal information</h3>
        <dl class="definition">
            <dt>Employee Name</dt><dd><?= e((string)$employee['name']) ?></dd>
            <dt>Father's Name</dt><dd><?= e((string)$employee['father_name']) ?></dd>
            <dt>CNIC</dt><dd><?= e(Security::formatCnic((string)$employee['cnic'])) ?></dd>
            <dt>CNIC Issue Date</dt><dd><?= e($fmtDate($employee['cnic_issue_date'])) ?></dd>
            <dt>Date of Birth</dt><dd><?= e($fmtDate($employee['dob'])) ?></dd>
            <dt>Phone</dt><dd><?= e((string)($employee['phone'] ?: '—')) ?></dd>
            <dt>Address</dt><dd><?= nl2br(e((string)($employee['address'] ?: '—'))) ?></dd>
        </dl>
    </section>

    <!-- Employment / HR information -->
    <section class="card">
        <h3 class="card__title"><?= icon('id-card') ?>Employment / HR information</h3>
        <dl class="definition">
            <dt>Employee ID</dt><dd><?= e((string)($employee['employee_code'] ?: '—')) ?></dd>
            <dt>Staff Pool</dt><dd><?= e((string)$employee['staff_pool_name']) ?></dd>
            <dt>Staff Type</dt><dd><?= e((string)$employee['staff_type_name']) ?></dd>
            <dt>Employee Status</dt><dd><span class="badge <?= e(hr_status_badge((string)$employee['status'])) ?>"><?= e(hr_status_label((string)$employee['status'])) ?></span></dd>
            <dt>Assignment rule</dt>
            <dd><?= match ($mode) { 'none' => 'No UC/Beat needed', 'multi' => 'Several UCs at once', default => 'One UC (+ optional Beat)' } ?></dd>
            <dt>Registered area</dt>
            <dd><?= e((string)$employee['tehsil_name']) ?>, <?= e((string)$employee['district_name']) ?>, <?= e((string)$employee['region_name']) ?></dd>
            <dt>Record created</dt><dd><?= e(format_datetime($employee['created_at'] ?? null)) ?></dd>
            <dt>Last updated</dt><dd><?= e(format_datetime($employee['updated_at'] ?? null)) ?></dd>
        </dl>
    </section>
</div>

<!-- Administrative assignment -->
<section class="card profile-section">
    <h3 class="card__title"><?= icon('layers') ?>Administrative assignment</h3>
    <?php if ($mode === 'none'): ?>
        <p class="card__subtitle" style="margin:0">This staff type doesn't use UC/Beat assignment.</p>
    <?php elseif (!$isAssigned): ?>
        <p class="card__subtitle" style="margin:0">Not assigned yet — this employee is in the Unassigned Employees list.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Region</th><th>District</th><th>Tehsil</th><th>UC</th><th>Beat</th><th>Since</th></tr></thead>
                <tbody>
                    <?php foreach ($current as $a): ?>
                        <tr>
                            <td><?= e((string)$a['region_name']) ?></td>
                            <td><?= e((string)$a['district_name']) ?></td>
                            <td><?= e((string)$a['tehsil_name']) ?></td>
                            <td><?= e((string)$a['uc_name']) ?></td>
                            <td><?= $a['beat_name'] ? e((string)$a['beat_name']) : '—' ?></td>
                            <td><?= e($fmtDate((string)$a['assigned_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($past): ?>
        <details style="margin-top:var(--space-4)">
            <summary style="cursor:pointer;font-size:var(--text-sm);color:var(--text-muted)">Assignment history (<?= count($past) ?>)</summary>
            <ul class="history-list" style="margin-top:var(--space-2)">
                <?php foreach ($past as $a): ?>
                    <li>
                        <span><?= e((string)$a['uc_name']) ?><?= $a['beat_name'] ? ' / ' . e((string)$a['beat_name']) : '' ?> — <?= e((string)$a['tehsil_name']) ?>, <?= e((string)$a['district_name']) ?></span>
                        <small><?= e($fmtDate((string)$a['assigned_at'])) ?> → <?= e($fmtDate((string)$a['unassigned_at'])) ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
</section>

<!-- Documents -->
<?php if ($canDocView): ?>
    <?php
    $canDownload = can('employee.doc_download');
    $hasCnic = !empty($employee['cnic_picture']) && hr_doc_path((string)$employee['cnic_picture']) !== null;
    $hasPhoto = !empty($employee['employee_picture']) && hr_doc_path((string)$employee['employee_picture']) !== null;
    $iconFor = static fn(string $file): string => hr_is_image_file($file) ? 'image' : 'file-text';
    $sizeLabel = static fn($b): string => $b ? ($b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB') : '';
    ?>
    <section class="card profile-section" id="documents">
        <h3 class="card__title"><?= icon('database') ?>Documents</h3>

        <div class="doc-list">
            <!-- Employee picture -->
            <div class="doc-item">
                <?php if ($hasPhoto): ?>
                    <img class="doc-item__thumb" src="<?= e(hr_file_url($id, 'photo')) ?>" alt="">
                <?php else: ?>
                    <span class="doc-item__thumb"><?= icon('image') ?></span>
                <?php endif; ?>
                <div class="doc-item__body">
                    <strong>Employee Picture</strong>
                    <span><?= $hasPhoto ? 'On file' : 'Not uploaded' ?></span>
                </div>
                <?php if ($hasPhoto): ?>
                    <div class="doc-item__actions no-print">
                        <a class="icon-btn" href="<?= e(hr_file_url($id, 'photo')) ?>" target="_blank" rel="noopener" title="View" aria-label="View"><?= icon('eye') ?></a>
                        <?php if ($canDownload): ?><a class="icon-btn" href="<?= e(hr_file_url($id, 'photo', null, true)) ?>" title="Download" aria-label="Download"><?= icon('download') ?></a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- CNIC picture -->
            <div class="doc-item">
                <?php if ($hasCnic && hr_is_image_file((string)$employee['cnic_picture'])): ?>
                    <img class="doc-item__thumb" src="<?= e(hr_file_url($id, 'cnic')) ?>" alt="">
                <?php else: ?>
                    <span class="doc-item__thumb"><?= icon($hasCnic ? 'file-text' : 'id-card') ?></span>
                <?php endif; ?>
                <div class="doc-item__body">
                    <strong>CNIC Picture</strong>
                    <span><?= $hasCnic ? 'On file' : 'Not uploaded' ?></span>
                </div>
                <?php if ($hasCnic): ?>
                    <div class="doc-item__actions no-print">
                        <a class="icon-btn" href="<?= e(hr_file_url($id, 'cnic')) ?>" target="_blank" rel="noopener" title="View" aria-label="View"><?= icon('eye') ?></a>
                        <?php if ($canDownload): ?><a class="icon-btn" href="<?= e(hr_file_url($id, 'cnic', null, true)) ?>" title="Download" aria-label="Download"><?= icon('download') ?></a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Other documents -->
            <?php foreach ($documents as $doc): ?>
                <div class="doc-item">
                    <?php if (hr_is_image_file((string)$doc['file_name'])): ?>
                        <img class="doc-item__thumb" src="<?= e(hr_file_url($id, 'doc', (int)$doc['id'])) ?>" alt="">
                    <?php else: ?>
                        <span class="doc-item__thumb"><?= icon($iconFor((string)$doc['file_name'])) ?></span>
                    <?php endif; ?>
                    <div class="doc-item__body">
                        <strong><?= e((string)($doc['title'] ?: $doc['original_name'])) ?></strong>
                        <span><?= e((string)$doc['original_name']) ?> · <?= e($sizeLabel($doc['file_size'])) ?> · <?= e(format_datetime((string)$doc['uploaded_at'], 'd M Y')) ?></span>
                    </div>
                    <div class="doc-item__actions no-print">
                        <?php if (in_array(strtolower(pathinfo((string)$doc['file_name'], PATHINFO_EXTENSION)), ['jpg', 'png', 'gif', 'webp', 'pdf'], true)): ?>
                            <a class="icon-btn" href="<?= e(hr_file_url($id, 'doc', (int)$doc['id'])) ?>" target="_blank" rel="noopener" title="View" aria-label="View"><?= icon('eye') ?></a>
                        <?php endif; ?>
                        <?php if ($canDownload): ?>
                            <a class="icon-btn" href="<?= e(hr_file_url($id, 'doc', (int)$doc['id'], true)) ?>" title="Download" aria-label="Download"><?= icon('download') ?></a>
                        <?php endif; ?>
                        <?php if (can('employee.doc_delete')): ?>
                            <form method="post" action="<?= e(base_url('modules/hr/process/document-delete.php')) ?>" data-confirm="Delete this document? This cannot be undone.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="employee_id" value="<?= $id ?>">
                                <input type="hidden" name="document_id" value="<?= (int)$doc['id'] ?>">
                                <button type="submit" class="icon-btn icon-btn--danger" title="Delete" aria-label="Delete"><?= icon('trash') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($documents)): ?>
                <p class="card__subtitle" style="margin:4px 0 0">No other documents uploaded yet.</p>
            <?php endif; ?>
        </div>

        <?php if (can('employee.doc_create')): ?>
            <form class="upload-inline no-print" method="post" action="<?= e(base_url('modules/hr/process/document-upload.php')) ?>" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="employee_id" value="<?= $id ?>">
                <div class="field">
                    <label class="field__label" for="doc_title">Title (optional)</label>
                    <div class="control"><input class="input" type="text" id="doc_title" name="title" maxlength="150" placeholder="e.g. Contract 2026"></div>
                </div>
                <div class="field">
                    <label class="field__label" for="doc_file">Add document</label>
                    <input class="file-input" type="file" id="doc_file" name="document[]" multiple required
                           accept="application/pdf,.doc,.docx,image/png,image/jpeg,image/gif,image/webp">
                </div>
                <button type="submit" class="btn btn--primary"><?= icon('upload') ?><span class="btn__label">Upload</span></button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
