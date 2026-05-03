/**
 * Generic client-side table sorter.
 *
 * Usage:
 *   Add data-sort-col="<attr>" and data-sort-type="priority|status|date|text"
 *   to a <button> inside a <th>.  The button must be inside a <table>.
 *
 *   Each sortable <tr> must carry the corresponding data attribute, e.g.
 *     data-priority="high"  data-status="todo"  data-due="2025-06-01"
 *
 *   A data-created-at="<ISO timestamp>" on rows is used as a tiebreaker
 *   (newest first).
 */
(function () {
  'use strict';

  var PRIORITY_RANK = { critical: 4, high: 3, medium: 2, low: 1 };
  var STATUS_RANK   = { todo: 1, doing: 2, done: 3 };

  function getValue(row, attr, type) {
    var v = (row.dataset[attr] || '').toLowerCase().trim();
    if (type === 'priority') return PRIORITY_RANK[v] != null ? PRIORITY_RANK[v] : 2;
    if (type === 'status')   return STATUS_RANK[v]   != null ? STATUS_RANK[v]   : 1;
    if (type === 'date')     return Date.parse(v) || 0;
    return v; // text – localeCompare below
  }

  function compareRows(a, b, attr, type, dir) {
    var av = getValue(a, attr, type);
    var bv = getValue(b, attr, type);
    var result;

    if (typeof av === 'string') {
      result = av.localeCompare(bv);
    } else {
      result = av - bv;
    }

    // Tiebreaker: newest created-at first
    if (result === 0) {
      var at = Date.parse(a.dataset.createdAt || '') || 0;
      var bt = Date.parse(b.dataset.createdAt || '') || 0;
      result = bt - at;
    }

    return dir === 'asc' ? result : -result;
  }

  function sortTable(table, attr, type, dir) {
    var tbody = table.querySelector('tbody');
    if (!tbody) return;

    var rows = Array.from(tbody.querySelectorAll('tr'));
    var dataRows  = rows.filter(function (r) { return !r.querySelector('td[colspan]'); });
    var emptyRows = rows.filter(function (r) {  return  r.querySelector('td[colspan]'); });

    dataRows.sort(function (a, b) { return compareRows(a, b, attr, type, dir); });

    tbody.innerHTML = '';
    dataRows.forEach(function (r) { tbody.appendChild(r); });
    emptyRows.forEach(function (r) { tbody.appendChild(r); });
  }

  function applySort(table, btn, attr, type, dir) {
    // Reset indicators on all sortable buttons in this table
    table.querySelectorAll('button[data-sort-col]').forEach(function (b) {
      b.removeAttribute('aria-sort');
      var ind = b.querySelector('.sort-indicator');
      if (ind) ind.remove();
    });

    btn.setAttribute('aria-sort', dir === 'desc' ? 'descending' : 'ascending');
    var indicator = document.createElement('span');
    indicator.className = 'sort-indicator';
    indicator.setAttribute('aria-hidden', 'true');
    indicator.textContent = dir === 'desc' ? ' \u25bc' : ' \u25b2';
    btn.appendChild(indicator);

    sortTable(table, attr, type, dir);
  }

  document.querySelectorAll('button[data-sort-col]').forEach(function (btn) {
    var table = btn.closest('table');
    if (!table) return;
    var dir = null;

    // Apply default sort if this button matches the table's default sort column
    var defaultCol = table.dataset.defaultSortCol;
    var defaultDir = table.dataset.defaultSortDir || 'desc';
    if (defaultCol && btn.dataset.sortCol === defaultCol) {
      dir = defaultDir;
      applySort(table, btn, defaultCol, btn.dataset.sortType || 'text', dir);
    }

    btn.addEventListener('click', function () {
      var attr = btn.dataset.sortCol;
      var type = btn.dataset.sortType || 'text';
      dir = (dir === 'desc') ? 'asc' : 'desc';

      applySort(table, btn, attr, type, dir);
    });
  });
})();
