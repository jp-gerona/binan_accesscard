<?php
/**
 * Distribution body (Developer, Admin, Viewer).
 *
 * Rendered inside layout.php as the `distribution` page body. The schedule
 * calendar, the batches list, and the distribution log share one page,
 * switched by ?tab=; data comes from DashboardPageBuilder::buildViewData().
 * Who reaches this page at all is the roleNav filter's decision
 * (Config\Navigation).
 *
 * Each tab renders its own dialogs, so the schedule form and the close
 * confirmation only reach the page that can open them.
 */

$distributionTab = (string) ($distributionTab ?? 'schedule');
$distributionTab = in_array($distributionTab, ['schedule', 'batches', 'log'], true) ? $distributionTab : 'schedule';
?>
<?= view('components/page_tabs', [
    'tabs' => [
        ['key' => 'schedule', 'label' => 'Schedule'],
        ['key' => 'batches', 'label' => 'Batches'],
        ['key' => 'log', 'label' => 'Distribution Log'],
    ],
    'active' => $distributionTab,
    'baseUrl' => 'distribution',
]) ?>

<?php if ($distributionTab === 'schedule'): ?>
    <?= view('Admin/schedule-calendar', ['currentRole' => $currentRole ?? '']) ?>
    <?php if (in_array($currentRole ?? '', ['Admin', 'Developer'], true)): ?>
        <?= view('Admin/schedule-form-modal', [
            'activeSubsidyTypes' => $activeSubsidyTypes ?? [],
            'barangayOptions'    => $barangayOptions ?? [],
            'sectorOptions'      => $batchSectorOptions ?? [],
            'scheduleColors'     => $scheduleColors ?? [],
            'venueSuggestions'   => $venueSuggestions ?? [],
        ]) ?>
    <?php endif; ?>
<?php elseif ($distributionTab === 'batches'): ?>
        <section class="card batch-card" data-table-paginate data-paginate-key="batches" data-paginate-label="batches">
        <div class="card-body">
            <h2 class="batch-pane-title">Distribution Batches</h2>
            <?= view('Admin/distribution-batches-body', [
                'batches' => $batches ?? [],
                'activeBatch' => $activeBatch ?? null,
                'currentRole' => $currentRole ?? '',
            ]) ?>
            <div class="mt-3 small text-muted">
                <?= view('components/table_footer', ['clientKey' => 'batches', 'entityLabel' => 'batches']) ?>
            </div>
        </div>
    </section>
    <?php if (in_array($currentRole ?? '', ['Admin', 'Developer'], true)): ?>
        <?= view('Admin/batch-close-modal') ?>
    <?php endif; ?>
<?php else: ?>
    <?php
    // Server-paged bundle from DashboardPageBuilder::buildDistributionListData().
    $distributionListData = $distributionListData ?? [];
    $distKeyword    = (string) ($distributionListData['keyword'] ?? '');
    $distPerPage    = (int) ($distributionListData['perPage'] ?? 25);
    $distPerPageOptions = ($distributionListData['perPageOptions'] ?? []) ?: [10, 25, 50, 100];
    $distPage       = (int) ($distributionListData['page'] ?? 1);
    $distTotalPages = (int) ($distributionListData['totalPages'] ?? 1);
    $distTotalRows  = (int) ($distributionListData['totalRows'] ?? 0);
    $distFrom       = (int) ($distributionListData['fromRecord'] ?? 0);
    $distTo         = (int) ($distributionListData['toRecord'] ?? 0);

    // Page URL preserving the keyword and the page size. tab=log is part of the
    // route rather than a param, so it rides in the base URL.
    $distPageUrl = static function (int $targetPage) use ($distKeyword, $distPerPage): string {
        $params = array_filter([
            'tab'      => 'log',
            'q'        => $distKeyword,
            'per_page' => $distPerPage !== 25 ? (string) $distPerPage : '',
            'page'     => $targetPage > 1 ? (string) $targetPage : '',
        ], static fn ($value): bool => $value !== '');

        return site_url('distribution') . '?' . http_build_query($params);
    };

    $distClearUrl = static function () use ($distPerPage): string {
        $params = ['tab' => 'log'] + ($distPerPage !== 25 ? ['per_page' => (string) $distPerPage] : []);

        return site_url('distribution') . '?' . http_build_query($params);
    };
    ?>
    <?= view('components/toolbar', [
        'formAction' => site_url('distribution'),
        'formAria' => 'Search all distributions',
        'searchPlaceholder' => 'Search all distributions...',
        'keyword' => $distKeyword,
        'clearUrl' => $distClearUrl(),
        'hiddenHtml' => '<input type="hidden" name="tab" value="log">'
            . ($distPerPage !== 25 ? '<input type="hidden" name="per_page" value="' . esc((string) $distPerPage, 'attr') . '">' : ''),
    ]) ?>
    <?php
    $distFooter = $distTotalRows >= 0 ? view('components/table_footer', [
        'fromRecord' => $distFrom,
        'toRecord' => $distTo,
        'totalRows' => $distTotalRows,
        'page' => $distPage,
        'totalPages' => $distTotalPages,
        'pageUrl' => $distPageUrl,
    ]) : null;
    ?>
        <section class="card batch-card" aria-label="All distributions" data-distribution-management-root>
        <div class="card-body">
            <h2 class="batch-pane-title">Distribution Log</h2>
            <?= view('Admin/distribution-distributions-body', [
                'distributions' => $distributions ?? [],
                'keyword' => $distKeyword,
                'perPage' => $distPerPage,
                'perPageOptions' => $distPerPageOptions,
            ]) ?>
            <?php if ($distFooter !== null): ?>
                <div class="mt-3 small text-muted">
                    <?= $distFooter ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
