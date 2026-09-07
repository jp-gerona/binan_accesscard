<?php
/**
 * Import review table body: the local search/filter controls and the
 * staged-rows table itself. Rendered inside components/card by
 * Family/import-review.php; the rows are painted client-side by
 * import-review.js, not by this view.
 */
$summary = $summary ?? [];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="flex-grow-1 import-review-search">
        <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
            <input type="search" class="form-control" id="importReviewSearch"
                   placeholder="Search this import..." aria-label="Search this import">
        </div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="d-flex align-items-center gap-2 small text-muted">
            <label class="mb-0" for="importReviewCodeFilter">Problem</label>
            <select class="form-select form-select-sm w-auto" id="importReviewCodeFilter">
                <option value="">All problems</option>
                <?php foreach (($summary['codes'] ?? []) as $code) : ?>
                    <option value="<?= esc($code['code'], 'attr') ?>"><?= esc($code['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="d-flex align-items-center gap-2 small text-muted">
            <label class="mb-0" for="importReviewPerPage">Show</label>
            <select class="form-select form-select-sm w-auto" id="importReviewPerPage">
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
            <span>entries</span>
        </div>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0" id="importReviewTable">
        <thead class="text-nowrap">
            <tr>
                <th scope="col" style="width: 1%;"><span class="visually-hidden">Status</span></th>
                <th scope="col" style="width: 1%;">Row</th>
                <th scope="col" style="width: 1%;">ID</th>
                <th scope="col" style="width: 1%;">Role</th>
                <th scope="col" style="width: 1%;">Last Name</th>
                <th scope="col" style="width: 1%;">First Name</th>
                <th scope="col" style="width: 1%;">Middle Name</th>
                <th scope="col" class="w-100">Issues</th>
                <th scope="col" style="width: 1%;"><span class="visually-hidden">Open</span></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
