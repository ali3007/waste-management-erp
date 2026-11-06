<?php
/**
 * Complaints — HTML fragments shared by the dashboard (AJAX), the reports
 * page and nothing else: the listing table and its pagination. One place,
 * so the dashboard's listing and the reports page can't drift apart.
 */
declare(strict_types=1);
defined('SWMS') || exit('Direct access is not allowed.');

/* Endpoints that return these fragments (AJAX) don't load the page header. */
require_once INCLUDES_PATH . '/components/icons.php';

/** The listing table body + header for a page of complaints. */
function cmp_render_table(array $rows, bool $showDeleted = false): string
{
    if (!$rows) {
        return '<div class="empty-state">' . icon('search') . '<h3 style="margin-bottom:6px">No complaints found</h3>'
            . '<p style="margin:0">Nothing matches these filters. Loosen a filter or choose another date range.</p></div>';
    }
    $now = cmp_now();
    ob_start();
    ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Complaint No.</th><th>Date</th><th>Complainant</th><th>Type</th><th>Source</th><th>Priority</th><th>UC / Beat</th><th>Status</th><th>SLA</th><th>Pictures</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $sla = cmp_sla_state($r, $now); ?>
                    <tr>
                        <td><a href="<?= e(base_url('modules/complaints/complaint-view.php?id=' . (int)$r['id'])) ?>"><strong><?= e((string)$r['complaint_number']) ?></strong></a></td>
                        <td><?= e(cmp_fmt_date((string)$r['complaint_date'])) ?></td>
                        <td class="is-wrap"><?= e((string)$r['complainant_name']) ?><?php if ($r['phone']): ?><span class="cell-sub"><?= e((string)$r['phone']) ?></span><?php endif; ?></td>
                        <td class="is-wrap"><?= e((string)$r['type_name']) ?></td>
                        <td><?= e((string)$r['source_name']) ?></td>
                        <td><?= cmp_priority_badge((string)$r['priority_name'], (string)$r['priority_color']) ?></td>
                        <td class="is-wrap"><?= e((string)$r['uc_name']) ?><?php if ($r['beat_name']): ?><span class="cell-sub"><?= e((string)$r['beat_name']) ?></span><?php endif; ?></td>
                        <td><?= cmp_status_badge((string)$r['status']) ?><?= $r['deleted_at'] ? ' <span class="badge badge--inactive">Deleted</span>' : '' ?></td>
                        <td><?= $sla === 'none' ? '<span class="cell-sub">—</span>' : cmp_sla_badge($sla) ?></td>
                        <td><span class="cell-sub"><?= (int)$r['before_count'] ?> before · <?= (int)$r['after_count'] ?> after</span></td>
                        <td><div class="cell-actions"><a class="icon-btn" href="<?= e(base_url('modules/complaints/complaint-view.php?id=' . (int)$r['id'])) ?>" title="Open" aria-label="Open"><?= icon('eye') ?></a></div></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return (string)ob_get_clean();
}

/** Pagination links. $href is called with a page number and returns the URL. */
function cmp_render_pagination(int $page, int $total, int $perPage, callable $href, bool $js = false): string
{
    $pages = max(1, (int)ceil($total / $perPage));
    if ($pages <= 1) {
        return '';
    }
    $link = static function (int $p, string $label, bool $current = false) use ($href, $js): string {
        if ($current) {
            return '<span class="is-current">' . e($label) . '</span>';
        }
        return '<a href="' . e($href($p)) . '"' . ($js ? ' data-page="' . $p . '"' : '') . '>' . e($label) . '</a>';
    };
    $html = '<nav class="pagination" aria-label="Pagination">';
    $from = max(1, $page - 3);
    $to = min($pages, $page + 3);
    if ($from > 1) {
        $html .= $link(1, '1') . ($from > 2 ? '<span>…</span>' : '');
    }
    for ($p = $from; $p <= $to; $p++) {
        $html .= $link($p, (string)$p, $p === $page);
    }
    if ($to < $pages) {
        $html .= ($to < $pages - 1 ? '<span>…</span>' : '') . $link($pages, (string)$pages);
    }
    return $html . '</nav>';
}
