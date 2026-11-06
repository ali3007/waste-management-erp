<?php
/** Small rendering helpers shared by the attendance pages. */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/** <option> list from id/name rows. */
function att_options(array $rows, int|string $current, string $placeholder = '', string $nameKey = 'name'): string
{
    $html = $placeholder !== '' ? '<option value="">' . e($placeholder) . '</option>' : '';
    foreach ($rows as $r) {
        $html .= '<option value="' . (int)$r['id'] . '"' . ((string)$current === (string)$r['id'] ? ' selected' : '') . '>' . e((string)$r[$nameKey]) . '</option>';
    }
    return $html;
}

/** "08:00 AM – 10:00 AM" */
function att_fmt_window(array $cfg, string $event): string
{
    return att_fmt_time($cfg[$event . '_start']) . ' – ' . att_fmt_time($cfg[$event . '_end']);
}

/** Time of day of a stored datetime, for tables. */
function att_clock(?string $dt): string
{
    return $dt ? date('h:i A', strtotime($dt) ?: 0) : '—';
}

/** Hidden CSRF + return target for the standard POST forms. */
function att_form_fields(string $returnTo = ''): string
{
    return csrf_field() . ($returnTo !== '' ? '<input type="hidden" name="return_to" value="' . e($returnTo) . '">' : '');
}

/** Pager links for ?page=N lists. */
function att_pager(array $pg, string $baseUrl, array $keep): string
{
    if ($pg['pages'] <= 1) {
        return '';
    }
    $link = static fn(int $p, string $label, bool $disabled = false): string =>
        $disabled ? '<span class="btn btn--ghost" aria-disabled="true">' . e($label) . '</span>'
                  : '<a class="btn btn--ghost" href="' . e($baseUrl . att_qs($keep + ['page' => $p])) . '">' . e($label) . '</a>';
    return '<div class="att-pager">' . $link($pg['page'] - 1, '‹ Previous', $pg['page'] <= 1)
        . '<span class="att-pager__info">Page ' . $pg['page'] . ' of ' . $pg['pages'] . '</span>'
        . $link($pg['page'] + 1, 'Next ›', $pg['page'] >= $pg['pages']) . '</div>';
}
