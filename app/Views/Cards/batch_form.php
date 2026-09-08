<?php
/**
 * QR access card issuing page (Admin > Cards).
 *
 * Reads $cardBarangayNames from DashboardPageBuilder: the same live barangay list
 * headsForCards() compares against, so the filter can't offer a value that matches
 * nothing. Two modes share the page: Batch, which takes a barangay and a
 * control-number range and previews the heads it would print, and Single card, which
 * takes one searchable head or an exact control number. The PDF is the real output;
 * the table only previews who lands in it.
 *
 * Layout constraint worth knowing before editing: the segmented tabs must not sit
 * inside a flex wrapper such as .records-scroll-panel, or the inline-flex tab track
 * stretches to full width.
 */

helper('ui');
$barangayList = $cardBarangayNames ?? [];
?>
<ul class="nav nav-pills segmented-tabs mb-4" id="cn-modes" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" type="button" data-mode="batch" aria-current="page">
            Batch Export
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" type="button" data-mode="single">
            Individual Export
        </button>
    </li>
</ul>


<!-- BATCH -->
<div id="cn-panel-batch">
    <section class="card batch-card mb-4 sector-management">
        <div class="card-body">
            <h2 class="batch-pane-title">Batch Configuration</h2>
            <div class="alert alert-primary d-flex align-items-center mb-3 py-2" role="alert">
                <i class="bi bi-info-circle flex-shrink-0 me-2 fs-5"></i>
                <div class="small">Leave all fields blank to export all active family heads. Both range bounds are inclusive.</div>
            </div>
            <form id="cn-batch-form" class="row g-3 align-items-end" autocomplete="off">
                <?= csrf_field() ?>
                <div class="col-12 col-md-5">
                    <label class="form-label" for="cn-barangay">Barangay</label>
                    <select class="form-select" id="cn-barangay" name="barangay">
                        <option value="">All barangays</option>
                        <?php foreach ($barangayList as $barangay): ?>
                            <option value="<?= esc($barangay, 'attr') ?>"><?= esc($barangay) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2 position-relative">
                    <label class="form-label" for="cn-from">Starting #</label>
                    <input type="number" min="1" inputmode="numeric" class="form-control" id="cn-from" name="from">
                    <div class="invalid-tooltip">Must be &le; ending #</div>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="cn-to">Ending #</label>
                    <input type="number" min="1" inputmode="numeric" class="form-control" id="cn-to" name="to">
                </div>
                <div class="col-12 col-md-3">
                    <button type="submit" class="<?= btn('generate') ?> w-100" id="cn-batch-btn">
                        <i class="bi bi-printer me-1" aria-hidden="true"></i>
                        <span>Export Cards</span>
                        <span id="cn-batch-status" class="badge bg-light text-dark ms-1" aria-live="polite">0</span>
                    </button>
                </div>
            </form>

        </div>
    </section>

    <section class="card batch-card mb-4 sector-management" id="cn-card-batch">
        <div class="card-body">
            <h2 class="batch-pane-title">Access Cards</h2>
            <?php /* The page search only hides non-matching preview rows already on
                     screen - it never touches the barangay/from/to params, so what
                     Generate cards prints always matches the full selection. */ ?>
            <?= view('components/table_controls', [
                'searchId' => 'cn-preview-search',
                'searchAria' => 'Search this page',
                'searchFormAttrs' => 'onsubmit="return false;"',
                'sizeId' => 'cn-per-page',
                'sizeAction' => null,
                'perPage' => 25,
                'perPageOptions' => [10 => '10', 25 => '25', 50 => '50', 100 => '100'],
            ]) ?>

            <div class="table-responsive">
                <table class="table manage-record-table align-middle w-100 mb-0" id="cn-preview-table">
                    <thead>
                        <tr><th scope="col">Control #</th><th scope="col">Head name</th><th scope="col">Barangay</th></tr>
                    </thead>
                    <tbody id="cn-preview-body">
                        <tr><td colspan="3" class="text-muted">Loading&hellip;</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 w-100 mt-3 small text-muted">
                <div class="table-footer-left"><span id="cn-preview-count" aria-live="polite">Loading preview&hellip;</span></div>
                <div class="table-footer-right" id="cn-preview-paging"></div>
            </div>
        </div>
    </section>
</div>

