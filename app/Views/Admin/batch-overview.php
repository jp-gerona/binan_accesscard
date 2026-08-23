<?php
/**
 * Dashboard Zone 2, "this batch": the batch picker, the four headline figures,
 * and the cards underneath them. A fragment, not a page: rendered inline by
 * Pages/dashboard.php for every role. Data comes from
 * DashboardPageBuilder::buildReportsData() (batches, batchRow, batchOpen,
 * batchSnapshot, selectedDay, weekdayHeatmap, remainingPage) plus $batchHeadline
 * and $role, the last of which decides only whether a station row is clickable.
 * All server data is escaped.
 *
 * Activity, Barangay coverage, Stations and Remaining used to be one block with
 * a page-level tab strip switching between them. They are four separate
 * subjects, not four views of one, so reading two of them meant clicking
 * between them and losing the first. Each is a card of its own now and they all
 * render together. A tab strip survives only inside a card, and only where it
 * switches between views of that card's own data.
 *
 * The batch figures render as a KPI card row plus a slim coverage bar,
 * matching the Overview pane's card row (`.kpi-card` styles are shared, see
 * theme.css).
 */

$batches = $batches ?? [];
$batchRow = $batchRow ?? null;
$batchOpen = (bool) ($batchOpen ?? false);
$batchSnapshot = $batchSnapshot ?? ['coverage' => ['eligible' => 0, 'served' => 0, 'remaining' => 0, 'coverage' => 0, 'voided' => 0], 'byBarangay' => [], 'timeline' => [], 'byDay' => [], 'heatmap' => ['days' => [], 'hours' => [], 'cells' => [], 'max' => 0], 'byScanner' => [], 'byScannerByDay' => [], 'days' => []];
$remainingPage = $remainingPage ?? ['rows' => [], 'keyword' => '', 'page' => 1, 'perPage' => 25, 'perPageOptions' => [10, 25, 50, 100], 'totalPages' => 1, 'totalRows' => 0, 'fromRecord' => 0, 'toRecord' => 0];
$weekdayHeatmap = $weekdayHeatmap ?? ['days' => [], 'hours' => [], 'cells' => [], 'max' => 0];
$selectedDay = $selectedDay ?? null;
$batchHeadline = $batchHeadline ?? [
    'eligible'       => ['value' => '0', 'sub' => ''],
    'served'         => ['value' => '0', 'sub' => ''],
    'peakHour'       => ['value' => '-', 'sub' => ''],
    'scannersActive' => ['value' => '0', 'sub' => ''],
];

// Only these two reach scanner/stats, which is what the station modal reads.
// See the note in Admin/batch-stations-table.php.
$canDrillInStations = in_array($role ?? '', ['Developer', 'Admin'], true);

$c = $batchSnapshot['coverage'];
$byBarangay = $batchSnapshot['byBarangay'];
$timeline = $batchSnapshot['timeline'];
$byDay = $batchSnapshot['byDay'] ?? [];
$heatmap = $batchSnapshot['heatmap'] ?? ['days' => [], 'hours' => [], 'cells' => [], 'max' => 0];
$byScanner = $batchSnapshot['byScanner'] ?? [];
$byScannerByDay = $batchSnapshot['byScannerByDay'] ?? [];

$batchId = (int) ($batchRow['batch_id'] ?? 0);
$noBatch = $batchRow === null;
$noEligible = ! $noBatch && $c['eligible'] === 0;
?>

<header class="d-flex justify-content-between align-items-center mb-4">
  <div class="d-flex gap-2 align-items-center">
    <?php if ($batches !== []): ?>
    <form class="reports-filter m-0" method="get" action="<?= site_url('dashboard') ?>">
      <input type="hidden" name="view" value="distribution">
      <label for="batchPick" class="form-label mb-0 visually-hidden">Batch</label>
      <select class="form-select w-auto" id="batchPick" name="batch" onchange="this.form.submit()">
        <?php foreach ($batches as $b): ?>
          <option value="<?= esc($b['batch_id'], 'attr') ?>" <?= $batchId === (int) $b['batch_id'] ? 'selected' : '' ?>>
            <?= esc($b['name']) ?><?= $b['closed_at'] === null ? ' (open)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
    
    <?php if (! $noBatch && ! $noEligible): ?>
    <label for="dayPick" class="form-label mb-0 visually-hidden">Day</label>
    <select class="form-select w-auto" id="dayPick">
      <option value=""<?= $selectedDay === null ? ' selected' : '' ?>>All days</option>
      <?php foreach ($byDay as $index => $day): ?>
      <option value="<?= esc($day['date'], 'attr') ?>"<?= $selectedDay === $day['date'] ? ' selected' : '' ?>>
        <?= esc(date('M j', strtotime($day['date'])) . ' (Day ' . ($index + 1) . ')') ?>
      </option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </div>
  
  <div>
    <?php if (! $noBatch): ?>
    <a class="btn btn-primary reports-download-btn" href="<?= site_url('distribution/reports/pdf') . '?batch=' . (int) $batchId ?>"><i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i><span>Download Report</span></a>
    <?php endif; ?>
  </div>
