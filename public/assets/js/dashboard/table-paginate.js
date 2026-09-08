// Client-side pagination for the lists that are fully rendered into the DOM
// (accounts, subsidy types, batches, the distributions log). Server-paginated
// lists build the same footer in PHP (components/table_footer.php) - this file
// keeps the client ones looking and behaving identically.
//
// Markup contract:
//   - wrapper: [data-table-paginate] [data-paginate-key="<key>"]
//              optional data-paginate-label="accounts" for the count text
//   - rows   : [data-paginate-row] inside the wrapper
//   - footer : [data-table-info="<key>"] and [data-table-paging="<key>"]
//              (components/table_footer.php, client mode)
//   - size   : optional <select data-paginate-size="<key>"> - value 0 means All
//
// Page filters (search boxes, the Filters panel) mark rows they exclude with
// data-filtered="out" and then call window.refreshTablePagination(key). They
// must not touch row.hidden - this file owns it.
(function (window, document) {
    var PAGE_WINDOW = 5;

    function wrapperFor(key) {
        return document.querySelector('[data-table-paginate][data-paginate-key="' + key + '"]');
    }

    function allRows(wrapper) {
        return Array.from(wrapper.querySelectorAll('[data-paginate-row]'));
    }

    // Optional built-in page search, for tables with no filter JS of their own.
    function searchTerm(key) {
        var input = document.querySelector('[data-paginate-search="' + key + '"]');
        return input ? input.value.trim().toLowerCase() : '';
    }

    function pageSize(key) {
        var select = document.querySelector('[data-paginate-size="' + key + '"]');
        if (!select) {
            return 25;
        }
        var size = parseInt(select.value, 10);
        return isNaN(size) ? 25 : size;
    }

    function pageLink(label, targetPage, ariaLabel) {
        var item = document.createElement('li');
        item.className = 'page-item';

        var link = document.createElement('a');
        link.className = 'page-link';
        link.href = '#';
        link.textContent = label;
        link.dataset.paginatePage = String(targetPage);
        if (ariaLabel) {
            link.setAttribute('aria-label', ariaLabel);
        }

        item.appendChild(link);
        return item;
    }

    function renderPaging(container, page, totalPages) {
        container.textContent = '';

        if (false) {
            return;
        }

        var list = document.createElement('ul');
        list.className = 'pagination pagination-sm m-0';

        var first = pageLink('«', 1, 'First page');
        var prev = pageLink('‹', page - 1, 'Previous page');
        if (page <= 1) {
            first.classList.add('disabled');
            prev.classList.add('disabled');
        }
        list.appendChild(first);
        list.appendChild(prev);

        var firstShown = Math.max(1, Math.min(page - Math.floor(PAGE_WINDOW / 2), totalPages - PAGE_WINDOW + 1));
        var lastShown = Math.min(totalPages, firstShown + PAGE_WINDOW - 1);

        for (var number = firstShown; number <= lastShown; number++) {
            var item = pageLink(String(number), number, null);
            if (number === page) {
                item.classList.add('active');
                item.querySelector('.page-link').setAttribute('aria-current', 'page');
            }
            list.appendChild(item);
        }

        var next = pageLink('›', page + 1, 'Next page');
        var last = pageLink('»', totalPages, 'Last page');
        if (page >= totalPages) {
            next.classList.add('disabled');
            last.classList.add('disabled');
        }
        list.appendChild(next);
        list.appendChild(last);

        container.appendChild(list);
    }

    function render(wrapper) {
        var key = wrapper.dataset.paginateKey;
        var label = wrapper.dataset.paginateLabel || 'entries';
        var rows = allRows(wrapper);
        var needle = searchTerm(key);
        var matching = rows.filter(function (row) {
            return row.dataset.filtered !== 'out'
                && (needle === '' || row.textContent.toLowerCase().indexOf(needle) !== -1);
        });

        var size = pageSize(key);
        var total = matching.length;
        var totalPages = size > 0 ? Math.max(1, Math.ceil(total / size)) : 1;
        var page = Math.min(Math.max(1, parseInt(wrapper.dataset.paginatePage, 10) || 1), totalPages);
        wrapper.dataset.paginatePage = String(page);

        var from = size > 0 ? (page - 1) * size : 0;
        var to = size > 0 ? Math.min(from + size, total) : total;

        rows.forEach(function (row) {
            row.hidden = true;
        });
        matching.slice(from, to).forEach(function (row) {
            row.hidden = false;
        });

        var info = document.querySelector('[data-table-info="' + key + '"]');
        if (info) {
            info.textContent = total === 0
                ? 'Showing 0 ' + label
                : 'Showing ' + (from + 1) + ' to ' + to + ' of ' + total + ' ' + label;
        }

        var paging = document.querySelector('[data-table-paging="' + key + '"]');
        if (paging) {
            renderPaging(paging, page, totalPages);
        }

        // Empty-state row owned by the page (e.g. the accounts "no matches" row).
        var emptyRow = wrapper.querySelector('[data-paginate-empty]');
        if (emptyRow) {
            emptyRow.hidden = total !== 0;
        }
    }

    function refresh(key, resetToFirstPage) {
        var wrapper = wrapperFor(key);
        if (!wrapper) {
            return;
        }
        if (resetToFirstPage) {
            wrapper.dataset.paginatePage = '1';
        }
        render(wrapper);
    }

    function initTablePagination(rootElement) {
        var root = rootElement instanceof HTMLElement ? rootElement : document;

        root.querySelectorAll('[data-table-paginate]').forEach(function (wrapper) {
            if (wrapper.dataset.paginateBound !== '1') {
                wrapper.dataset.paginateBound = '1';

                var key = wrapper.dataset.paginateKey;
                var paging = document.querySelector('[data-table-paging="' + key + '"]');
                if (paging) {
                    paging.addEventListener('click', function (event) {
                        var link = event.target.closest('[data-paginate-page]');
                        if (!link || link.closest('.page-item').classList.contains('disabled')) {
                            event.preventDefault();
                            return;
                        }
                        event.preventDefault();
                        wrapper.dataset.paginatePage = link.dataset.paginatePage;
                        render(wrapper);
                    });
                }

                var size = document.querySelector('[data-paginate-size="' + key + '"]');
                if (size) {
                    size.addEventListener('change', function () {
                        refresh(key, true);
                    });
                }

                var search = document.querySelector('[data-paginate-search="' + key + '"]');
                if (search) {
                    search.addEventListener('input', function () {
                        refresh(key, true);
                    });
                }
            }

            render(wrapper);
        });
    }

    window.initTablePagination = initTablePagination;
    window.refreshTablePagination = refresh;
    // For server-paged lists that still want this footer's markup (the Control
    // Numbers preview): render the links, then bind your own click handler and
    // read link.dataset.paginatePage.
    window.renderTablePaging = renderPaging;

    document.addEventListener('DOMContentLoaded', function () {
        initTablePagination(document);
    });
})(window, document);
