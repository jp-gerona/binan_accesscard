<?php
/**
 * Sector list page (Admin > Reference Data > Sectors).
 *
 * Data comes from sector_management_view_data(); this view never touches a model.
 * Counts are whole-table, not the current page. Sectors are flat classifications,
 * so there is no category column here. Reuses the Manage Records .records-* layout
 * (managerecord.css) plus the shared lookup badge and action styles
 * (lookupmanagement.css).
 */
helper('dashboard_view');
// sector_management_view_data() also supplies the Add-Sector modal data
// ($existingShortcodes, for the inline duplicate check) so this view never
// instantiates a model itself. Sectors are flat classifications - no category.
extract(sector_management_view_data(get_defined_vars()), EXTR_OVERWRITE);

// Counts come from the server bundle (whole table), not the current page below.
$activeSectorCount   = (int) ($activeCount ?? 0);
$archivedSectorCount = (int) ($archivedCount ?? 0);
$allSectorCount      = $activeSectorCount + $archivedSectorCount;
$status              = (string) ($status ?? 'active');
$keyword             = (string) ($keyword ?? '');
$listRoute           = (string) ($listRoute ?? 'reference-data');
$tabParam            = (string) ($tabParam ?? '');
$perPage             = (int) ($perPage ?? 25);
$perPageOptions      = ($perPageOptions ?? []) ?: [10, 25, 50, 100];
// Read-only roles (Viewer) see the list without Add / Edit / Archive / Restore.
// Defaults true so the admin/developer sector page is unaffected.
$canManage           = (bool) ($canManage ?? true);

// Builds a page URL preserving the current database keyword + status + page size.
$sectorPageUrl = static function (int $targetPage) use ($listRoute, $keyword, $status, $perPage, $tabParam): string {
    $params = array_filter([
        'tab'      => $tabParam,
        'q'        => $keyword,
        'status'   => $status === 'active' ? '' : $status,
        'per_page' => $perPage !== 25 ? (string) $perPage : '',
        'page'     => $targetPage > 1 ? (string) $targetPage : '',
    ], static fn ($value): bool => $value !== '');

    return site_url($listRoute) . ($params === [] ? '' : '?' . http_build_query($params));
};

// "Clear" resets the whole toolbar (keyword + status filter, back to page 1)
// per the one-role-per-control rule; only the page size survives.
$sectorClearUrl = static function () use ($listRoute, $perPage, $tabParam): string {
    $params = array_filter([
        'tab'      => $tabParam,
        'per_page' => $perPage !== 25 ? (string) $perPage : '',
    ], static fn ($value): bool => $value !== '');

    return site_url($listRoute) . ($params === [] ? '' : '?' . http_build_query($params));
};
?>

<?php /* Toolbar above the card, Manage Records standard (components/records_toolbar_server +
         records-filter-panel.js live-apply + pills). Melbranch hooks preserved:
         data-sector-management-root, data-lookup-search local filter, .js-sector-modal-open +
         data-sector-* attributes, the sector-modal include. */ ?>
<?= view('components/toolbar', [
    'formAction' => site_url($listRoute),
    'formAria' => 'Search all sectors',
    'searchPlaceholder' => 'Search all sectors...',
    'keyword' => $keyword,
    'clearUrl' => $sectorClearUrl(),
    'pillsId' => 'sectorFilterPills',
    'hiddenHtml' => ($tabParam !== '' ? '<input type="hidden" name="tab" value="' . esc($tabParam, 'attr') . '">' : '')
        . ($perPage !== 25 ? '<input type="hidden" name="per_page" value="' . esc((string) $perPage, 'attr') . '">' : ''),
    'actionsHtml' => $canManage ? '<button class="' . btn('add') . ' flex-fill js-sector-modal-open" type="button" data-sector-mode="create"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Sector</button>' : '',
    'filterGroups' => [[
        'name' => 'status',
        'label' => 'Status',
        'options' => [
            ['value' => 'active', 'label' => "Active ({$activeSectorCount})", 'checked' => $status === 'active', 'default' => true],
            ['value' => 'archived', 'label' => "Archived ({$archivedSectorCount})", 'pill' => 'Archived', 'checked' => $status === 'archived'],
            ['value' => 'all', 'label' => "All ({$allSectorCount})", 'checked' => $status === 'all'],
        ],
    ]],
]) ?>
<?php
$sectorFooter = ($totalRows ?? 0) >= 0 ? view('components/table_footer', [
    'fromRecord' => $fromRecord,
    'toRecord' => $toRecord,
    'totalRows' => $totalRows,
    'page' => $page,
    'totalPages' => $totalPages,
    'pageUrl' => $sectorPageUrl,
]) : null;
?>

<section class="card batch-card sector-management" data-sector-management-root>
    <div class="card-body">
        <h2 class="batch-pane-title">Sectors</h2>
        <?= view('Lookups/sectors-body', get_defined_vars()) ?>
        <?php if ($sectorFooter !== null): ?>
            <div class="mt-3 small text-muted">
                <?= $sectorFooter ?>
            </div>
        <?php endif; ?>
    </div>
</section>


<?php if ($canManage): ?>
<?= view('Lookups/sector-modal', [
	'existingShortcodes' => $existingShortcodes,
]) ?>
<?php endif; ?>
