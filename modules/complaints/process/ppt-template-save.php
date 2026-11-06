<?php
/**
 * PowerPoint template management: upload, replace, rename, activate /
 * deactivate, delete. The uploaded .pptx is validated (real ZIP, OOXML
 * structure, no macros, no unsafe paths, size limits) before it is stored
 * under a generated name in storage/ppt-templates/.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ppt.php';

if (!IS_INSTALLED) {
    redirect('install/index.php');
}

Permission::require('complaint.ppt_templates');

$back = 'modules/complaints/ppt-templates.php';
if (!is_post()) {
    redirect($back);
}
Security::requireCsrf($back);

$op = post('op');
$id = (int)post('id');

/** Validate + store the uploaded file. @return array{file:string,original:string,size:int,info:array}|string */
$storeUpload = static function (): array|string {
    $f = $_FILES['file'] ?? null;
    if (!is_array($f) || is_array($f['error'] ?? null) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'Choose a .pptx file to upload.';
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'File size exceeds allowed limit (' . cmp_fmt_bytes(CMP_PPT_MAX_BYTES) . ').' : 'The file could not be uploaded.';
    }
    if ((int)$f['size'] <= 0 || (int)$f['size'] > CMP_PPT_MAX_BYTES) {
        return 'File size exceeds allowed limit (' . cmp_fmt_bytes(CMP_PPT_MAX_BYTES) . ').';
    }
    if (!is_uploaded_file((string)$f['tmp_name'])) {
        return 'Invalid upload.';
    }
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pptx') {
        return 'Invalid file format — upload a PowerPoint .pptx file (macro-enabled .pptm and old .ppt are not supported).';
    }
    $info = cmp_ppt_inspect((string)$f['tmp_name']);
    if (is_string($info)) {
        return $info;
    }
    $dir = CMP_TEMPLATE_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\nOptions -Indexes\n");
        @file_put_contents($dir . '/index.html', '');
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return 'The template folder is not writable. Contact the administrator.';
    }
    $stored = 'tpl-' . bin2hex(random_bytes(10)) . '.pptx';
    if (!move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $stored)) {
        return 'The file could not be saved.';
    }
    @chmod($dir . '/' . $stored, 0640);
    return ['file' => $stored, 'original' => cmp_clean_filename((string)$f['name']), 'size' => (int)$f['size'], 'info' => $info];
};

$now = cmp_now();

try {
    if ($op === 'upload') {
        $name = Security::cleanText(post('name'), 120);
        if ($name === '') {
            flash('error', 'Enter a name for the template.');
            redirect($back);
        }
        if (Database::exists('SELECT id FROM ppt_templates WHERE name = :n', ['n' => $name])) {
            flash('error', 'A template with that name already exists.');
            redirect($back);
        }
        $r = $storeUpload();
        if (is_string($r)) {
            flash('error', $r);
            redirect($back);
        }
        Database::insert('ppt_templates', [
            'name' => $name, 'description' => Security::cleanText(post('description'), 255) ?: null, 'file_name' => $r['file'],
            'original_name' => $r['original'], 'file_size' => $r['size'], 'slide_count' => $r['info']['slide_count'], 'layout_count' => $r['info']['layout_count'],
            'slide_width' => $r['info']['width'], 'slide_height' => $r['info']['height'], 'status' => 'active',
            'uploaded_by' => Auth::id(), 'uploaded_at' => $now, 'updated_at' => $now,
        ]);
        flash('success', 'Template "' . $name . '" uploaded and activated.');
    } elseif ($op === 'replace') {
        $row = Database::fetch('SELECT * FROM ppt_templates WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            flash('error', 'Template not found.');
            redirect($back);
        }
        $r = $storeUpload();
        if (is_string($r)) {
            flash('error', $r);
            redirect($back . '?replace=' . $id);
        }
        Database::update('ppt_templates', [
            'file_name' => $r['file'], 'original_name' => $r['original'], 'file_size' => $r['size'], 'slide_count' => $r['info']['slide_count'],
            'layout_count' => $r['info']['layout_count'], 'slide_width' => $r['info']['width'], 'slide_height' => $r['info']['height'],
            'uploaded_by' => Auth::id(), 'updated_at' => $now,
        ], ['id' => $id]);
        $old = CMP_TEMPLATE_DIR . '/' . $row['file_name'];
        if (preg_match('/^[A-Za-z0-9._-]+$/', (string)$row['file_name']) && is_file($old)) {
            @unlink($old);
        }
        flash('success', 'Template file replaced.');
    } elseif ($op === 'rename') {
        $name = Security::cleanText(post('name'), 120);
        if ($name === '' || Database::exists('SELECT id FROM ppt_templates WHERE name = :n AND id != :id', ['n' => $name, 'id' => $id])) {
            flash('error', $name === '' ? 'Enter a name.' : 'A template with that name already exists.');
            redirect($back);
        }
        Database::update('ppt_templates', ['name' => $name, 'updated_at' => $now], ['id' => $id]);
        flash('success', 'Template renamed.');
    } elseif ($op === 'toggle') {
        Database::execute("UPDATE ppt_templates SET status = IF(status = 'active', 'inactive', 'active'), updated_at = :n WHERE id = :id", ['n' => $now, 'id' => $id]);
        flash('success', 'Template status updated.');
    } elseif ($op === 'delete') {
        $row = Database::fetch('SELECT * FROM ppt_templates WHERE id = :id', ['id' => $id]);
        if ($row !== null) {
            Database::execute('DELETE FROM ppt_templates WHERE id = :id', ['id' => $id]);
            $path = CMP_TEMPLATE_DIR . '/' . $row['file_name'];
            if (preg_match('/^[A-Za-z0-9._-]+$/', (string)$row['file_name']) && is_file($path)) {
                @unlink($path);
            }
            flash('success', 'Template deleted.');
        }
    }
} catch (Throwable $e) {
    log_event('complaints', 'PPT template save failed', ['op' => $op, 'error' => $e->getMessage()]);
    flash('error', Database::friendlyError($e));
}
redirect($back);
