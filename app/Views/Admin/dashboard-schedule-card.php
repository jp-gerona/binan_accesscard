<?php
/**
 * Dashboard schedule card: the current month with a labelled, spanning bar
 * for each batch's run of days, today filled in as a badge, and the running
 * or next batches listed beneath with their date and time range.
 *
 * A bar is one column wide at its narrowest and clips its label, so each one
 * carries a Bootstrap tooltip with the whole batch name. The tooltip is
 * containered on body because the bar itself is overflow:hidden.
 *
 * Read only. Plotting happens on the Schedule tab of the distribution page,
 * which the heading links to. The grid is written by hand rather than with
 * FullCalendar because shrinking that into a single dashboard column costs
 * more than drawing a static month. Drawn at one dashboard column's width
 * (see .dash-schedule-card, theme.css), not the page's full width.
 *
 * Data source: DashboardPageBuilder::buildViewData(), keys upcomingSchedule
 * and scheduleGrid (see buildScheduleGrid() for the grid's shape).
 */
$upcomingSchedule = $upcomingSchedule ?? [];
$scheduleGrid     = $scheduleGrid ?? ['weeks' => [], 'bars' => []];
$weeks            = $scheduleGrid['weeks'];
$barsByWeek       = [];
foreach ($scheduleGrid['bars'] as $bar) {
    $barsByWeek[$bar['weekIndex']][] = $bar;
}
?>
<div class="card dash-schedule-card h-100">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="dashboard-zone-title mb-0">Upcoming schedule</h2>
      <a href="<?= esc(site_url('distribution?tab=schedule'), 'attr') ?>" class="small text-decoration-none">Open calendar</a>
    </div>
    <div class="mb-2">
      <span class="fw-semibold"><?= esc(date('F Y')) ?></span>
    </div>
    <div class="dash-schedule-heading text-muted small">
      <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
    </div>
    <?php foreach ($weeks as $weekIndex => $week): ?>
      <?php
        $laneCount = 0;
        foreach ($barsByWeek[$weekIndex] ?? [] as $bar) {
            $laneCount = max($laneCount, $bar['lane'] + 1);
        }
      ?>
      <div class="dash-schedule-week" style="grid-template-rows:1.5rem repeat(<?= max(1, $laneCount) ?>, 15px);">
        <?php foreach ($week as $col => $cell): ?>
          <span class="dash-schedule-day<?= $cell['isToday'] ? ' is-today' : '' ?><?= $cell['isOutside'] ? ' is-outside' : '' ?>" style="grid-column:<?= $col + 1 ?>">
            <?= esc((string) $cell['day']) ?>
          </span>
        <?php endforeach; ?>
        <?php foreach ($barsByWeek[$weekIndex] ?? [] as $bar): ?>
          <span class="dash-schedule-bar<?= $bar['status'] === 'done' ? ' is-done' : '' ?>"
                style="grid-column:<?= $bar['startCol'] + 1 ?> / span <?= $bar['span'] ?>;grid-row:<?= $bar['lane'] + 2 ?>;background:var(--batch-<?= esc($bar['color'], 'attr') ?>)"
                data-bs-toggle="tooltip" data-bs-container="body"
                title="<?= esc($bar['name']) ?>">
            <?= esc($bar['name']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($upcomingSchedule === []): ?>
      <p class="text-muted small mb-0 mt-2">Nothing scheduled. Plot a distribution on the calendar.</p>
    <?php else: ?>
      <?php foreach ($upcomingSchedule as $row): ?>
        <div class="d-flex gap-2 align-items-start border-top pt-2 mt-2 dash-schedule-item">
          <div class="dash-schedule-when">
            <b><?= esc(date('j', strtotime($row['start']))) ?></b>
            <i><?= esc(date('M', strtotime($row['start']))) ?></i>
          </div>
          <div class="small">
            <span class="fw-semibold d-block"><i class="bi bi-calendar-event me-1 text-muted"></i><?= esc($row['name']) ?></span>
            <span class="text-muted d-block"><i class="bi bi-geo-alt me-1"></i><?= esc($row['venue']) ?></span>
            <span class="text-muted d-block">
              <i class="bi bi-clock me-1"></i><?= esc(date('M j', strtotime($row['start']))) ?><?= $row['end'] !== $row['start'] ? esc('–' . date('j', strtotime($row['end']))) : '' ?>
              &middot; <?= esc(date('g:i A', strtotime($row['dailyStart']))) ?>&ndash;<?= esc(date('g:i A', strtotime($row['dailyEnd']))) ?>
            </span>
            <?php if ($row['status'] === 'running'): ?>
              <span class="badge bg-success mt-1">Open</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
