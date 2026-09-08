<?php
/**
 * Audit Trails page (Admin > Audit Trails).
 *
 * Rendered inside layout.php. Rows and their pagination bundle come from
 * DashboardPageBuilder::buildAuditListData(); the counts describe the whole filtered
 * set, not the rows on screen. Paging and filtering are server side, so every control
 * here is a link or a form rather than a client-side table filter.
 */

$recentAudits       = $recentAudits ?? [];
$searchTerm         = $searchTerm ?? '';
$searchFilters      = $searchFilters ?? [];
$auditActionOptions = $auditActionOptions ?? [];
$auditListData      = $auditListData ?? [];
$hasSearchFilters   = $searchTerm !== '' || array_filter($searchFilters, static fn ($value): bool => trim((string) $value) !== '') !== [];

// Pagination + page-size bundle (from DashboardPageBuilder::buildAuditListData).
$listRoute      = (string) ($auditListData['listRoute'] ?? 'audit-trails');
$auditAction    = trim((string) ($searchFilters['action'] ?? ''));
$perPage        = (int) ($auditListData['perPage'] ?? 25);
$perPageOptions = ($auditListData['perPageOptions'] ?? []) ?: [10, 25, 50, 100];
$page           = (int) ($auditListData['page'] ?? 1);
$totalPages     = (int) ($auditListData['totalPages'] ?? 1);
$totalRows      = (int) ($auditListData['totalRows'] ?? count($recentAudits));
$fromRecord     = (int) ($auditListData['fromRecord'] ?? 0);
$toRecord       = (int) ($auditListData['toRecord'] ?? 0);

// Page URL preserving the database keyword + action filter + page size.
$auditPageUrl = static function (int $targetPage) use ($listRoute, $searchTerm, $auditAction, $perPage): string {
    $params = array_filter([
        'q'        => $searchTerm,
        'action'   => $auditAction,
        'per_page' => $perPage !== 25 ? (string) $perPage : '',
        'page'     => $targetPage > 1 ? (string) $targetPage : '',
    ], static fn ($value): bool => $value !== '');

    return site_url($listRoute) . ($params === [] ? '' : '?' . http_build_query($params));
};

// "Clear" resets the whole toolbar (keyword + action filter, back to page 1)
// per the one-role-per-control rule; only the page size survives.
$auditClearUrl = static function () use ($listRoute, $perPage): string {
    $params = $perPage !== 25 ? ['per_page' => (string) $perPage] : [];

    return site_url($listRoute) . ($params === [] ? '' : '?' . http_build_query($params));
};

$formatAuditMember = static function (array $audit): string {
    $memberName = trim((string) ($audit['member_name'] ?? ''));

    if ($memberName === '') {
        $memberName = trim((string) ($audit['firstname'] ?? '') . ' ' . (string) ($audit['lastname'] ?? ''));
    }

    return $memberName === '' ? '-' : $memberName;
};

?>

<?php /* Toolbar above the card, Manage Records standard (components/records_toolbar_server +
         records-filter-panel.js live-apply + pills). Bar 2 inside the card =
         page-size + client-side local "Search:" filter via data-lookup-search (lookup-search.js,
         scoped by data-audit-management-root). */ ?>
<?php
$auditActionRadios = [['value' => '', 'label' => 'All actions', 'checked' => $auditAction === '', 'default' => true]];
foreach ($auditActionOptions as $action) {
    $action = trim((string) $action);
    $auditActionRadios[] = ['value' => $action, 'label' => $action, 'pill' => $action, 'checked' => $auditAction === $action];
}
?>
<?= view('components/toolbar', [
    'formAction' => site_url($listRoute),
    'formAria' => 'Search all audit logs',
    'searchPlaceholder' => 'Search all audit logs...',
    'keyword' => $searchTerm,
    'clearUrl' => $auditClearUrl(),
    'pillsId' => 'auditFilterPills',
    'narrow' => true,
    'hiddenHtml' => $perPage !== 25 ? '<input type="hidden" name="per_page" value="' . esc((string) $perPage, 'attr') . '">' : '',
    'filterGroups' => [[
        'name' => 'action',
        'label' => 'Action',
        'scroll' => true,
        'options' => $auditActionRadios,
    ]],
]) ?>
<?php
$auditFooter = $totalRows >= 0 ? view('components/table_footer', [
    'fromRecord' => $fromRecord,
    'toRecord' => $toRecord,
    'totalRows' => $totalRows,
    'page' => $page,
    'totalPages' => $totalPages,
    'pageUrl' => $auditPageUrl,
]) : null;
?>

<section class="card batch-card audit-trails" aria-label="Audit trails" data-audit-management-root>
    <div class="card-body">
        <h2 class="batch-pane-title">Audit Trails</h2>
        <?= view('Admin/audit-trails-body', [
            'listRoute' => $listRoute,
            'searchTerm' => $searchTerm,
            'auditAction' => $auditAction,
            'auditActionOptions' => $auditActionOptions,
            'perPage' => $perPage,
            'perPageOptions' => $perPageOptions,
            'recentAudits' => $recentAudits,
            'hasSearchFilters' => $hasSearchFilters,
            'auditClearUrl' => $auditClearUrl,
        ]) ?>
        <?php if ($auditFooter !== null): ?>
            <div class="mt-3 small text-muted">
                <?= $auditFooter ?>
            </div>
        <?php endif; ?>
    </div>
</section>

