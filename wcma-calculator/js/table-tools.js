/**
 * Lightweight client-side search/sort for the data tables on account.php
 * and admin.php. No build step, so this is a plain global (WcmaTableTools)
 * rather than an ES module.
 */
(function () {
    function dataRows(table) {
        return Array.from(table.tBodies[0].rows).filter(function (row) {
            return !row.classList.contains('empty-row') && row.cells.length > 1;
        });
    }

    function enableSearch(input, table) {
        if (!input || !table) return;
        const rows = dataRows(table);
        const noResults = table.parentElement.querySelector('.no-results-message');

        input.addEventListener('input', function () {
            const q = input.value.trim().toLowerCase();
            let visibleCount = 0;
            rows.forEach(function (row) {
                const match = !q || row.textContent.toLowerCase().indexOf(q) !== -1;
                row.hidden = !match;
                if (match) visibleCount++;
            });
            if (noResults) noResults.hidden = visibleCount !== 0;
        });
    }

    function sortValue(cell, type) {
        const raw = cell.dataset.sortValue !== undefined ? cell.dataset.sortValue : cell.textContent.trim();
        if (type === 'number') return parseFloat(raw) || 0;
        if (type === 'date') {
            // MySQL datetimes ("2026-09-21 14:00:00") aren't reliably parsed
            // by Date.parse across browsers without a 'T' separator.
            const t = Date.parse(raw.replace(' ', 'T'));
            return isNaN(t) ? 0 : t;
        }
        return raw.toLowerCase();
    }

    function enableSort(table) {
        if (!table) return;
        const headers = Array.from(table.querySelectorAll('th[data-sort]'));
        if (!headers.length) return;

        headers.forEach(function (th) {
            th.classList.add('sortable');
            th.addEventListener('click', function () {
                const dir = th.dataset.sortDir === 'asc' ? 'desc' : 'asc';
                headers.forEach(function (h) {
                    delete h.dataset.sortDir;
                    h.classList.remove('sort-asc', 'sort-desc');
                });
                th.dataset.sortDir = dir;
                th.classList.add(dir === 'asc' ? 'sort-asc' : 'sort-desc');

                const idx = Array.prototype.indexOf.call(th.parentNode.children, th);
                const type = th.dataset.sortType || 'text';
                const tbody = table.tBodies[0];
                const rows = dataRows(table);

                rows.sort(function (a, b) {
                    const av = sortValue(a.cells[idx], type);
                    const bv = sortValue(b.cells[idx], type);
                    if (av < bv) return dir === 'asc' ? -1 : 1;
                    if (av > bv) return dir === 'asc' ? 1 : -1;
                    return 0;
                });

                rows.forEach(function (row) { tbody.appendChild(row); });
            });
        });
    }

    window.WcmaTableTools = { enableSearch: enableSearch, enableSort: enableSort };
})();
