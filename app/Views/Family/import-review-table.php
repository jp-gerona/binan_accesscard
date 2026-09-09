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
    <form class="records-table-search-form import-review-table-search mb-0" role="search" aria-label="Search this page" data-lookup-search>
        <div class="input-group input-group-sm">
            <input class="form-control" type="search" id="importReviewSearch" placeholder="Search this page..." autocomplete="off" aria-label="Search this page" data-lookup-search-input>
            <button class="btn btn-primary" type="submit" aria-label="Search this page"><i class="bi bi-search" aria-hidden="true"></i></button>
        </div>
    </form>
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
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0" id="importReviewTable">
        <thead class="text-nowrap">
            <tr>
                <th scope="col" class="import-review-status-col"><span class="visually-hidden">Status</span></th>
                <th scope="col" class="import-review-compact-col">Row</th>
                <th scope="col" class="import-review-compact-col">ID</th>
                <th scope="col" class="import-review-compact-col">Role</th>
                <th scope="col" class="import-review-name-col">Last Name</th>
                <th scope="col" class="import-review-name-col">First Name</th>
                <th scope="col" class="import-review-name-col">Middle Name</th>
                <th scope="col" class="w-100">Issues</th>
                <th scope="col" class="import-review-open-col"><span class="visually-hidden">Open</span></th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