</header>

<?php if ($noBatch): ?>
<p class="text-muted mb-4">No distribution batch exists yet. Open one from the Distribution page to see its coverage here.</p>
<?php elseif ($noEligible): ?>
<p class="text-muted mb-4">This batch has no eligible families. Check its barangay and sector filters.</p>
<?php else: ?>

<?php /* Every value carries data-metric and every sub-line data-metric-sub, so
         the day filter and the live poll rewrite the same four figures through
         one contract rather than a list of ids. The sub-line is always in the
         markup, even when it has nothing to say, because both of those repaint
         it in place and neither should be inserting markup mid-batch. */ ?>
<div class="row row-cols-2 row-cols-md-4 g-3 kpi-row">
  <div class="col">
    <div class="card kpi-card h-100"><div class="card-body">
      <p class="kpi-label">Eligible</p>
      <p class="kpi-value" id="progressEligible" data-metric="eligible"><?= esc($batchHeadline['eligible']['value']) ?></p>
      <p class="kpi-sub" data-metric-sub="eligible"><?= esc($batchHeadline['eligible']['sub']) ?></p>
    </div></div>
  </div>
  <div class="col">
    <div class="card kpi-card h-100"><div class="card-body">
      <p class="kpi-label">Served</p>
      <p class="kpi-value" id="progressServed" data-metric="served"><?= esc($batchHeadline['served']['value']) ?></p>
      <p class="kpi-sub" data-metric-sub="served"><?= esc($batchHeadline['served']['sub']) ?></p>
    </div></div>
  </div>
  <div class="col">
    <div class="card kpi-card h-100"><div class="card-body">
      <p class="kpi-label">Peak hour</p>
      <p class="kpi-value" data-metric="peakHour"><?= esc($batchHeadline['peakHour']['value']) ?></p>
      <p class="kpi-sub" data-metric-sub="peakHour"><?= esc($batchHeadline['peakHour']['sub']) ?></p>
    </div></div>
  </div>
  <div class="col">
    <div class="card kpi-card h-100"><div class="card-body">
      <p class="kpi-label">Scanners active</p>
      <p class="kpi-value" data-metric="scannersActive"><?= esc($batchHeadline['scannersActive']['value']) ?></p>
      <p class="kpi-sub" data-metric-sub="scannersActive"><?= esc($batchHeadline['scannersActive']['sub']) ?></p>
    </div></div>
  </div>
</div>

<section class="card batch-card mb-4" id="progressCard">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="batch-pane-title mb-0">Distribution Progress</h2>
      <div>
        <span class="status-pill is-muted"><?= $batchOpen ? 'open' : 'closed' ?></span>
        <?php if ($batchOpen): ?>
          <span class="text-muted small ms-2">updated <span id="lastUpdated">-</span></span>
        <?php endif; ?>
      </div>
    </div>
    
    <div class="d-flex align-items-center mb-2">
      <div class="fs-1 fw-bold text-dark me-2 lh-1"><span id="progressCoverage"><?= esc((string) $c['coverage']) ?></span>%</div>
    </div>
    
    <div class="progress mb-4" id="coverageProgress" role="progressbar"
         aria-label="Coverage"
         aria-valuenow="<?= esc((string) $c['coverage'], 'attr') ?>"
         aria-valuemin="0" aria-valuemax="100" style="height: 12px; border-radius: 6px; background-color: var(--token-gray-200);">
      <div class="progress-bar" id="coverageProgressFill" style="width: <?= esc((string) $c['coverage'], 'attr') ?>%; background-color: var(--token-primary-green);"></div>
    </div>
    
    <div class="d-flex gap-5">
      <div id="remainingTileWrap">
        <div class="text-muted small text-uppercase fw-bold" style="letter-spacing: 0.04em;"><?= $batchOpen ? 'Remaining' : 'Not claimed' ?></div>
        <div class="fs-5 fw-semibold text-dark"><span id="remainingTileValue"><?= esc(number_format($c['remaining'])) ?></span></div>
      </div>
      <div id="voidedTileWrap"<?= $c['voided'] > 0 ? '' : ' class="d-none"' ?>>
        <div class="text-muted small text-uppercase fw-bold" style="letter-spacing: 0.04em;">Voided</div>
        <div class="fs-5 fw-semibold text-dark"><span id="voidedTileValue"><?= esc((string) $c['voided']) ?></span></div>
      </div>
    </div>
  </div>
