<?php
/**
 * Data Completeness body (Profiling > Data Completeness).
 *
 * Rendered inside layout.php as the `records-completeness` page body. Data
 * comes from DashboardPageBuilder::buildCompletenessViewData(): the tiles
 * describe the whole queue, the table honours ?barangay= and ?field=, and the
 * download link carries the same filters.
 */

$tiles          = (array) ($tiles ?? []);
$families       = (array) ($families ?? []);
$allFamilies    = (array) ($allFamilies ?? []);
$filterBarangay = trim((string) ($filterBarangay ?? ''));
$filterField    = trim((string) ($filterField ?? ''));
$page           = max(1, (int) ($page ?? 1));
$perPage        = max(1, (int) ($perPage ?? 25));
$pageCount      = max(1, (int) ($pageCount ?? 1));

// Mirrors DashboardPageBuilder::COMPLETENESS_LABELS plus the two head-only
// labels, in display order. Kept in the view because the builder returns the
// already-shaped families, not its label constants.
$completenessLabels = [
    'Birthday', 'Sex', 'Civil Status', 'Education', 'Job', 'Monthly Income',
    'Address', 'Barangay',
];

$totalFamilies = count($allFamilies);
$fromRecord    = $totalFamilies === 0 ? 0 : (($page - 1) * $perPage) + 1;
$toRecord      = min($totalFamilies, $page * $perPage);

$downloadQuery = http_build_query(array_filter([
    'field'    => $filterField,
    'barangay' => $filterBarangay,
], static fn ($value): bool => $value !== ''));
$downloadUrl = site_url('records/completeness/download') . ($downloadQuery === '' ? '' : '?' . $downloadQuery);

$pageUrl = static function (int $targetPage) use ($filterField, $filterBarangay): string {
    $params = array_filter([
        'field'    => $filterField,
        'barangay' => $filterBarangay,
        'page'     => $targetPage > 1 ? (string) $targetPage : '',
    ], static fn ($value): bool => $value !== '');

    return site_url('records/completeness') . ($params === [] ? '' : '?' . http_build_query($params));
};

$tileCards = [
    ['label' => 'Families with gaps', 'value' => (int) ($tiles['families'] ?? 0)],
    ['label' => 'Families with head gaps', 'value' => (int) ($tiles['headGaps'] ?? 0)],
];
foreach (($tiles['byField'] ?? []) as $label => $count) {
    $tileCards[] = ['label' => (string) $label, 'value' => (int) $count];
}
?>
<div class="row row-cols-2 row-cols-md-4 g-3 kpi-row mb-4">
  <?php foreach ($tileCards as $tile): ?>
  <div class="col">
    <div class="card kpi-card h-100">
      <div class="card-body">
        <p class="kpi-label"><?= esc($tile['label']) ?></p>
        <p class="kpi-value"><?= esc(number_format($tile['value'])) ?></p>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<form class="row g-2 align-items-end mb-3" method="get" action="<?= esc(site_url('records/completeness'), 'attr') ?>" role="search" aria-label="Data Completeness filters">
  <div class="col-12 col-md-4">
    <label class="form-label small text-muted fw-semibold mb-1" for="completenessBarangay">Barangay</label>
    <input type="text" class="form-control" id="completenessBarangay" name="barangay" value="<?= esc($filterBarangay, 'attr') ?>" placeholder="Barangay">
  </div>
  <div class="col-12 col-md-4">
    <label class="form-label small text-muted fw-semibold mb-1" for="completenessField">Field</label>
    <select class="form-select" id="completenessField" name="field">
      <option value="">All fields</option>
      <?php foreach ($completenessLabels as $label): ?>
      <option value="<?= esc($label, 'attr') ?>" <?= ($filterField === $label) ? 'selected' : '' ?>><?= esc($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-12 col-md-auto">
    <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
  </div>
</form>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-clipboard-data me-1" aria-hidden="true"></i>Data Completeness</span>
    <a class="btn btn-sm btn-outline-primary" href="<?= esc($downloadUrl, 'attr') ?>">
      <i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>Download
    </a>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table manage-record-table align-middle w-100 mb-0" id="completenessTable">
        <thead class="table-light">
        <tr>
          <th class="fw-semibold small text-center">QR</th>
          <th class="fw-semibold small">HEAD</th>
          <th class="fw-semibold small">BARANGAY</th>
          <th class="fw-semibold small">HEAD GAPS</th>
          <th class="fw-semibold small">MEMBERS WITH GAPS</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($families as $family): ?>
          <tr>
            <td class="text-center text-nowrap"><?= esc(($family['qr'] ?? null) !== null ? (string) $family['qr'] : '-') ?></td>
            <td><?= esc((string) ($family['head'] ?? '-')) ?></td>
            <td><?= esc((string) ($family['barangay'] ?? '')) ?></td>
            <td>
              <?php if (($family['headGaps'] ?? []) === []): ?>
                <span class="text-muted">None</span>
              <?php else: ?>
                <?php foreach ($family['headGaps'] as $gap): ?>
                  <span class="badge rounded-pill text-bg-danger"><?= esc((string) $gap) ?></span>
                <?php endforeach; ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if (($family['members'] ?? []) === []): ?>
                <span class="text-muted">None</span>
              <?php else: ?>
                <?php foreach ($family['members'] as $member): ?>
                  <div class="mb-1">
                    <strong><?= esc((string) ($member['name'] ?? '-')) ?></strong>
                    <small class="text-muted d-block"><?= esc((string) ($member['relationship'] ?? 'MEMBER')) ?></small>
                    <?php foreach (($member['gaps'] ?? []) as $gap): ?>
                      <span class="badge rounded-pill text-bg-warning text-dark"><?= esc((string) $gap) ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($families === []): ?>
          <tr><td colspan="5" class="text-muted">No families with missing profile data.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($totalFamilies > 0): ?>
  <div class="card-footer small text-muted">
    <?= view('components/table_footer', [
        'fromRecord'  => $fromRecord,
        'toRecord'    => $toRecord,
        'totalRows'   => $totalFamilies,
        'page'        => $page,
        'totalPages'  => $pageCount,
        'pageUrl'     => $pageUrl,
        'entityLabel' => 'families',
    ]) ?>
  </div>
  <?php endif; ?>
</div>
