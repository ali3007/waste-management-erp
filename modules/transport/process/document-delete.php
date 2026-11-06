<?php
/** Soft-delete a current vehicle document. The file and record stay in Document History. */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = trn_return_url('modules/transport/documents.php');
trn_post_guard('transport.documents_manage', $back);

$doc = Database::fetch('SELECT d.*, t.name AS type_name FROM vehicle_documents d JOIN vehicle_document_types t ON t.id = d.document_type_id WHERE d.id = :id AND d.current_flag = 1', ['id' => (int)post('document_id')]);
if ($doc === null) {
    trn_back($back, 'error', 'That document could not be found.');
}
$vehicle = trn_require_vehicle((int)$doc['vehicle_id']);
$reason = Security::cleanText(post('reason'), 255);

try {
    Database::beginTransaction();
    Database::execute("UPDATE vehicle_documents SET state = 'deleted', current_flag = NULL, closed_at = NOW(), closed_by = :by, close_reason = :r WHERE id = :id",
        ['by' => Auth::id(), 'r' => $reason !== '' ? $reason : 'Deleted', 'id' => (int)$doc['id']]);
    trn_audit('document.deleted', 'vehicle', (int)$vehicle['id'], $doc['type_name'] . ' deleted from ' . $vehicle['registration_no'],
        ['File' => $doc['original_name']], null, $reason, (int)$vehicle['id']);
    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    trn_back($back, 'error', Database::friendlyError($e));
}
trn_back($back, 'success', $doc['type_name'] . ' deleted. It remains in Document History.');
