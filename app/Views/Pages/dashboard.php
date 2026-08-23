<?php
/**
 * Dashboard body (every role), the page all staff land on after login.
 *
 * Two panes share this page, now separated into two sidebar links (Overview and Batch Progress) picked by ?view=. Overview covers the program end to end and never moves with the
 * batch selector; Distribution covers one batch. Neither is role-gated: the
 * dashboard is the landing page for all staff, so a role that saw nothing here
 * would land on an empty page. DashboardPageBuilder still assembles only the
 * pane being shown, which is a cost decision, not an access one.
 *
 * The activity panel renders only for an Encoder, who has no Audit Trails page
 * of their own.
 *
 * Data comes from DashboardPageBuilder::buildViewData().
 */

$myAudits = $myAudits ?? [];
$dashboardView = ($dashboardView ?? 'overview') === 'distribution' ? 'distribution' : 'overview';
$selectedBatchId = (int) ($selectedBatchId ?? 0);
?>
<div class="dashboard-overview" data-dashboard-overview>
        <?php if ($dashboardView === 'distribution'): ?>
        <?= view('Admin/batch-overview') ?>
    <?php else: ?>
        <?= view('Pages/dashboard-overview') ?>
    <?php endif; ?>

    <?php if (($role ?? '') === 'Encoder'): ?>
        <?= view('components/card', [
            'icon' => 'clock-history',
            'title' => 'My Recent Activity',
            'cardClass' => 'dashboard-table-panel',
            'bodyView' => 'Pages/dashboard-activity-body',
            'bodyData' => [
                'myAudits' => $myAudits,
                'formatAuditMember' => $formatAuditMember ?? null,
            ],
            'footer' => null,
        ]) ?>
    <?php endif; ?>
</div>
