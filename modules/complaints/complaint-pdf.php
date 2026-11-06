<?php
/** Download one complaint as a PDF (details, assignment, SLA, evidence, timeline). */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/pdf.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('complaint.reports');
$complaint = cmp_require_access((int)query('id'), 'complaint.view');

try {
    cmp_timeline((int)$complaint['id'], 'pdf', 'Complaint PDF generated');
    cmp_pdf_complaint($complaint);
} catch (Throwable $e) {
    log_event('complaints', 'Complaint PDF failed', ['id' => (int)$complaint['id'], 'error' => $e->getMessage()]);
    flash('error', 'The PDF could not be generated. ' . (APP_ENV === 'development' ? $e->getMessage() : 'Check the log for details.'));
    redirect('modules/complaints/complaint-view.php?id=' . (int)$complaint['id']);
}
