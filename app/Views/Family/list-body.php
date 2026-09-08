<?php
/**
 * Family records list body: the AJAX DataTable only. The search/filter toolbar
 * moved to components/records_toolbar (rendered above this card by
 * Family/list.php). The DataTable endpoint is the flat records/data route.
 */
?>
<div class="table-responsive">
    <table
        class="table manage-record-table table-hover align-middle w-100 mb-0"
        id="familyRecordsTable"
        data-ajax-url="<?= esc(site_url('records/data'), 'attr') ?>"
    >
        <thead class="table-light">
        <tr>
            <th class="fw-semibold small text-center">QR NO.</th>
            <th class="fw-semibold small">HEAD NAME</th>
            <th class="fw-semibold small text-center">MEMBERS</th>
            <th class="fw-semibold small">SECTOR</th>
            <th class="fw-semibold small">ADDRESS</th>
            <th class="fw-semibold small text-end">ACTIONS</th>
        </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
