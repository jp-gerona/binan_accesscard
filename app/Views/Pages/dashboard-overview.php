<?php
/**
 * The dashboard's Overview pane: the program end to end, from profiling
 * through to distribution. Never scoped to a batch.
 *
 * Figures come from DashboardModel::programStats() and the per-batch outcome
 * rows from DashboardPageBuilder::buildDistributionRows(), both handed over by
 * DashboardPageBuilder::buildViewData(). The schedule card at the bottom is
 * Admin/dashboard-schedule-card.php, fed by the same builder's
 * buildUpcomingSchedule() and buildScheduleGrid().
 *
 * The tiles carry no icon and no card header block. That is a deliberate
 * exception to the SB Admin card convention, scoped to KPI tiles: an icon
 * beside a number is decoration, and four of them compete with the four
 * numbers.
 */

$overviewStats = $overviewStats ?? ['families' => 0, 'cardsIssued' => 0, 'distributions' => 0, 'everServed' => 0, 'neverServed' => 0];
$distributionRows = $distributionRows ?? [];

$neverServed = (int) ($overviewStats['neverServed'] ?? 0);

// Read left to right the row is the program's funnel: every family profiled,
// how many of them hold a card, how many have collected at least once, and the
// number of distributions those collections came out of. Never-served is
// families minus ever-served, so it rides under the card it is the remainder of
// rather than taking a fourth tile to restate a number already on the row.
$cards = [
    ['label' => 'Families profiled', 'value' => (int) $overviewStats['families'], 'sub' => null],
    // Cards generated, not control numbers assigned: profiling a family reserves
    // its number, printing the card is what issues it.
    ['label' => 'Access cards issued', 'value' => (int) ($overviewStats['cardsIssued'] ?? 0), 'sub' => null],
    [
        'label' => 'Families ever served',
        'value' => (int) $overviewStats['everServed'],
        'sub'   => number_format($neverServed) . ' never served',
    ],
    ['label' => 'Distributions hosted', 'value' => (int) $overviewStats['distributions'], 'sub' => null],
];
?>
<header class="d-flex justify-content-end mb-4">
  <a class="btn btn-primary reports-download-btn" href="<?= site_url('distribution/reports/pdf') ?>">
    <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
    <span>Download Report</span>
  </a>
</header>

<div class="row row-cols-2 row-cols-md-4 g-3 kpi-row">
  <?php foreach ($cards as $card): ?>
  <div class="col">
    <div class="card kpi-card h-100">
      <div class="card-body">
        <p class="kpi-label"><?= esc($card['label']) ?></p>
        <p class="kpi-value"><?= esc(number_format($card['value'])) ?></p>
        <?php if ($card['sub'] !== null): ?>
        <p class="kpi-sub"><?= esc($card['sub']) ?></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-4 mt-1">
  <div class="col-lg-8">
    <section class="card batch-card h-100">
      <div class="card-body">
        <h2 class="dashboard-zone-title mb-3">Distributions</h2>
        <div class="table-responsive">
          <table class="table manage-record-table align-middle w-100 mb-0">
            <thead>
              <tr><th>Batch</th><th>Status</th><th>Subsidy</th><th>Opened</th><th>Eligible</th><th>Served</th><th>Coverage</th></tr>
            </thead>
            <tbody>
              <?php foreach ($distributionRows as $row): ?>
              <tr>
                <td>
                  <a href="<?= site_url('dashboard') ?>?view=distribution&batch=<?= esc((string) (int) $row['batch_id'], 'attr') ?>">
                    <?= esc((string) $row['name']) ?>
                  </a>
                </td>
                <td>
                  <?php if (($row['closed_at'] ?? null) !== null): ?>
                    <span class="badge bg-secondary">Closed</span>
                  <?php elseif (($row['started_at'] ?? null) === null): ?>
                    <span class="badge bg-info text-dark">Scheduled</span>
                  <?php else: ?>
                    <span class="badge bg-success">Open</span>
                  <?php endif; ?>
                </td>
                <td><?= esc((string) ($row['subsidy_type_name'] ?? '')) ?></td>
                <td><?= ($row['started_at'] ?? null) === null ? 'Not yet started' : esc((string) $row['started_at']) ?></td>
                <td><?= esc(number_format((int) $row['eligible'])) ?></td>
                <td><?= esc(number_format((int) $row['served'])) ?></td>
                <td><?= esc((string) (int) $row['coverage']) ?>%</td>
              </tr>
              <?php endforeach; ?>
              <?php if ($distributionRows === []): ?>
              <tr><td colspan="7" class="text-muted">No distribution has been run yet. Open one from the Distribution page.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
  
  <div class="col-lg-4">
    <?php /* dashboard-schedule-card is already a .card container but we omit its native title since we want a zone title */ ?>
    <?= view('Admin/dashboard-schedule-card', [
        'upcomingSchedule' => $upcomingSchedule ?? [],
        'scheduleGrid'     => $scheduleGrid ?? ['weeks' => [], 'bars' => []],
    ]) ?>
  </div>
</div>