</section>

<?= view('Admin/batch-activity-card', [
    'heatmap'        => $heatmap,
    'weekdayHeatmap' => $weekdayHeatmap,
    'byDay'          => $byDay,
    'selectedDay'    => $selectedDay,
    'batchOpen'      => $batchOpen,
]) ?>

<?php if ($batchOpen): ?>
<?php /* Live monitoring rather than reporting, which is why it is a card of its
         own and not a fourth view inside Activity: the three Activity views all
         answer "when was it busy", this one answers "is it still moving", and
         it is the only block on the pane that a closed batch has no use for. */ ?>
<section class="card batch-card">
  <div class="card-body">
    <h3 class="batch-pane-title">Families served over time</h3>
    <div class="batch-timeline-chart"><canvas id="chartTimeline"></canvas></div>
  </div>
</section>
<?php endif; ?>

<section class="card batch-card" id="barangayCard" data-strip="table">
  <div class="card-body">
    <h3 class="batch-pane-title">Barangay coverage</h3>
    <?php /* Map and Table are two readings of one set of figures, so they are a
             card strip rather than two blocks side by side. Table leads: it is
             legible at every width and carries the exact numbers, while the map
             carries the pattern. The map used to be hidden below the large
             breakpoint because at 390px the SVG renders 342x520 and four
             barangays (Casile 18x19,
             Poblacion 14x44, Santo Domingo 16x51, San Jose 23x62) fall under a
             24px tap target in at least one dimension, and the popover trigger
             is hover-or-focus while hover does not exist on touch. Behind a
             strip that measurement no longer justifies hiding it: nobody is
             made to scroll past an awkward map to reach the figures, and a
             phone reader who wants the pattern can still ask for it. */ ?>
    <?= view('components/card_tabs', [
        'tabs' => [
            ['key' => 'table', 'label' => 'Table'],
            ['key' => 'map', 'label' => 'Map'],
        ],
        'active' => 'table',
        'stripId' => 'barangay',
    ]) ?>

    <?php /* Pane ids follow components/card_tabs.php's contract,
             "<stripId>-pane-<key>" against its "<stripId>-tab-<key>", so the
             two strips on this page cannot collide on a shared pane key. */ ?>
    <div id="barangay-pane-table" role="tabpanel" aria-labelledby="barangay-tab-table" data-strip-pane="table">
      <div class="table-responsive">
        <table class="table manage-record-table align-middle w-100 mb-0" id="barangayTable">
          <thead><tr><th scope="col">Barangay</th><th scope="col">Eligible</th><th scope="col">Served</th><th scope="col">Coverage</th></tr></thead>
          <tbody>
            <?php foreach ($byBarangay as $b): ?>
            <tr data-barangay="<?= esc($b['barangay'], 'attr') ?>">
              <td><?= esc($b['barangay']) ?></td>
              <td><?= esc(number_format($b['total'])) ?></td>
              <td><?= esc(number_format($b['received'])) ?></td>
              <td><?= esc((string) $b['coverage']) ?>%</td>
            </tr>
            <?php endforeach; ?>
            <?php if ($byBarangay === []): ?>
            <tr><td colspan="4" class="text-muted">No data for this batch.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div id="barangay-pane-map" role="tabpanel" aria-labelledby="barangay-tab-map" data-strip-pane="map" hidden>
      <?= view('Admin/batch-barangay-map', ['byBarangay' => $byBarangay]) ?>
    </div>
  </div>
</section>

