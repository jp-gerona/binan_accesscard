<?php
/**
 * Category list page (Admin > Reference Data > Categories).
 *
 * Data comes from category_management_view_data(); this view never touches a model.
 * Lists the standalone SERVICE categories from the `category` table (FA/SWPS/EDA, the
 * ones with no matching sector, since a sector acts as its own service category) and
 * lets an admin add, rename, archive or restore them through the shared
 * #categoryActionModal (see category-modal.php and categories-modal.js).
 *
 * Server-side guards (Lookups\CategoryController): a category may not duplicate a sector
 * (code or name), and one still used by an active service cannot be archived. Reuses the
 * Manage Records .records-* layout (managerecord.css) plus the shared lookup badge/action
 * styles (lookupmanagement.css).
 */
helper('dashboard_view');
// category_management_view_data() also supplies $existingCodes (all codes incl.
// archived, for the modal's duplicate check) so this view stays model-free.
extract(category_management_view_data(get_defined_vars()), EXTR_OVERWRITE);

// Counts come from the server bundle (whole table), not the current page below.
$activeCategoryCount   = (int) ($activeCount ?? 0);
$archivedCategoryCount = (int) ($archivedCount ?? 0);
$allCategoryCount      = $activeCategoryCount + $archivedCategoryCount;
$status                = (string) ($status ?? 'active');
$keyword               = (string) ($keyword ?? '');
$listRoute             = (string) ($listRoute ?? 'reference-data');
$tabParam              = (string) ($tabParam ?? '');
$perPage               = (int) ($perPage ?? 25);
$perPageOptions        = ($perPageOptions ?? []) ?: [10, 25, 50, 100];

// Builds a page URL preserving the current database keyword + status + page size.
$categoryPageUrl = static function (int $targetPage) use ($listRoute, $keyword, $status, $perPage, $tabParam): string {
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
$categoryClearUrl = static function () use ($listRoute, $perPage, $tabParam): string {
    $params = array_filter([
        'tab'      => $tabParam,
        'per_page' => $perPage !== 25 ? (string) $perPage : '',
    ], static fn ($value): bool => $value !== '');

    return site_url($listRoute) . ($params === [] ? '' : '?' . http_build_query($params));
};
?>

<?php /* Toolbar above the card, Manage Records standard (components/records_toolbar_server +
         records-filter-panel.js live-apply + pills). */ ?>
<?= view('components/toolbar', [
    'formAction' => site_url($listRoute),
    'formAria' => 'Search all categories',
    'searchPlaceholder' => 'Search all categories...',
    'keyword' => $keyword,
    'clearUrl' => $categoryClearUrl(),
    'pillsId' => 'categoryFilterPills',
    'hiddenHtml' => ($tabParam !== '' ? '<input type="hidden" name="tab" value="' . esc($tabParam, 'attr') . '">' : '')
        . ($perPage !== 25 ? '<input type="hidden" name="per_page" value="' . esc((string) $perPage, 'attr') . '">' : ''),
    'actionsHtml' => '<button class="' . btn('add') . ' flex-fill js-category-modal-open" type="button" data-category-mode="create"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Category</button>',
    'filterGroups' => [[
        'name' => 'status',
        'label' => 'Status',
        'options' => [
            ['value' => 'active', 'label' => "Active ({$activeCategoryCount})", 'checked' => $status === 'active', 'default' => true],
            ['value' => 'archived', 'label' => "Archived ({$archivedCategoryCount})", 'pill' => 'Archived', 'checked' => $status === 'archived'],
            ['value' => 'all', 'label' => "All ({$allCategoryCount})", 'checked' => $status === 'all'],
        ],
    ]],
]) ?>
<?php
$categoryFooter = ($totalRows ?? 0) >= 0 ? view('components/table_footer', [
    'fromRecord' => $fromRecord,
    'toRecord' => $toRecord,
    'totalRows' => $totalRows,
    'page' => $page,
    'totalPages' => $totalPages,
    'pageUrl' => $categoryPageUrl,
]) : null;
?>

<section class="card batch-card sector-management" data-category-management-root>
    <div class="card-body">
        <h2 class="batch-pane-title">Categories</h2>
        <?= view('Lookups/categories-body', get_defined_vars()) ?>
        <?php if ($categoryFooter !== null): ?>
            <div class="mt-3 small text-muted">
                <?= $categoryFooter ?>
            </div>
        <?php endif; ?>
    </div>
</section>


<?= view('Lookups/category-modal', [
	'existingCodes' => $existingCodes,
]) ?>
