<?php
/**
 * Shared card-footer for every list: "Showing X to Y of Z entries" on the left,
 * numbered Bootstrap pagination on the right. Render with view() and pass the
 * result as components/card's $footer.
 *
 * Two modes:
 * - server: the caller paginates in PHP and passes a $pageUrl callable
 *   (fn(int $page): string). Pages render as links.
 * - client: the caller passes $clientKey; the row list is fully in the DOM and
 *   assets/js/dashboard/table-paginate.js fills both halves. The matching table
 *   wrapper carries data-table-paginate + data-paginate-key="<key>".
 *
 * Variables:
 * - $clientKey  string|null  client-mode key (see table-paginate.js)
 * - $entityLabel string      plural noun for the count text (default 'entries')
 * - $leftContent string|null raw HTML override for the left half
 * - $fromRecord int, $toRecord int, $totalRows int
 * - $page int, $totalPages int
 * - $pageUrl    callable|null fn(int $page): string
 * - $prevUrl string, $nextUrl string  (fallback when $pageUrl is absent)
 */
$clientKey = $clientKey ?? null;
$entityLabel = (string) ($entityLabel ?? 'entries');
$leftContent = $leftContent ?? null;
$fromRecord = (int) ($fromRecord ?? 0);
$toRecord = (int) ($toRecord ?? 0);
$totalRows = (int) ($totalRows ?? 0);
$page = (int) ($page ?? 1);
$totalPages = (int) ($totalPages ?? 1);
$pageUrl = $pageUrl ?? null;
$prevUrl = (string) ($prevUrl ?? '#');
$nextUrl = (string) ($nextUrl ?? '#');

// Page numbers around the current one, so a 50-page list stays one short row.
$windowSize = 5;
$firstShown = max(1, min($page - (int) floor($windowSize / 2), $totalPages - $windowSize + 1));
$lastShown = min($totalPages, $firstShown + $windowSize - 1);

$linkFor = static function (int $target) use ($pageUrl, $prevUrl, $nextUrl, $page): string {
    if (is_callable($pageUrl)) {
        return (string) $pageUrl($target);
    }

    return $target < $page ? $prevUrl : $nextUrl;
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 w-100">
    <div class="table-footer-left"<?= $clientKey !== null ? ' data-table-info="' . esc($clientKey, 'attr') . '"' : '' ?>>
        <?php if ($clientKey !== null): ?>
        <?php elseif ($leftContent !== null): ?>
            <?= $leftContent ?>
        <?php else: ?>
            Showing <?= esc((string) $fromRecord) ?> to <?= esc((string) $toRecord) ?> of <?= esc((string) $totalRows) ?> <?= esc($entityLabel) ?>
        <?php endif; ?>
    </div>
    <div class="table-footer-right"<?= $clientKey !== null ? ' data-table-paging="' . esc($clientKey, 'attr') . '"' : '' ?>>
        <?php if ($clientKey === null): ?>
            <ul class="pagination pagination-sm m-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= esc($linkFor(1), 'attr') ?>" aria-label="First page" <?= $page <= 1 ? 'tabindex="-1" aria-disabled="true"' : '' ?>>&laquo;</a>
                </li>
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= esc($linkFor(max(1, $page - 1)), 'attr') ?>" aria-label="Previous page" <?= $page <= 1 ? 'tabindex="-1" aria-disabled="true"' : '' ?>>&lsaquo;</a>
                </li>
                <?php for ($number = $firstShown; $number <= $lastShown; $number++): ?>
                <li class="page-item <?= $number === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= esc($linkFor($number), 'attr') ?>" <?= $number === $page ? 'aria-current="page"' : '' ?>><?= esc((string) $number) ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= esc($linkFor(min($totalPages, $page + 1)), 'attr') ?>" aria-label="Next page" <?= $page >= $totalPages ? 'tabindex="-1" aria-disabled="true"' : '' ?>>&rsaquo;</a>
                </li>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= esc($linkFor($totalPages), 'attr') ?>" aria-label="Last page" <?= $page >= $totalPages ? 'tabindex="-1" aria-disabled="true"' : '' ?>>&raquo;</a>
                </li>
            </ul>
        <?php endif; ?>
    </div>
</div>