<section class="card batch-card" id="stationsCard" data-strip="all">
  <div class="card-body">
    <h3 class="batch-pane-title">Stations</h3>
    <?php /* All is the batch-wide fold every earlier task shipped; Per day
             narrows to one day's fold (SubsidyStatsModel::batchSnapshot()'s
             byScannerByDay), which task 9 wires to the #dayPick control this
             card shares with the Activity card's Hours view. Same stripId
             contract as the Barangay and Activity strips: pane ids are
             "<stripId>-pane-<key>" against "<stripId>-tab-<key>". */ ?>
    <?= view('components/card_tabs', [
        'tabs' => [
            ['key' => 'all', 'label' => 'All'],
            ['key' => 'day', 'label' => 'Per day'],
        ],
        'active' => 'all',
        'stripId' => 'stations',
    ]) ?>

    <div id="stations-pane-all" role="tabpanel" aria-labelledby="stations-tab-all" data-strip-pane="all">
      <?php /* Every parameter passed explicitly, including the defaults this
               card's own extraction already computed: this partial renders a
               second time below for the Per day pane, and CI4's renderer
               carries one view() call's data into the next, so the two would
               otherwise depend on which one runs first. Same fix as the
               Activity card's Hours/Weekdays panes. */ ?>
      <?= view('Admin/batch-stations-table', [
          'byScanner'  => $byScanner,
          'batchId'    => $batchId,
          'canDrillIn' => $canDrillInStations,
          'tableId'    => 'stationsTable',
      ]) ?>
    </div>

    <div id="stations-pane-day" role="tabpanel" aria-labelledby="stations-tab-day" data-strip-pane="day" hidden>
      <?php
      $dayRows = $selectedDay !== null ? ($byScannerByDay[$selectedDay] ?? []) : null;
      ?>
      <?php if ($selectedDay === null): ?>
      <p class="text-muted mb-0" id="stationsDayHint">Use the Day picker above to choose a day.</p>
      <?php elseif ($dayRows === []): ?>
      <p class="text-muted mb-0" id="stationsDayHint">No station logged a scan on <?= esc(date('M j', strtotime($selectedDay))) ?>.</p>
      <?php else: ?>
      <?php /* A second table on the same page cannot reuse stationsTable's id,
               the same reason the Activity card's day/weekday heatmaps do not
               share peakHeatmap/weekdayHeatmap. station-modal.js delegates
               from #stationsCard rather than a fixed table id (see that file),
               so this second table's data-scanner-id rows still open the
               drill-in modal without a matching bind of their own. */ ?>
      <?= view('Admin/batch-stations-table', [
          'byScanner'  => $dayRows,
          'batchId'    => $batchId,
          'canDrillIn' => $canDrillInStations,
          'tableId'    => 'stationsTableDay',
      ]) ?>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php if ($canDrillInStations): ?>
<?= view('Admin/batch-station-modal') ?>
<?php endif; ?>

<section class="card batch-card" id="remainingCard">
  <div class="card-body">
    <h3 class="batch-pane-title">Remaining</h3>
    <?php
    // Server-side paginated (SubsidyStatsModel::remainingPage()): against the
    // 100k-family target, fetching the whole roster into the DOM for
    // table-paginate.js to slice client-side (the Distribution Batches/Log
    // pattern) is too large a page. Preserves the pane and the batch across
    // page links; there is no sub-tab to preserve any more, and the picked day
    // does not narrow this list, so neither rides along.
    $remainingPageUrl = static function (int $targetPage) use ($batchId, $remainingPage): string {
        $params = array_filter([
            'view'     => 'distribution',
            'batch'    => $batchId > 0 ? (string) $batchId : '',
            'q'        => $remainingPage['keyword'],
            'per_page' => $remainingPage['perPage'] !== 25 ? (string) $remainingPage['perPage'] : '',
            'page'     => $targetPage > 1 ? (string) $targetPage : '',
        ], static fn ($value): bool => $value !== '');

        return site_url('dashboard') . ($params === [] ? '' : '?' . http_build_query($params));
    };
    // The pane and the batch selection have to survive the search and per-page
    // forms below; a new search always lands on page 1, so neither form carries
    // one. table_controls escapes these.
    $remainingContext = [
        'view'  => 'distribution',
        'batch' => $batchId > 0 ? (string) $batchId : '',
    ];
    ?>
    <?= view('Admin/batch-remaining', [
        'remaining' => $remainingPage['rows'],
        'keyword' => $remainingPage['keyword'],
        'perPage' => $remainingPage['perPage'],
        'perPageOptions' => $remainingPage['perPageOptions'],
        'searchHidden' => $remainingContext + [
            'per_page' => $remainingPage['perPage'] !== 25 ? (string) $remainingPage['perPage'] : '',
        ],
        'sizeHidden' => $remainingContext + ['q' => $remainingPage['keyword']],
    ]) ?>
    <?= view('components/table_footer', [
        'fromRecord' => $remainingPage['fromRecord'],
        'toRecord' => $remainingPage['toRecord'],
        'totalRows' => $remainingPage['totalRows'],
        'page' => $remainingPage['page'],
        'totalPages' => $remainingPage['totalPages'],
        'pageUrl' => $remainingPageUrl,
        'entityLabel' => 'families',
    ]) ?>
  </div>