<section class="card batch-card mb-4 sector-management" id="cn-card-single" hidden>
    <div class="card-body">
        <h2 class="batch-pane-title">Individual Configuration</h2>
        <div class="alert alert-primary d-flex align-items-center mb-3 py-2" role="alert">
            <i class="bi bi-info-circle flex-shrink-0 me-2 fs-5"></i>
            <div class="small">Enter the exact Control Number of the family head to export their individual card.</div>
        </div>
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label" for="cn-control">Control #</label>
                <input type="number" min="1" inputmode="numeric" class="form-control" id="cn-control">
            </div>
            <div class="col-12 col-md-3">
                <button type="button" class="<?= btn('generate') ?> w-100" id="cn-single-btn">
                    <i class="bi bi-printer me-1" aria-hidden="true"></i>
                    <span>Export Card</span>
                </button>
            </div>
        </div>
    </div>
</section>

<style>
#cn-modes .nav-link { cursor: pointer; }
</style>

<script>
(function () {
    const maxQuantity = <?= (int) config('QrCardSettings')->maxQuantity ?>;
    const headsUrl    = '<?= site_url('cards/heads') ?>';
    const generateUrl = '<?= site_url('cards/generate') ?>';
    const cardUrlBase = '<?= site_url('cards/card') ?>';

    // ---- mode switch ------------------------------------------------------
    const modeBtns = document.querySelectorAll('#cn-modes [data-mode]');
    const cards = { batch: document.getElementById('cn-panel-batch'), single: document.getElementById('cn-card-single') };
    modeBtns.forEach((b) => b.addEventListener('click', function () {
        modeBtns.forEach((x) => { x.classList.remove('active'); x.removeAttribute('aria-current'); });
        b.classList.add('active'); b.setAttribute('aria-current', 'page');
        const mode = b.dataset.mode;
        cards.batch.hidden = mode !== 'batch';
        cards.single.hidden = mode !== 'single';
    }));

    // ---- shared helpers ---------------------------------------------------
    function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

    async function download(resp, fallback) {
        const blob = await resp.blob();
        const disposition = resp.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="([^"]+)"/);
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = match ? match[1] : fallback;
        document.body.appendChild(a); a.click(); a.remove();
        URL.revokeObjectURL(url);
    }

    // ---- batch: live preview ---------------------------------------------
    const batchForm = document.getElementById('cn-batch-form');
    const countEl = document.getElementById('cn-preview-count');
    const bodyEl = document.getElementById('cn-preview-body');
    const batchBtn = document.getElementById('cn-batch-btn');
    const batchStatus = document.getElementById('cn-batch-status');
    const pagingEl = document.getElementById('cn-preview-paging');
    const perPageEl = document.getElementById('cn-per-page');
    const previewSearchInput = document.getElementById('cn-preview-search');
    let debounce;

    // Hides preview rows that don't match the page-search box - display-only,
    // never touches the barangay/from/to params that Generate cards submits.
    function applyPreviewFilter() {
        const q = (previewSearchInput.value || '').trim().toLowerCase();
        Array.from(bodyEl.rows).forEach((row) => {
            row.style.display = !q || row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    }
    if (previewSearchInput) {
        previewSearchInput.addEventListener('input', applyPreviewFilter);
    }
    // The preview is paged by the server (cards/heads), so the whole
    // selection stays available without shipping every row to the browser.
    let previewPage = 1;

    async function refreshPreview() {
        const params = new URLSearchParams({
            barangay: document.getElementById('cn-barangay').value,
            from: document.getElementById('cn-from').value,
            to: document.getElementById('cn-to').value,
            page: String(previewPage),
            per_page: perPageEl ? perPageEl.value : '25',
        });
        try {
            const resp = await fetch(headsUrl + '?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!resp.ok) { throw new Error('preview'); }
            const data = await resp.json();
            const count = data.count || 0;
            const rows = data.rows || [];
            const page = data.page || 1;
            const totalPages = data.totalPages || 1;
            previewPage = page;
            const perPage = data.perPage || 25;
            const from = (page - 1) * perPage;

            if (count === 0) {
                countEl.textContent = 'No heads match. Adjust the filters.';
                bodyEl.innerHTML = '<tr><td colspan="3" class="text-muted">No heads match the current filters.</td></tr>';
                pagingEl.textContent = '';
                batchBtn.disabled = true;
                return;
            }
            if (count > maxQuantity) {
                batchStatus.className = 'badge bg-danger ms-2';
                batchStatus.textContent = count;
                batchStatus.title = 'Max ' + maxQuantity + ' cards per batch';
                batchBtn.disabled = true;
            } else {
                batchStatus.className = 'badge bg-light text-dark ms-2';
                batchStatus.textContent = count;
                batchStatus.title = '';
                batchBtn.disabled = false;
            }
            countEl.textContent = 'Showing ' + (from + 1) + ' to ' + (from + rows.length) + ' of ' + count + ' cards';
            bodyEl.innerHTML = rows.map((r) =>
                '<tr><td>' + esc(String(r.controlNo)) + '</td><td>' + esc(r.name) + '</td><td>' + esc(r.barangay) + '</td></tr>'
            ).join('');
            applyPreviewFilter();
            // Same footer markup as every other list (table-paginate.js).
            window.renderTablePaging(pagingEl, page, totalPages);
        } catch (e) {
            countEl.textContent = 'Preview unavailable.';
            bodyEl.innerHTML = '<tr><td colspan="3" class="text-muted">Preview unavailable.</td></tr>';
            pagingEl.textContent = '';
            batchBtn.disabled = true;
        }
    }

    function reloadFromFirstPage() {
        previewPage = 1;
        refreshPreview();
    }

    pagingEl.addEventListener('click', function (event) {
        const link = event.target.closest('[data-paginate-page]');
        event.preventDefault();
        if (!link || link.closest('.page-item').classList.contains('disabled')) {
            return;
        }
        previewPage = parseInt(link.dataset.paginatePage, 10) || 1;
        refreshPreview();
    });

    if (perPageEl) {
        perPageEl.addEventListener('change', reloadFromFirstPage);
    }

    ['cn-barangay', 'cn-from', 'cn-to'].forEach((id) => {
        document.getElementById(id).addEventListener('input', function () {
            // clear validation state on change
            document.getElementById('cn-from').classList.remove('is-invalid');
            clearTimeout(debounce); debounce = setTimeout(reloadFromFirstPage, 300);
        });
    });
    // After load, not inline: this script runs while table-paginate.js is still
    // being fetched, so a preview drawn now throws on renderTablePaging and the
    // catch below prints "Preview unavailable." on a page whose data is fine.
    if (document.readyState === 'complete') {
        refreshPreview();
    } else {
        window.addEventListener('load', refreshPreview);
    }

    batchForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        // Validate
        const fromEl = document.getElementById('cn-from');
        const toEl = document.getElementById('cn-to');
        const fromVal = parseInt(fromEl.value, 10);
        const toVal = parseInt(toEl.value, 10);
        if (!isNaN(fromVal) && !isNaN(toVal) && fromVal > toVal) {
            fromEl.classList.add('is-invalid');
            return;
        }

        window.showToast('Generating…', 'primary');
        batchBtn.disabled = true;
        try {
            const resp = await fetch(generateUrl, {
                method: 'POST', body: new FormData(batchForm),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const fresh = resp.headers.get('X-CSRF-TOKEN');
            if (fresh) { const h = batchForm.querySelector('input[type="hidden"]'); if (h) { h.value = fresh; } }
            if (!resp.ok) {
                const text = await resp.text();
                let msg = 'Generation failed.';
                try { msg = JSON.parse(text).error || msg; } catch (_) {}
                window.showToast(msg, 'danger');
                return;
            }
            
            let fallbackName = 'binan-qr-cards.pdf';
            const contentType = resp.headers.get('Content-Type');
            if (contentType && contentType.includes('zip')) {
                fallbackName = 'binan-qr-cards.zip';
            }
            
            await download(resp, fallbackName);
            window.showToast('Cards exported successfully.', 'success');
        } catch (err) {
            window.showToast('Generation failed. Please try again.', 'danger');
        } finally {
            refreshPreview();
        }
    });

    // ---- single: generate ---------------------------------
    const controlInput = document.getElementById('cn-control');
    const singleBtn = document.getElementById('cn-single-btn');

    singleBtn.addEventListener('click', async function () {
        const control = controlInput.value.trim();
        if (!control) { window.showToast('Please enter a control number.', 'warning'); return; }
        
        let memberId = null;

        // Exact control number path: resolve to a head via the heads feed (range
        // collapsed to the single control_no) before hitting the single-card route,
        // so a bad number fails with a clear message instead of a 404 download.
        // control_no is the paper QR number, not necessarily the memberID, so we
        // must look it up rather than assume control === memberID.
        try {
            const resp = await fetch(headsUrl + '?from=' + encodeURIComponent(control) + '&to=' + encodeURIComponent(control), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await resp.json();
            const hit = (data.rows || []).find((r) => String(r.controlNo) === control);
            if (!hit) { window.showToast('No head found for control number ' + control + '.', 'warning'); return; }
            memberId = hit.memberID;
        } catch (e) { window.showToast('Lookup failed. Try again.', 'danger'); return; }

        window.showToast('Generating…', 'primary');
        singleBtn.disabled = true;
        try {
            const resp = await fetch(cardUrlBase + '/' + memberId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!resp.ok) { window.showToast('Generation failed.', 'danger'); return; }
            await download(resp, 'binan-qr-card.pdf');
            window.showToast('Card generated.', 'success');
        } catch (e) {
            window.showToast('Generation failed. Please try again.', 'danger');
        } finally {
            singleBtn.disabled = false;
        }
    });
})();
</script>
