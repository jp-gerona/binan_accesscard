<?php
/**
 * Card Readiness body (Profiling > Card Readiness).
 *
 * Active family heads appear once when a card-required value is absent. The
 * builder owns filtering and pagination, while this records view only renders
 * its prepared rows and URLs.
 */

$families        = (array) ($families ?? []);
$allFamilies     = (array) ($allFamilies ?? []);
$filterBarangay  = trim((string) ($filterBarangay ?? ''));
$filterField     = trim((string) ($filterField ?? ''));
$keyword         = trim((string) ($keyword ?? ''));
$barangayOptions = (array) ($barangayOptions ?? []);
$page            = max(1, (int) ($page ?? 1));
$perPage         = max(1, (int) ($perPage ?? 25));
$pageCount       = max(1, (int) ($pageCount ?? 1));

$readinessLabels = ['Control Number', 'First Name', 'Last Name', 'Sex', 'Birthday', 'Address', 'Contact Number', 'Barangay'];
$totalFamilies = count($allFamilies);
$fromRecord = $totalFamilies === 0 ? 0 : (($page - 1) * $perPage) + 1;
$toRecord = min($totalFamilies, $page * $perPage);

$query = static function (array $params): string {
    $query = http_build_query(array_filter($params, static fn ($value): bool => $value !== ''));

    return $query === '' ? '' : '?' . $query;
};
$downloadUrl = site_url('records/completeness/download') . $query([
    'q' => $keyword, 'field' => $filterField, 'barangay' => $filterBarangay,
]);
$pageUrl = static function (int $targetPage) use ($keyword, $filterField, $filterBarangay, $query): string {
    return site_url('records/completeness') . $query([
        'q' => $keyword,
        'field' => $filterField,
        'barangay' => $filterBarangay,
        'page' => $targetPage > 1 ? (string) $targetPage : '',
    ]);
};

$fieldOptions = [['value' => '', 'label' => 'All missing fields', 'default' => true, 'checked' => $filterField === '']];
foreach ($readinessLabels as $label) {
    $fieldOptions[] = ['value' => $label, 'label' => $label, 'pill' => $label, 'checked' => $filterField === $label];
}
$barangayFilterOptions = [['value' => '', 'label' => 'All barangays', 'default' => true, 'checked' => $filterBarangay === '']];
foreach ($barangayOptions as $barangay) {
    $barangay = trim((string) $barangay);
    if ($barangay !== '') {
        $barangayFilterOptions[] = ['value' => $barangay, 'label' => $barangay, 'pill' => $barangay, 'checked' => $filterBarangay === $barangay];
    }
}
$exportAction = '<a class="' . btn('generate') . ' flex-fill" href="' . esc($downloadUrl, 'attr') . '"><i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>Export</a>';
?>
<?= view('components/toolbar', [
    'formAction' => site_url('records/completeness'),
    'formAria' => 'Card Readiness search and filters',
    'searchPlaceholder' => 'Search all card readiness records...',
    'keyword' => $keyword,
    'pillsId' => 'cardReadinessFilterPills',
    'narrow' => true,
    'actionsHtml' => $exportAction,
    'filterGroups' => [
        ['name' => 'barangay', 'label' => 'Barangay', 'type' => 'radio', 'scroll' => true, 'options' => $barangayFilterOptions],
        ['name' => 'field', 'label' => 'Missing card field', 'type' => 'radio', 'scroll' => true, 'options' => $fieldOptions],
    ],
]) ?>

<section class="card batch-card card-readiness-records">
    <div class="card-body">
        <h2 class="batch-pane-title">Card Readiness</h2>
        <?= view('Family/completeness-table', compact('families', 'filterBarangay', 'filterField', 'keyword', 'perPage')) ?>
        <div class="mt-3 small text-muted">
            <?= view('components/table_footer', [
                'fromRecord' => $fromRecord,
                'toRecord' => $toRecord,
                'totalRows' => $totalFamilies,
                'page' => $page,
                'totalPages' => $pageCount,
                'pageUrl' => $pageUrl,
                'entityLabel' => 'heads',
            ]) ?>
        </div>
    </div>
</section>