</section>

<script id="reportsData" type="application/json"><?= json_encode(
    [
        'coverage'       => $c,
        'barangay'       => $byBarangay,
        'timeline'       => $timeline,
        'byDay'          => $byDay,
        'heatmap'        => $heatmap,
        'byScanner'      => $byScanner,
        'byScannerByDay' => $byScannerByDay,
        'days'           => $batchSnapshot['days'] ?? [],
        'selectedDay'    => $selectedDay,
    ],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
) ?></script>

<?php /* Day selection and the card strips are independent of Chart.js, so this
         loads unconditionally rather than behind the chart.js/scanner-reports.js
         pair: a closed batch (no charts, no live poll) still gets a working day
         filter and working strips. */ ?>
<script src="<?= esc(asset_url('assets/js/dashboard/batch-heatmap.js'), 'attr') ?>"></script>
<script src="<?= esc(asset_url('vendor/chart.js/chart.umd.min.js'), 'attr') ?>"></script>
<script src="<?= esc(asset_url('assets/js/dashboard/scanner-reports.js'), 'attr') ?>"></script>
<?php /* This fragment renders inside <main>, ahead of layout.php's Bootstrap
         bundle script at the end of the body, so a plain <script src> here
         would run before window.bootstrap exists and the Popover constructor
         below would throw. defer holds it until after the document (and the
         blocking bootstrap.bundle.min.js tag within it) has parsed. */ ?>
<script src="<?= esc(asset_url('assets/js/dashboard/barangay-map.js'), 'attr') ?>" defer></script>
<?php if ($canDrillInStations): ?>
<?php /* defer for the same reason as barangay-map.js above: this fragment
         renders ahead of layout.php's Bootstrap bundle, and the Modal
         constructor needs window.bootstrap to exist. Loaded only for the roles
         the modal was rendered for; it used to be gated on the Stations tab
         being the one showing as well, and there is no such tab now. */ ?>
<script src="<?= esc(asset_url('assets/js/dashboard/station-modal.js'), 'attr') ?>" defer></script>
<?php endif; ?>
<script>
(function () {
  // Live poll: fetch fresh stats for the selected batch and repaint the charts
  // in place (no page reload, so the batch selector, the picked day and scroll
  // stay put).
  var statsUrl = '<?= site_url('distribution/reports/stats') ?>';
  var batchId = <?= (int) $batchId ?>;
  var batchOpen = <?= $batchOpen ? 'true' : 'false' ?>;
  // distribution/reports/stats carries no remaining-families rows, so there is
  // nothing to repaint the Remaining card with. That card used to be a tab of
  // its own and the whole poll was skipped while it showed, rather than tick
  // the figures above a list sitting frozen. Every card renders together now,
  // so the poll runs whenever the batch is open and simply leaves Remaining
  // alone; its rows are a page of a database query, not a live figure.
  if (batchId > 0) { statsUrl += '?batch=' + batchId; }

  // A stamp that stops ticking looks the same as a quiet batch, so a failed
  // poll has to say so rather than leave the last good time sitting there. One
  // dropped request on a flaky connection is not worth a warning, so the notice
  // waits for three in a row.
  var failures = 0;

  function stampNow() {
    var stamp = document.getElementById('lastUpdated');
    if (stamp) { stamp.textContent = new Date().toLocaleTimeString(); }
  }

  function apply(d) {
    if (window.ReportsCharts) { window.ReportsCharts.update(d); }
    failures = 0;
    stampNow();
  }

  function failed() {
    failures += 1;
    var stamp = document.getElementById('lastUpdated');
    if (stamp && failures >= 3) { stamp.textContent = 'not connected'; }
  }

  function poll() {
    fetch(statsUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d) { apply(d); } else { failed(); } })
      .catch(failed);
  }

  stampNow();

  // Live-poll only while the selected batch is open (closed batches are static)
  // and the tab is visible; browsers throttle hidden-tab timers anyway.
  if (batchOpen) {
    setInterval(function () {
      if (document.visibilityState === 'visible') { poll(); }
    }, 5000);
  }
})();
</script>
<?php endif; ?>
