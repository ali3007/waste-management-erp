<?php
/**
 * Transport settings: document warning window, which HR staff pool/type
 * counts as a driver, one-vehicle-per-driver, currency label and units.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$back = 'modules/transport/config.php?tab=settings';
trn_post_guard('transport.config', $back);

$days = post('doc_warning_days');
if ($days === '' || !ctype_digit($days) || (int)$days > 3650) {
    trn_back($back, 'error', 'Document warning days must be a whole number between 0 and 3650.');
}

/* Only real, active HR pools/types are accepted. */
$validIds = static function (string $field, string $table): array {
    $posted = $_POST[$field] ?? [];
    $ids = [];
    foreach ((is_array($posted) ? $posted : []) as $v) {
        $n = (int)$v;
        if ($n > 0 && Database::exists("SELECT id FROM `{$table}` WHERE id = :id", ['id' => $n])) {
            $ids[$n] = $n;
        }
    }
    return array_values($ids);
};
$pools = $validIds('driver_pools', 'hr_staff_pools');
$types = $validIds('driver_types', 'hr_staff_types');

$new = [
    'transport_doc_warning_days' => (string)(int)$days,
    'transport_driver_pool_ids'  => implode(',', $pools),
    'transport_driver_type_ids'  => implode(',', $types),
    'transport_driver_multi'     => post('driver_multi') === '1' ? '1' : '0',
    'transport_currency'         => Security::cleanText(post('currency'), 12),
    'transport_distance_unit'    => Security::cleanText(post('distance_unit'), 10) ?: 'km',
    'transport_volume_unit'      => Security::cleanText(post('volume_unit'), 10) ?: 'L',
];

try {
    Database::beginTransaction();
    $old = [];
    foreach ($new as $k => $v) {
        $old[$k] = trn_setting($k);
        trn_save_setting($k, $v);
    }
    [$o, $n] = trn_diff($old, $new, array_keys($new));
    trn_audit('config.settings_updated', 'settings', 0, 'Transport settings updated', $o ?: null, $n ?: null);
    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    trn_back($back, 'error', Database::friendlyError($e));
}
trn_back($back, 'success', 'Settings saved.');
