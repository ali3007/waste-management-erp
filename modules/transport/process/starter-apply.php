<?php
/**
 * Apply the ticked entries from data/starter.json — only after an authorised
 * person has seen the list and confirmed. Existing names are skipped, so it
 * can be run again safely.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = 'modules/transport/config.php?tab=start';
trn_post_guard('transport.config', $back);

$data = json_decode((string)@file_get_contents(dirname(__DIR__) . '/data/starter.json'), true);
if (!is_array($data)) {
    trn_back($back, 'error', 'The starter data file could not be read.');
}
$picked = $_POST['pick'] ?? [];
$added = 0;
$skipped = 0;

try {
    Database::beginTransaction();
    foreach (trn_config_registry() as $kind => $reg) {
        $chosen = is_array($picked[$kind] ?? null) ? $picked[$kind] : [];
        foreach ($chosen as $idx) {
            $item = $data[$kind][(int)$idx] ?? null;
            if (!is_array($item) || empty($item['name'])) {
                continue;
            }
            $name = Security::cleanText((string)$item['name'], 100);
            if (Database::exists("SELECT id FROM `{$reg['table']}` WHERE name = :n", ['n' => $name])) {
                $skipped++;
                continue;
            }
            $row = ['name' => $name, 'created_by' => Auth::id(), 'updated_by' => Auth::id()];
            if ($kind === 'vehicle_status') {
                $cat = (string)($item['category'] ?? 'active');
                $row['category'] = isset(trn_status_categories()[$cat]) ? $cat : 'active';
                $hasDefault = Database::exists('SELECT id FROM vehicle_statuses WHERE is_default = 1');
                $row['is_default'] = (!empty($item['is_default']) && !$hasDefault && $row['category'] === 'active') ? 1 : 0;
            }
            if ($kind === 'document_type') {
                $row['is_required'] = !empty($item['is_required']) ? 1 : 0;
                $row['requires_expiry'] = !empty($item['requires_expiry']) ? 1 : 0;
            }
            $id = Database::insert($reg['table'], $row);
            trn_audit('config.' . $kind . '_created', $kind, $id, $reg['label'] . ' created from starter data: ' . $name, null, ['Name' => $name]);
            $added++;
        }
    }
    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    trn_back($back, 'error', Database::friendlyError($e));
}
trn_back($back, $added > 0 ? 'success' : 'info', $added . ' entr' . ($added === 1 ? 'y' : 'ies') . ' added' . ($skipped ? ', ' . $skipped . ' already existed' : '') . '.');
