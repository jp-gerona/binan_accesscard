<?php
/**
 * Manage Records list (Admin, Employee and Viewer > Manage Records).
 *
 * One view serves every role: the read-only flags decide which controls render,
 * so there is no per-role copy of this page. The rows themselves are not rendered here; the table is loaded over AJAX from
 * the dataTable endpoint, and row actions are gated again server side in
 * FamilyController::dataTableActions(). This page is the layout source of truth the
 * other list pages are matched against.
 */

$keyword = trim((string) ($keyword ?? ''));
$status = in_array((string) ($status ?? 'all'), ['all', 'active', 'archived'], true) ? (string) $status : 'all';
$filters = (array) ($filters ?? []);
$sectorOptions = (array) ($sectorOptions ?? []);
$barangayOptions = (array) ($barangayOptions ?? []);
// Add is hidden for read-only roles (Viewer). Defaults true so admin/employee
// record lists are unaffected. DataTable row actions are gated server-side in
// FamilyController::dataTableActions().
$canEdit = (bool) ($canEdit ?? true);
$selectedSectorIds = array_map('strval', (array) ($filters['sectorID'] ?? []));
$selectedBarangays = array_map('strval', (array) ($filters['barangay'] ?? []));
$sectorOptionLabel = static function (array $sector): string {
    $shortcode = trim((string) ($sector['shortcode'] ?? ''));
    $name = trim((string) ($sector['sector_name'] ?? $sector['name'] ?? $sector['label'] ?? ''));

    if ($shortcode !== '' && $name !== '') {
        return mb_strtoupper($shortcode) . ' - ' . $name;
    }

    return $shortcode !== '' ? mb_strtoupper($shortcode) : $name;
};
?>

<?php
$sectorOptionsGroup = [
    'name' => 'sectorID',
    'label' => 'Sector',
    'type' => 'checkbox',
    'scroll' => true,
    'options' => [],
];
foreach ($sectorOptions as $sector) {
    $sectorId = (string) ($sector['sectorID'] ?? $sector['id'] ?? '');
    $sectorName = $sectorOptionLabel((array) $sector);
    if ($sectorId !== '' && $sectorName !== '') {
        $sectorOptionsGroup['options'][] = [
            'value' => $sectorId,
            'label' => $sectorName,
            'pill' => $sectorName,
            'checked' => in_array($sectorId, $selectedSectorIds, true),
        ];
    }
}

$barangayOptionsGroup = [
    'name' => 'barangay',
    'label' => 'Barangay',
    'type' => 'checkbox',
    'scroll' => true,
    'options' => [],
];
foreach ($barangayOptions as $barangay) {
    $barangayName = trim((string) $barangay);
    if ($barangayName !== '') {
        $barangayOptionsGroup['options'][] = [
            'value' => $barangayName,
            'label' => $barangayName,
            'pill' => $barangayName,
            'checked' => in_array($barangayName, $selectedBarangays, true),
        ];
    }
}

$statusGroup = [
    'name' => 'status',
    'label' => 'Status',
    'type' => 'radio',
    'options' => [
        ['value' => 'all', 'label' => 'All', 'checked' => $status === 'all', 'default' => true],
        ['value' => 'active', 'label' => 'Active', 'pill' => 'Active', 'checked' => $status === 'active'],
        ['value' => 'archived', 'label' => 'Archived', 'pill' => 'Archived', 'checked' => $status === 'archived'],
    ],
];

$actionsHtml = '';
if ($canEdit) {
    $actionsHtml .= '<a class="' . btn('add') . ' flex-fill" href="' . esc(site_url('records/entry')) . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add Family</a>';
    $actionsHtml .= '<a class="' . btn('import') . ' text-dark flex-fill" href="' . esc(site_url('records/import')) . '" title="Bulk-import families from an Excel file"><i class="bi bi-file-earmark-arrow-up me-1" aria-hidden="true"></i>Import Excel</a>';
}
?>

<?= view('components/toolbar', [
    'formId' => 'familyDataTableFilters',
    'disableGenericFilterJs' => true,
    'isClient' => true,
    'formAria' => 'Family records search and filters',
    'searchPlaceholder' => 'Search all family records...',
    'keyword' => $keyword,
    'searchAttrs' => 'data-records-database-keyword',
    'pillsId' => 'familyFilterPills',
    'narrow' => true,
    'actionsHtml' => $actionsHtml,
    'filterGroups' => [$sectorOptionsGroup, $barangayOptionsGroup, $statusGroup],
]) ?>

<section class="card batch-card">
    <div class="card-body">
        <h2 class="batch-pane-title">Family Records</h2>
        <?= view('Family/list-body') ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 w-100 mt-3">
            <div id="familyFooterLeft" class="small text-muted"></div>
            <div id="familyFooterRight"></div>
        </div>
    </div>
</section>
