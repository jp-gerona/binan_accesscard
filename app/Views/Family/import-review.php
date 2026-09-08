<?php
/**
 * Import review (Family Records > Import Excel, step 2). The staged rows of an upload
 * as one paginated row per person, so every problem in the file is reachable and a
 * 10,000-row import renders as fast as a small one.
 *
 * Rows arrive from records/import/review/:id/rows, a page at a time, and are painted by
 * import-review.js. Clicking a flagged row expands an editor for every field carrying a
 * problem; nothing is staged until Apply, and nothing reaches the member table until
 * Confirm import. Cancel discards staging.
 *
 * Renders inside the shared dashboard layout, so it loads no assets of its own.
 */
$jobId   = (int) ($jobId ?? 0);
$summary = $summary ?? ['file' => '', 'counts' => [], 'codes' => [], 'fileNotices' => []];
$fieldOptions = $fieldOptions ?? [];
$counts  = is_array($summary['counts'] ?? null) ? $summary['counts'] : [];

// JSON islands: HEX_TAG/HEX_AMP keep any "</script>" or "&" from a spreadsheet cell
// from breaking out of the <script> tag (defence against a crafted .xlsx).
$summaryJson = json_encode($summary, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
$fieldOptionsJson = json_encode($fieldOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<div id="importReview" class="pb-5 mb-5"
     data-rows-url="<?= esc(site_url('records/import/review/' . $jobId . '/rows'), 'attr') ?>"
     data-apply-url="<?= esc(site_url('records/import/review/' . $jobId . '/apply'), 'attr') ?>"
     data-resolve-duplicate-url="<?= esc(site_url('records/import/review/' . $jobId . '/resolve-duplicate'), 'attr') ?>"
     data-restore-url="<?= esc(site_url('records/import/review/' . $jobId . '/restore'), 'attr') ?>"
     data-commit-url="<?= esc(site_url('records/import/review/' . $jobId . '/commit'), 'attr') ?>"
     data-cancel-url="<?= esc(site_url('records/import/review/' . $jobId . '/cancel'), 'attr') ?>"
     data-redirect-url="<?= esc(site_url('records'), 'attr') ?>">

    <input type="hidden" id="reviewCsrf" name="<?= csrf_token() ?>" value="<?= csrf_hash() ?>">

    <?php /* The review step reads as an error while blocking rows remain, so a file
             that cannot be committed says so in the page chrome and not only in the
             severity pills below. */ ?>
    <?= view('components/stepper', [
        'orientation' => 'horizontal',
        'label'       => 'Import progress',
        'steps'       => [
            ['label' => 'Upload', 'state' => 'done'],
            ['label' => 'Review and Fix', 'state' => ((int) ($counts['blocking'] ?? 0)) > 0 ? 'error' : 'current'],
        ],
    ]) ?>

    <p class="text-muted">
        File: <strong id="reviewFileName"><?= esc($summary['file'] ?? '') ?></strong>.
        Nothing is saved until you press <strong>Confirm import</strong>. Open a flagged
        row to correct it.
    </p>

    <div id="importReviewNotices">
        <?php foreach (($summary['fileNotices'] ?? []) as $notice) : ?>
            <div class="alert alert-danger" role="alert"><?= esc($notice) ?></div>
        <?php endforeach; ?>
    </div>

    <ul class="nav nav-pills segmented-tabs mb-3" id="importReviewSeverity" role="tablist">
        <li class="nav-item"><button type="button" class="nav-link active" data-severity="all">All</button></li>
        <li class="nav-item"><button type="button" class="nav-link" data-severity="problems">Problems</button></li>
        <li class="nav-item"><button type="button" class="nav-link" data-severity="blocking">
            Must fix <span class="badge rounded-pill text-bg-danger" data-count="blocking"><?= (int) ($counts['blocking'] ?? 0) ?></span>
        </button></li>
        <li class="nav-item"><button type="button" class="nav-link" data-severity="warning">
            Warnings <span class="badge rounded-pill text-bg-warning" data-count="warnings"><?= (int) ($counts['warnings'] ?? 0) ?></span>
        </button></li>
        <li class="nav-item"><button type="button" class="nav-link" data-severity="discarded">
            Discarded <span class="badge rounded-pill text-bg-secondary" data-count="discarded"><?= (int) ($counts['discarded'] ?? 0) ?></span>
        </button></li>
    </ul>

    <?php
    $problemOptions = [];
    foreach (($summary['codes'] ?? []) as $code) {
        $problemOptions[] = ['value' => $code['code'], 'label' => $code['label'], 'pill' => $code['label']];
    }
    ?>
    <?= view('components/toolbar', [
        'formId' => 'importReviewDatabaseSearchForm',
        'disableGenericFilterJs' => false,
        'isClient' => true,
        'formAria' => 'Import review search and filters',
        'searchPlaceholder' => 'Search this import...',
        'searchName' => 'q',
        'searchAttrs' => 'id="importReviewDatabaseSearch"',
        'pillsId' => 'importReviewFilterPills',
        'filterGroups' => [
            [
                'name' => 'code[]',
                'label' => 'Problem',
                'type' => 'checkbox',
                'scroll' => true,
                'options' => $problemOptions,
            ]
        ],
    ]) ?>

    <?php /* House list-surface shell (batch-card), matching Reference Data's
             ui-ux. The severity tabs above stay outside the card, same as a toolbar
             does on those pages; the search input, filters and table markup are unchanged,
             just relocated into Family/import-review-table as the card's body. */ ?>
    <section class="card batch-card import-review-card" data-import-review-root>
        <div class="card-body">
            <h2 class="batch-pane-title">Rows to review</h2>
            <?= view('Family/import-review-table', ['summary' => $summary]) ?>
            <div class="mt-3 small text-muted">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 w-100">
                    <div class="table-footer-left">
                        <span id="importReviewCount" role="status" aria-live="polite"></span>
                    </div>
                    <div class="table-footer-right">
                        <ul class="pagination pagination-sm m-0" id="importReviewPager"></ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="fixed-bottom bg-white border-top p-3 shadow-sm d-flex flex-wrap justify-content-end align-items-center gap-2">
        <span id="importReviewStatus" class="text-muted me-auto" role="status" aria-live="polite"></span>
        <button type="button" class="btn btn-outline-secondary" id="importReviewCancel">
            Cancel import
        </button>
        <button type="button" class="<?= btn('add') ?>" id="importReviewConfirm" disabled>
            Confirm import
        </button>
    </div>

    <?php /* Payloads ride in <template> rather than <script type="application/json">. A
             <template>'s content is still parsed as HTML text, so a raw "&" from the
             payload could start a character entity a browser decodes before the JS reads
             it. The JSON_HEX_TAG/JSON_HEX_AMP flags on the encode side keep "<" and "&"
             out of the payload entirely, which is what keeps that entity decoding from
             corrupting it. */ ?>
    <template id="importReviewSummary"><?= $summaryJson ?></template>
    <template id="importReviewFieldOptions"><?= $fieldOptionsJson ?></template>
</div>
