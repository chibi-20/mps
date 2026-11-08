(() => {
  'use strict';

  const PAGE_SIZES = [10, 25, 50, 100];
  const states = new WeakMap();

  function rowsFor(table) {
    return Array.from(table.rows).filter((row) => !row.closest('thead, tfoot'));
  }

  function getState(table) {
    let state = states.get(table);
    if (state) return state;

    const responsiveWrap = table.closest('.table-responsive');
    const insertionPoint = responsiveWrap || table;
    const footer = document.createElement('div');
    footer.className = 'table-pagination d-flex align-items-center justify-content-between flex-wrap gap-2 px-3 py-2 border-top';
    footer.setAttribute('aria-label', 'Table pagination');
    insertionPoint.insertAdjacentElement('afterend', footer);

    state = { table, footer, page: 1, size: 10 };
    states.set(table, state);
    footer.addEventListener('click', (event) => {
      const button = event.target.closest('[data-page-action]');
      if (!button || button.disabled) return;
      state.page = Number(button.dataset.pageAction);
      render(state);
    });
    footer.addEventListener('change', (event) => {
      if (!event.target.matches('[data-page-size]')) return;
      state.size = Number(event.target.value) || 10;
      state.page = 1;
      render(state);
    });
    return state;
  }

  function render(state) {
    const allRows = rowsFor(state.table);
    const filteredRows = allRows.filter((row) => !row.hidden && row.style.display !== 'none');
    const placeholderOnly = allRows.length === 0 || (allRows.length === 1 && allRows[0].querySelector('td[colspan]'));
    if (placeholderOnly) {
      state.footer.classList.add('d-none');
      allRows.forEach((row) => row.classList.remove('table-page-hidden'));
      return;
    }
    state.footer.classList.remove('d-none');
    const pageCount = Math.max(1, Math.ceil(filteredRows.length / state.size));
    state.page = Math.min(state.page, pageCount);
    const startIndex = (state.page - 1) * state.size;
    const endIndex = Math.min(startIndex + state.size, filteredRows.length);
    const pageRows = new Set(filteredRows.slice(startIndex, endIndex));

    allRows.forEach((row) => {
      row.classList.toggle('table-page-hidden', filteredRows.includes(row) && !pageRows.has(row));
    });

    const first = filteredRows.length ? startIndex + 1 : 0;
    const last = filteredRows.length ? endIndex : 0;
    const pages = [];
    const firstPage = Math.max(1, state.page - 2);
    const lastPage = Math.min(pageCount, firstPage + 4);
    for (let page = firstPage; page <= lastPage; page += 1) pages.push(page);

    state.footer.innerHTML = `
      <div class="d-flex align-items-center flex-wrap gap-2">
        <span class="small text-muted">Showing ${first} to ${last} of ${filteredRows.length} rows</span>
        <label class="small text-muted d-flex align-items-center gap-1 mb-0">
          Rows
          <select class="form-select form-select-sm table-page-size" data-page-size aria-label="Rows per page">
            ${PAGE_SIZES.map((size) => `<option value="${size}" ${state.size === size ? 'selected' : ''}>${size}</option>`).join('')}
          </select>
        </label>
      </div>
      <nav aria-label="Table pages"><ul class="pagination pagination-sm mb-0">
        <li class="page-item ${state.page === 1 ? 'disabled' : ''}"><button class="page-link" type="button" data-page-action="${state.page - 1}" ${state.page === 1 ? 'disabled' : ''} aria-label="Previous page">&laquo;</button></li>
        ${pages.map((page) => `<li class="page-item ${page === state.page ? 'active' : ''}"><button class="page-link" type="button" data-page-action="${page}" ${page === state.page ? 'aria-current="page"' : ''}>${page}</button></li>`).join('')}
        <li class="page-item ${state.page === pageCount ? 'disabled' : ''}"><button class="page-link" type="button" data-page-action="${state.page + 1}" ${state.page === pageCount ? 'disabled' : ''} aria-label="Next page">&raquo;</button></li>
      </ul></nav>`;
  }

  function enhance(table) {
    if (!(table instanceof HTMLTableElement) || table.closest('.tabulator') || table.dataset.pagination === 'false') return;
    const state = getState(table);
    render(state);
  }

  function refreshAll() {
    document.querySelectorAll('table').forEach(enhance);
  }

  function reset(table) {
    if (!(table instanceof HTMLTableElement)) return;
    const state = states.get(table);
    if (state) {
      state.page = 1;
      render(state);
    } else {
      enhance(table);
    }
  }

  window.MpsTablePagination = { refresh: refreshAll, reset };
  document.addEventListener('DOMContentLoaded', refreshAll);

  let refreshQueued = false;
  const observer = new MutationObserver((records) => {
    let changed = false;
    records.forEach((record) => {
      const targetTable = record.target instanceof Element ? record.target.closest('table') : null;
      if (targetTable && !targetTable.closest('.tabulator')) changed = true;
      record.addedNodes.forEach((node) => {
        if (!(node instanceof Element)) return;
        if (node.matches('table') || node.querySelector('table')) changed = true;
      });
    });
    if (changed && !refreshQueued) {
      refreshQueued = true;
      queueMicrotask(() => {
        refreshQueued = false;
        refreshAll();
      });
    }
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });
})();
