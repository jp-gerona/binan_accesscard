// Import Review, step 2 of the import wizard.
//
// One row per staged person, a page at a time from records/import/review/:id/rows, so a
// 10,000-row file renders as fast as a small one. The Issues column names every problem
// on a row, including ones on columns the table does not show, and opening the row
// reveals an editor for each field carrying a problem.
//
// Nothing is staged until Apply, and nothing reaches the member table until Confirm
// import. An Apply that touches a field driving cross-row rules refetches the page,
// because other rows' flags can move with it.
//
// Backend: GET records/import/review/:id/rows, POST records/import/review/:id/apply|commit|cancel
(function (window, document) {
    'use strict';

    // Must match STORAGE_KEY in family-import.js so the write job's toast resumes there.
    var IMPORT_TRACK_KEY = 'binanFamilyImport';

    var root = document.getElementById('importReview');

    if (!root) {
        return;
    }

    var commitUrl   = root.dataset.commitUrl;
    var cancelUrl   = root.dataset.cancelUrl;
    var redirectUrl = root.dataset.redirectUrl;
    var rowsUrl      = root.dataset.rowsUrl;
    var applyUrl     = root.dataset.applyUrl;
    var resolveDuplicateUrl = root.dataset.resolveDuplicateUrl;
    var restoreUrl   = root.dataset.restoreUrl;
    var databaseSearchEl = document.getElementById('importReviewDatabaseSearch');
    var filterForm   = document.getElementById('importReviewDatabaseSearchForm');
    var perPageEl    = document.getElementById('importReviewPerPage');
    var pagerEl      = document.getElementById('importReviewPager');

    var table      = document.getElementById('importReviewTable');
    var tbody      = table ? table.querySelector('tbody') : null;
    var countEl    = document.getElementById('importReviewCount');
    var statusEl   = document.getElementById('importReviewStatus');
    var confirmBtn = document.getElementById('importReviewConfirm');
    var cancelBtn  = document.getElementById('importReviewCancel');

    // The two action buttons are not optional: without them there is no way to finish
    // or discard the staged import, so a page missing them is not a review page.
    if (!confirmBtn || !cancelBtn) {
        return;
    }

    var state = {
        page: 1,
        per: 25,
        severity: 'all',
        code: [],
        q: '',
        total: 0,
        filtered: 0
    };

    var rowsBySheetRow = {};

    var summary = parseJson('importReviewSummary', { file: '', counts: {}, codes: [] });
    // Dropdown option lists (field => [option strings]) for the columns that are dropdowns
    // in the Excel template, so an inline field edit offers the same choices as the sheet.
    var fieldOptions = parseJson('importReviewFieldOptions', {});

    // The payload lives in a <template>, not a <script>: a <template>'s children land
    // in its .content fragment rather than as its own child nodes, so the JSON has to
    // be read off node.content.textContent, not node.textContent (which is empty).
    function parseJson(id, fallback) {
        var node = document.getElementById(id);

        try {
            var parsed = JSON.parse(node ? node.content.textContent : 'null');

            return (parsed && typeof parsed === 'object') ? parsed : fallback;
        } catch (e) {
            return fallback;
        }
    }

    // -- small DOM helpers -----------------------------------------------------

    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text != null) {
            node.textContent = String(text);
        }

        return node;
    }

    function csrfField() {
        return document.getElementById('reviewCsrf');
    }

    function setStatus(message) {
        if (statusEl) {
            statusEl.textContent = message || '';
        }
    }

    // Promise-based confirm reusing the layout's #familyActionModal, so Cancel and Confirm
    // match the app's dialog instead of a native window.confirm. Resolves true on confirm.
    function confirmAction(opts) {
        opts = opts || {};

        var modalEl = document.getElementById('familyActionModal');
        var bs = window.bootstrap;

        if (!modalEl || !bs || !bs.Modal) {
            return Promise.resolve(window.confirm(opts.message || 'Are you sure?'));
        }

        var titleEl = modalEl.querySelector('#familyActionModalLabel');
        var msgEl = modalEl.querySelector('.js-family-action-message');
        var okBtn = modalEl.querySelector('.js-family-action-confirm');

        if (titleEl) {
            titleEl.textContent = opts.title || 'Please confirm';
        }
        if (msgEl) {
            msgEl.textContent = '';
            if (opts.node) {
                msgEl.appendChild(opts.node);
            } else {
                msgEl.textContent = opts.message || 'Are you sure?';
            }
        }
        if (okBtn) {
            okBtn.textContent = opts.confirmLabel || 'Confirm';
            okBtn.className = 'btn ' + (opts.confirmClass || 'btn-danger') + ' js-family-action-confirm';
        }

        var modal = bs.Modal.getOrCreateInstance(modalEl);

        return new Promise(function (resolve) {
            var settled = false;

            function cleanup() {
                okBtn.removeEventListener('click', onConfirm);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
            }

            function onConfirm() {
                settled = true;
                cleanup();
                modal.hide();
                resolve(true);
            }

            function onHidden() {
                cleanup();
                if (!settled) {
                    resolve(false);
                }
            }

            okBtn.addEventListener('click', onConfirm);
            modalEl.addEventListener('hidden.bs.modal', onHidden);
            modal.show();
        });
    }

    // -- the table -------------------------------------------------------------

    function loadRows() {
        setStatus('Loading...');

        var url = rowsUrl
            + '?page=' + encodeURIComponent(state.page)
            + '&per=' + encodeURIComponent(state.per)
            + '&severity=' + encodeURIComponent(state.severity)
            + '&code=' + encodeURIComponent(state.code)
            + '&q=' + encodeURIComponent(state.q);

        return window.fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        }).then(function (result) {
            if (!result.ok) {
                setStatus((result.data && result.data.message) || 'The rows could not be loaded.');
                return;
            }

            state.total = Number(result.data.total || 0);
            state.filtered = Number(result.data.filtered || 0);
            renderRows(result.data.rows || []);
            renderPager();
            setStatus('');
        }).catch(function () {
            setStatus('A network error occurred. Please try again.');
        });
    }

    function renderRows(rows) {
        tbody.textContent = '';
        rowsBySheetRow = {};

        rows.forEach(function (row) {
            rowsBySheetRow[row.sheetRow] = row;
        });

        if (!rows.length) {
            var empty = el('tr');
            var cell = el('td', 'text-center text-muted py-4', 'No people match this filter.');
            cell.colSpan = 9;
            empty.appendChild(cell);
            tbody.appendChild(empty);
        }

        rows.forEach(function (row) {
            tbody.appendChild(personRow(row));
        });

        renderCount();
    }

    function renderCount() {
        var first = state.filtered === 0 ? 0 : ((state.page - 1) * state.per) + 1;
        var last = Math.min(state.page * state.per, state.filtered);

        countEl.textContent = 'Showing ' + first + ' to ' + last + ' of ' + state.filtered
            + ' people' + (state.filtered !== state.total ? ' (filtered from ' + state.total + ')' : '');
    }

    function personRow(row) {
        var flagged = !!row.severity;
        var tr = el('tr', row.discarded ? 'table-secondary import-review-discarded' : (flagged ? (row.severity === 'blocking' ? 'table-danger' : 'table-warning') : ''));
        tr.dataset.row = row.sheetRow;

        tr.appendChild(statusCell(row));
        tr.appendChild(el('td', 'font-monospace text-nowrap', String(row.sheetRow)));
        tr.appendChild(el('td', 'text-nowrap', row.qr || ''));
        tr.appendChild(el('td', 'text-uppercase', row.role || ''));
        tr.appendChild(el('td', null, (row.values || {}).lastname || ''));
        tr.appendChild(el('td', 'text-nowrap', (row.values || {}).firstname || ''));
        tr.appendChild(el('td', null, (row.values || {}).middlename || ''));
        tr.appendChild(issuesCell(row.issues || [], !!row.discarded));
        tr.appendChild(openCell(row));

        return tr;
    }

    function statusCell(row) {
        var td = el('td', 'text-center import-review-status-col');

        if (row.discarded) {
            var discardedIcon = el('i', 'bi bi-arrow-return-left text-muted');
            discardedIcon.setAttribute('aria-hidden', 'true');
            discardedIcon.title = 'Discarded';
            td.appendChild(discardedIcon);
            td.appendChild(el('span', 'visually-hidden', 'Discarded'));

            return td;
        }

        if (!row.severity) {
            return td;
        }

        var blocking = row.severity === 'blocking';
        var icon = el('i', 'bi ' + (blocking ? 'bi-exclamation-triangle-fill text-danger' : 'bi-exclamation-circle-fill text-warning'));
        icon.setAttribute('aria-hidden', 'true');
        icon.title = blocking ? 'Must fix' : 'Warning';
        td.appendChild(icon);
        td.appendChild(el('span', 'visually-hidden', blocking ? 'Must fix' : 'Warning'));

        return td;
    }

    // Every distinct problem on the row, including the informational ones that offer
    // nothing to edit: a row that will be skipped must say so.
    function issuesCell(issues, discarded) {
        var td = el('td');
        var list = el('div', 'import-review-issues');

        issues.forEach(function (issue) {
            var badge = el('span',
                'badge ' + (discarded ? 'text-bg-secondary' : (issue.severity === 'blocking' ? 'text-bg-danger' : 'text-bg-warning')),
                (issue.cell ? issue.cell + ' · ' : '') + issue.label);
            badge.title = issue.message || '';
            list.appendChild(badge);
        });

        td.appendChild(list);

        return td;
    }

    // Discarded rows only restore. Active duplicate rows use the same toggle as normal
    // editors, so a field correction remains available beside the focused resolver.
    function openCell(row) {
        var td = el('td', 'text-end import-review-open-col');

        if (row.discarded) {
            var restore = el('button', 'btn btn-sm btn-outline-secondary js-import-restore', 'Restore');
            restore.type = 'button';
            restore.dataset.row = row.sheetRow;
            td.appendChild(restore);

            return td;
        }

        if (!(row.fields || []).length && !row.duplicateGroup) {
            return td;
        }

        var button = el('button', 'btn btn-sm btn-outline-secondary js-import-open');
        button.type = 'button';
        button.dataset.row = row.sheetRow;
        button.setAttribute('aria-expanded', 'false');
        button.appendChild(el('i', 'bi bi-pencil'));
        button.appendChild(el('span', 'visually-hidden', 'Fix this person'));
        td.appendChild(button);

        return td;
    }

    // The editor panel: one control per field carrying a problem, plus Apply/Discard.
    // Nothing posts until Apply, so a mistyped correction is discardable.
    function panelRow(row) {
        var tr = el('tr', 'js-import-panel');
        tr.dataset.panelFor = row.sheetRow;

        var td = el('td', 'bg-body-tertiary');
        td.colSpan = 9;

        var wrap = el('div', 'p-3');
        var grid = el('div', 'row g-2');

        (row.fields || []).forEach(function (field) {
            grid.appendChild(fieldControl(field, row.sheetRow));
        });

        if ((row.fields || []).length) {
            wrap.appendChild(grid);
        }

        if (row.duplicateGroup) {
            wrap.appendChild(duplicateComparison(row));
        }

        if ((row.fields || []).length) {
            var actions = el('div', 'd-flex justify-content-end gap-2 mt-3');
            var discard = el('button', 'btn btn-secondary js-import-discard', 'Discard');
            discard.type = 'button';
            var apply = el('button', 'btn btn-primary js-import-apply', 'Apply');
            apply.type = 'button';
            apply.dataset.row = row.sheetRow;
            actions.appendChild(discard);
            actions.appendChild(apply);
            wrap.appendChild(actions);
        }

        td.appendChild(wrap);
        tr.appendChild(td);

        return tr;
    }

    // Candidate details are presentation data from the presenter, not markup: every
    // spreadsheet value is placed through textContent by el() before it reaches the DOM.
    function duplicateComparison(row) {
        var group = row.duplicateGroup || {};
        var panel = el('section', 'js-import-duplicate-panel mt-3 pt-3 border-top');
        panel.appendChild(el('h3', 'h6 mb-2', 'Choose the one duplicate row to keep'));
        panel.appendChild(el('p', 'small text-muted mb-3', 'All other matching rows will be discarded from this import.'));

        var candidates = Array.isArray(group.candidates) && group.candidates.length
            ? group.candidates
            : (group.rows || []).map(function (sheetRow) {
                var candidate = rowsBySheetRow[sheetRow] || {};
                return { sheetRow: sheetRow, role: candidate.role || '', values: candidate.values || {} };
            });
        var grid = el('div', 'row g-2');

        candidates.forEach(function (candidate) {
            var column = el('div', 'col-12 col-md-6 col-xl-4');
            var card = el('div', 'border rounded p-3 h-100');
            card.appendChild(el('strong', 'd-block', 'Row ' + candidate.sheetRow));
            card.appendChild(el('span', 'small text-muted d-block mb-2', candidate.role || 'Member'));
            var values = candidate.values || {};
            card.appendChild(el('div', 'small mb-3', [
                values.lastname, values.firstname, values.middlename, values.birthday, values.sex
            ].filter(Boolean).join(' · ')));
            var keep = el('button', 'btn btn-sm btn-primary js-import-keep-duplicate', 'Keep this row');
            keep.type = 'button';
            keep.dataset.keepRow = candidate.sheetRow;
            card.appendChild(keep);
            column.appendChild(card);
            grid.appendChild(column);
        });

        panel.appendChild(grid);

        return panel;
    }

    function fieldControl(field, sheetRow) {
        var col = el('div', 'col-12 col-md-6 col-lg-4');
        var id = 'importField-' + sheetRow + '-' + field.field;

        var label = el('label', 'form-label small mb-1', field.label
            + (field.cell ? ' (cell ' + field.cell + ')' : ''));
        label.setAttribute('for', id);
        col.appendChild(label);

        var options = fieldOptions[field.field];
        var control = (options && options.length)
            ? buildSelect(field, options)
            : buildInput(field);

        control.id = id;
        control.classList.add('js-import-field');
        control.dataset.field = field.field;
        control.dataset.row = sheetRow;
        col.appendChild(control);

        if (field.message) {
            col.appendChild(el('div',
                'form-text ' + (field.severity === 'blocking' ? 'text-danger' : 'text-warning-emphasis'),
                field.message));
        }

        return col;
    }

    function buildInput(field) {
        var input = el('input', 'form-control form-control-sm');
        input.type = field.field === 'birthday' ? 'date' : 'text';
        
        var val = field.value || '';
        if (field.field === 'birthday' && val) {
            var parts = val.split(/[-/]/).map(function(s) { return s.trim(); });
            if (parts.length === 3) {
                var y, m, d;
                if (parts[0].length === 4) {
                    y = parts[0]; m = parts[1]; d = parts[2];
                } else if (parts[2].length === 4) {
                    y = parts[2]; m = parts[0]; d = parts[1];
                }
                if (y && m && d) {
                    m = m.length === 1 ? '0' + m : m;
                    d = d.length === 1 ? '0' + d : d;
                    val = y + '-' + m + '-' + d;
                }
            }
        }
        
        input.value = val;

        return input;
    }

    // A <select> mirroring the Excel column's dropdown. A blank first choice lets a
    // required-but-empty field start unselected; an off-list current value is kept as its
    // own option so saving never silently drops what is already there.
    function buildSelect(field, options) {
        var select = el('select', 'form-select form-select-sm');
        var current = field.value || '';

        var blank = el('option', null, '- choose -');
        blank.value = '';
        select.appendChild(blank);

        var matched = current === '';
        options.forEach(function (opt) {
            var option = el('option', null, opt);
            option.value = opt;
            if (opt === current) {
                option.selected = true;
                matched = true;
            }
            select.appendChild(option);
        });

        if (!matched) {
            var keep = el('option', null, current + ' (current)');
            keep.value = current;
            keep.selected = true;
            select.appendChild(keep);
        }

        return select;
    }

    // -- network ---------------------------------------------------------------

    function postForm(url, extra) {
        var body = new FormData();
        var field = csrfField();

        if (field) {
            body.append(field.name, field.value);
        }

        if (extra) {
            Object.keys(extra).forEach(function (key) {
                body.append(key, extra[key]);
            });
        }

        return window.fetch(url, {
            method: 'POST',
            body: body,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, code: response.status, data: data };
            }).catch(function () {
                return { ok: response.ok, code: response.status, data: {} };
            });
        });
    }

    function refreshCsrf(hash) {
        var field = csrfField();

        if (field && hash) {
            field.value = hash;
        }
    }

    // -- actions ---------------------------------------------------------------

    function applyRow(sheetRow) {
        var panel = tbody.querySelector('[data-panel-for="' + sheetRow + '"]');

        if (!panel) {
            return;
        }

        var controls = panel.querySelectorAll('.js-import-field');
        var payload = { import_row: sheetRow };
        var any = false;

        Array.prototype.forEach.call(controls, function (control) {
            payload['fields[' + control.dataset.field + ']'] = control.value;
            any = true;
        });

        if (!any) {
            return;
        }

        setBusy(panel, true);
        setStatus('Applying...');

        postForm(applyUrl, payload).then(function (result) {
            var data = result.data || {};
            refreshCsrf(data.csrf);

            if (!result.ok) {
                setBusy(panel, false);
                setStatus(data.message || 'The correction could not be applied.');

                return;
            }

            updateCounts(data.counts);
            updateCodeFilter(data.codes);

            // A correction can change duplicate membership or discarded state on another
            // row. Always refetch this page rather than trusting a single-row response.
            loadRows();
            setStatus('Applied.');
        }).catch(function () {
            setBusy(panel, false);
            setStatus('A network error occurred. Please try again.');
        });
    }

    function resolveDuplicate(keepRow) {
        if (!resolveDuplicateUrl) {
            return;
        }

        setStatus('Resolving duplicate...');
        postForm(resolveDuplicateUrl, { keep_row: keepRow }).then(function (result) {
            var data = result.data || {};
            refreshCsrf(data.csrf);

            if (!result.ok) {
                setStatus(data.message || 'The duplicate decision could not be applied.');

                return;
            }

            updateCounts(data.counts);
            updateCodeFilter(data.codes);
            loadRows();
            setStatus(data.message || 'Duplicate copies discarded.');
        }).catch(function () {
            setStatus('A network error occurred. Please try again.');
        });
    }

    function restoreRow(sheetRow) {
        if (!restoreUrl) {
            return;
        }

        setStatus('Restoring...');
        postForm(restoreUrl, { import_row: sheetRow }).then(function (result) {
            var data = result.data || {};
            refreshCsrf(data.csrf);

            if (!result.ok) {
                setStatus(data.message || 'The row could not be restored.');

                return;
            }

            updateCounts(data.counts);
            updateCodeFilter(data.codes);
            loadRows();
            setStatus(data.message || 'Duplicate row restored.');
        }).catch(function () {
            setStatus('A network error occurred. Please try again.');
        });
    }

    function updateCounts(counts) {
        if (!counts) {
            return;
        }

        summary.counts = counts;
        var blocking = Number(counts.blocking || 0);

        root.querySelectorAll('[data-count="blocking"]').forEach(function (node) {
            node.textContent = blocking;
        });
        root.querySelectorAll('[data-count="warnings"]').forEach(function (node) {
            node.textContent = Number(counts.warnings || 0);
        });
        root.querySelectorAll('[data-count="discarded"]').forEach(function (node) {
            node.textContent = Number(counts.discarded || 0);
        });

        confirmBtn.disabled = blocking > 0;
        confirmBtn.title = blocking > 0
            ? 'Fix the flagged values first, or correct them in the spreadsheet and upload again.'
            : '';
    }

    // Keeps the Problem dropdown honest after an Apply
    function updateCodeFilter(codes) {
        if (!codes) {
            return;
        }

        var current = state.code || [];
        var codeGroup = filterForm ? filterForm.querySelector('[data-records-filter="code"]') : null;

        if (!codeGroup) return;

        var listContainer = codeGroup.querySelector('.records-filter-list');
        if (!listContainer) {
            listContainer = document.createElement('div');
            listContainer.className = 'records-filter-list overflow-auto';
            codeGroup.appendChild(listContainer);
        } else {
            listContainer.innerHTML = '';
        }

        function createOption(val, label, isDefault) {
            var lbl = document.createElement('label');
            lbl.className = 'form-check d-flex align-items-center gap-2 py-1';
            lbl.dataset.recordsOption = '';
            var inp = document.createElement('input');
            inp.className = 'form-check-input m-0';
            inp.type = 'checkbox';
            inp.name = 'code[]';
            inp.value = val;
            if (!isDefault) inp.dataset.recordsPillLabel = label;
            if (isDefault) inp.dataset.recordsDefault = '';
            lbl.appendChild(inp);
            var span = document.createElement('span');
            span.className = 'form-check-label text-wrap small';
            span.textContent = label;
            lbl.appendChild(span);
            return lbl;
        }

        codes.forEach(function (code) {
            listContainer.appendChild(createOption(code.code, code.label, false));
        });

        var anyStillPresent = false;
        current.forEach(function (val) {
            var cb = listContainer.querySelector('input[value="' + val + '"]');
            if (cb) {
                cb.checked = true;
                anyStillPresent = true;
            }
        });

        if (!anyStillPresent && current.length > 0) {
            state.code = [];
            if (filterForm) filterForm.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (filterForm) {
            filterForm.removeAttribute('data-records-filter-bound');
            if (typeof window.initRecordsFilterPanel === 'function') {
                window.initRecordsFilterPanel(filterForm);
            }
        }
    }

    // One panel at a time: two open editors on one screen invite applying the wrong row.
    function togglePanel(button) {
        var sheetRow = button.dataset.row;
        var open = tbody.querySelector('[data-panel-for="' + sheetRow + '"]');

        if (open) {
            closePanel(open);

            return;
        }

        tbody.querySelectorAll('.js-import-panel').forEach(closePanel);

        var tr = tbody.querySelector('tr[data-row="' + sheetRow + '"]');
        var row = rowsBySheetRow[sheetRow];

        if (!tr || !row) {
            return;
        }

        tr.after(panelRow(row));
        button.setAttribute('aria-expanded', 'true');
    }

    function closePanel(panel) {
        if (!panel) {
            return;
        }

        var button = tbody.querySelector('.js-import-open[data-row="' + panel.dataset.panelFor + '"]');

        if (button) {
            button.setAttribute('aria-expanded', 'false');
        }

        panel.remove();
    }

    // Locks the panel while its Apply is in flight, so a double click cannot post twice.
    function setBusy(panel, busy) {
        panel.querySelectorAll('input, select, button').forEach(function (node) {
            node.disabled = busy;
        });
    }

    // A windowed pager: first, last, and two either side of the current page, so a
    // 400-page import does not render 400 links.
    function renderPager() {
        pagerEl.textContent = '';

        var pages = Math.max(1, Math.ceil(state.filtered / state.per));

        if (pages < 2) {
            return;
        }

        pagerEl.appendChild(pageItem('&laquo;', 1, state.page === 1));
        pagerEl.appendChild(pageItem('&lsaquo;', state.page - 1, state.page === 1));

        var wanted = {};
        wanted[1] = true;
        wanted[pages] = true;

        for (var n = state.page - 2; n <= state.page + 2; n++) {
            if (n >= 1 && n <= pages) {
                wanted[n] = true;
            }
        }

        var numbers = Object.keys(wanted).map(Number).sort(function (a, b) { return a - b; });
        var previous = 0;

        numbers.forEach(function (n) {
            if (previous && n - previous > 1) {
                var gap = el('li', 'page-item disabled');
                gap.appendChild(el('span', 'page-link', '...'));
                pagerEl.appendChild(gap);
            }

            pagerEl.appendChild(pageItem(String(n), n, false, n === state.page));
            previous = n;
        });

        pagerEl.appendChild(pageItem('&rsaquo;', state.page + 1, state.page === pages));
        pagerEl.appendChild(pageItem('&raquo;', pages, state.page === pages));
    }

    function pageItem(label, page, disabled, active) {
        var li = el('li', 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : ''));
        var a = el('a', 'page-link');
        a.innerHTML = label;
        a.href = '#';

        if (!disabled) {
            a.dataset.page = page;
        }

        if (active) {
            a.setAttribute('aria-current', 'page');
        }

        li.appendChild(a);

        return li;
    }

    // Recap what the write job will do, then commit only on confirm: the write is not
    // reversible from this screen.
    function confirmImport() {
        var counts = summary.counts || {};
        var newFamilies = Number(counts.newFamilies != null ? counts.newFamilies : (counts.families || 0));
        var appends = Number(counts.appends || 0);
        var skipped = Number(counts.existing || 0);
        var warnings = Number(counts.warnings || 0);

        var node = document.createElement('div');
        node.appendChild(el('p', 'mb-2', 'You are about to import:'));
        var list = el('ul', 'mb-2');
        list.appendChild(el('li', null, newFamilies + ' new famil' + (newFamilies === 1 ? 'y' : 'ies')));
        if (appends > 0) {
            list.appendChild(el('li', null, appends + ' member(s) added to existing families'));
        }
        if (skipped > 0) {
            list.appendChild(el('li', null, skipped + ' already in the system (skipped)'));
        }
        if (warnings > 0) {
            list.appendChild(el('li', null, warnings + ' warning(s) - imported as typed'));
        }
        node.appendChild(list);
        node.appendChild(el('p', 'mb-0 text-muted small', 'This cannot be undone from here.'));

        confirmAction({
            title: 'Confirm import',
            node: node,
            confirmLabel: 'Yes, import',
            confirmClass: 'btn-primary'
        }).then(function (ok) {
            if (ok) {
                doCommit();
            }
        });
    }

    function doCommit() {
        confirmBtn.disabled = true;
        cancelBtn.disabled = true;
        setStatus('Starting import...');

        postForm(commitUrl).then(function (result) {
            var data = result.data || {};
            refreshCsrf(data.csrf);

            if (result.ok && data.status === 'queued' && data.statusUrl) {
                rememberJob(data.statusUrl);
                window.location.href = data.redirect || redirectUrl;

                return;
            }

            cancelBtn.disabled = false;
            setStatus(data.message || 'The import could not be started.');
        }).catch(function () {
            cancelBtn.disabled = false;
            confirmBtn.disabled = false;
            setStatus('A network error occurred. Please try again.');
        });
    }

    function cancelImport() {
        confirmAction({
            title: 'Discard import',
            message: 'Discard this import? Nothing will be saved.',
            confirmLabel: 'Discard',
            confirmClass: 'btn-danger'
        }).then(function (ok) {
            if (!ok) {
                return;
            }

            cancelBtn.disabled = true;
            setStatus('Cancelling...');

            postForm(cancelUrl).then(function (result) {
                var data = result.data || {};
                window.location.href = data.redirect || redirectUrl;
            }).catch(function () {
                cancelBtn.disabled = false;
                setStatus('A network error occurred. Please try again.');
            });
        });
    }

    // Hand the write job's status URL to family-import.js so its progress toast appears
    // on the records page after we redirect there.
    function rememberJob(statusUrl) {
        try {
            var raw = window.localStorage.getItem(IMPORT_TRACK_KEY);
            var list = raw ? JSON.parse(raw) : [];

            if (!Array.isArray(list)) {
                list = [];
            }

            if (list.indexOf(statusUrl) === -1) {
                list.push(statusUrl);
            }

            window.localStorage.setItem(IMPORT_TRACK_KEY, JSON.stringify(list));
        } catch (e) { /* private mode / quota - the import still runs, just no toast */ }
    }

    // -- wire up ---------------------------------------------------------------

    root.addEventListener('click', function (event) {
        var keep = event.target.closest ? event.target.closest('.js-import-keep-duplicate') : null;

        if (keep) {
            resolveDuplicate(keep.dataset.keepRow);

            return;
        }

        var restore = event.target.closest ? event.target.closest('.js-import-restore') : null;

        if (restore) {
            restoreRow(restore.dataset.row);

            return;
        }

        var open = event.target.closest ? event.target.closest('.js-import-open') : null;

        if (open) {
            togglePanel(open);

            return;
        }

        var apply = event.target.closest ? event.target.closest('.js-import-apply') : null;

        if (apply) {
            applyRow(apply.dataset.row);

            return;
        }

        var discard = event.target.closest ? event.target.closest('.js-import-discard') : null;

        if (discard) {
            closePanel(discard.closest('.js-import-panel'));

            return;
        }

        var pageLink = event.target.closest ? event.target.closest('[data-page]') : null;

        if (pageLink) {
            event.preventDefault();
            state.page = Number(pageLink.dataset.page);
            loadRows();
        }
    });

    root.addEventListener('click', function (event) {
        var pill = event.target.closest ? event.target.closest('[data-severity]') : null;

        if (!pill) {
            return;
        }

        root.querySelectorAll('[data-severity]').forEach(function (node) {
            node.classList.toggle('active', node === pill);
        });

        state.severity = pill.dataset.severity;
        state.page = 1;
        loadRows();
    });

    // Debounced so typing does not fire a request per keystroke.
    var searchTimer = null;

    if (databaseSearchEl) {
        databaseSearchEl.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                state.q = databaseSearchEl.value;
                state.page = 1;
                loadRows();
            }, 250);
        });
    }

    perPageEl.addEventListener('change', function () {
        state.per = Number(perPageEl.value);
        state.page = 1;
        loadRows();
    });

    if (filterForm) {
        filterForm.addEventListener('change', function () {
            var codeInputs = filterForm.querySelectorAll('input[name="code[]"]:checked');
            var codes = [];
            codeInputs.forEach(function (inp) { codes.push(inp.value); });
            
            state.code = codes;
            state.page = 1;
            loadRows();
        });

        filterForm.addEventListener('submit', function (event) {
            event.preventDefault();
        });
    }

    confirmBtn.addEventListener('click', confirmImport);
    cancelBtn.addEventListener('click', cancelImport);

    updateCounts(summary.counts);
    loadRows();
})(window, document);
