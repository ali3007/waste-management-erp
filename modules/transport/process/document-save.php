<?php
/**
 * Vehicle documents: upload (first copy), replace (new file, old one kept in
 * history) or update (dates / remarks only). One CURRENT document per vehicle
 * and type; the database enforces it.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$action = in_array(post('action'), ['upload', 'replace', 'update'], true) ? post('action') : 'upload';
$back = trn_return_url('modules/transport/documents.php');
trn_post_guard('transport.documents_manage', $back);

$issue = post('issue_date');
$expiry = post('expiry_date');
$remarks = Security::cleanMultiline(post('remarks'), 500);
$issueD = $issue !== '' ? trn_valid_date($issue) : null;
$expiryD = $expiry !== '' ? trn_valid_date($expiry, '1950-01-01', '2150-12-31') : null;
if (($issue !== '' && $issueD === null) || ($expiry !== '' && $expiryD === null)) {
    trn_back_with_old($back, 'Enter valid dates.');
}
if ($issueD !== null && $expiryD !== null && $expiryD < $issueD) {
    trn_back_with_old($back, 'The expiry date cannot be before the issue date.');
}

$doc = null;
$docType = null;
if ($action === 'upload') {
    $vehicle = trn_require_vehicle((int)post('vehicle_id'));
    $docType = Database::fetch("SELECT * FROM vehicle_document_types WHERE id = :id AND status = 'active'", ['id' => (int)post('document_type_id')]);
    if ($docType === null) {
        trn_back_with_old($back, 'Choose an available document type.');
    }
} else {
    $doc = Database::fetch('SELECT * FROM vehicle_documents WHERE id = :id AND current_flag = 1', ['id' => (int)post('document_id')]);
    if ($doc === null) {
        trn_back($back, 'error', 'That document could not be found, or is no longer current.');
    }
    $vehicle = trn_require_vehicle((int)$doc['vehicle_id']); // also enforces area scope
    $docType = Database::fetch('SELECT * FROM vehicle_document_types WHERE id = :id', ['id' => (int)$doc['document_type_id']]);
}
if ($vehicle['deleted_at'] !== null) {
    trn_back($back, 'error', 'This vehicle is archived. Restore it before changing its documents.');
}
if ((int)$docType['requires_expiry'] === 1 && $expiryD === null) {
    trn_back_with_old($back, '"' . $docType['name'] . '" needs an expiry date.');
}

$vehicleId = (int)$vehicle['id'];
$hasFile = hr_has_upload('document_file');
if ($action !== 'update' && !$hasFile) {
    trn_back_with_old($back, 'Choose the document file to upload.');
}
if ($hasFile && $action !== 'update') {
    $check = trn_validate_upload($_FILES['document_file'], 'doc');
    if (!$check['ok']) {
        trn_back_with_old($back, $check['message']);
    }
}

$stored = null;
try {
    Database::beginTransaction();
    trn_lock_vehicle($vehicleId);

    if ($action === 'upload') {
        if (Database::exists('SELECT id FROM vehicle_documents WHERE vehicle_id = :v AND document_type_id = :t AND current_flag = 1', ['v' => $vehicleId, 't' => $docType['id']])) {
            throw new RuntimeException('This vehicle already has a current "' . $docType['name'] . '". Use Replace to upload a new copy.');
        }
    }

    if ($action === 'update') {
        Database::update('vehicle_documents', ['issue_date' => $issueD, 'expiry_date' => $expiryD, 'remarks' => $remarks !== '' ? $remarks : null,
            'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int)$doc['id']]);
        [$o, $n] = trn_diff($doc, ['issue_date' => $issueD, 'expiry_date' => $expiryD, 'remarks' => $remarks ?: null], ['issue_date', 'expiry_date', 'remarks']);
        trn_audit('document.updated', 'vehicle', $vehicleId, $docType['name'] . ' details updated on ' . $vehicle['registration_no'], $o ?: null, $n ?: null, null, $vehicleId);
        $msg = $docType['name'] . ' updated.';
    } else {
        $stored = trn_store_upload($_FILES['document_file'], 'doc', $vehicleId);
        if (!$stored['ok']) {
            throw new RuntimeException($stored['message']);
        }
        if ($action === 'replace') {
            Database::execute("UPDATE vehicle_documents SET state = 'replaced', current_flag = NULL, closed_at = NOW(), closed_by = :by, close_reason = 'Replaced by a newer copy' WHERE id = :id",
                ['by' => Auth::id(), 'id' => (int)$doc['id']]);
        }
        $newId = Database::insert('vehicle_documents', [
            'vehicle_id' => $vehicleId, 'document_type_id' => (int)$docType['id'], 'file_name' => $stored['file'],
            'original_name' => $stored['original'], 'mime_type' => $stored['mime'], 'file_size' => $stored['size'],
            'issue_date' => $issueD, 'expiry_date' => $expiryD, 'remarks' => $remarks !== '' ? $remarks : null,
            'state' => 'current', 'current_flag' => 1, 'uploaded_by' => Auth::id(),
        ]);
        if ($action === 'replace') {
            trn_audit('document.replaced', 'vehicle', $vehicleId, $docType['name'] . ' replaced on ' . $vehicle['registration_no'],
                ['File' => $doc['original_name'], 'Expiry' => $doc['expiry_date']], ['File' => $stored['original'], 'Expiry' => $expiryD], null, $vehicleId);
            $msg = $docType['name'] . ' replaced. The previous copy is kept in Document History.';
        } else {
            trn_audit('document.uploaded', 'vehicle', $vehicleId, $docType['name'] . ' uploaded for ' . $vehicle['registration_no'],
                null, ['File' => $stored['original'], 'Expiry' => $expiryD], null, $vehicleId);
            $msg = $docType['name'] . ' uploaded.';
        }
    }
    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    if ($stored !== null && !empty($stored['file'])) {
        trn_delete_file($stored['file']);
    }
    $text = $e instanceof RuntimeException ? $e->getMessage() : (trn_is_duplicate_key($e) ? 'This vehicle already has a current document of that type.' : Database::friendlyError($e));
    trn_back_with_old($back, $text);
}
clear_old();
trn_back($back, 'success', $msg);
