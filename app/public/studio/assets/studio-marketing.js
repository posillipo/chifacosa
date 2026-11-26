/* Studio · Marketing: ricerca e ordinamento dei progetti, barre di avanzamento (larghezze da data-w). */
(function () {
  'use strict';
  document.querySelectorAll('.mkx-bar i[data-w]').forEach(function (i) { i.style.width = (parseInt(i.getAttribute('data-w'), 10) || 0) + '%'; });
  var list = document.getElementById('mkt-list');
  var items = list ? Array.prototype.slice.call(list.querySelectorAll('.mkx-proj')) : [];
  var q = document.getElementById('mkt-search');
  if (q) q.addEventListener('input', function () {
    var t = q.value.trim().toLowerCase();
    items.forEach(function (d) { d.hidden = !(!t || (d.getAttribute('data-title') || '').indexOf(t) !== -1); });
  });
  var sel = document.getElementById('mkt-sort');
  if (sel && items.length) {
    var apply = function () {
      var v = sel.value, dir = /_desc$/.test(v) ? -1 : 1, byName = /^name/.test(v);
      items.slice().sort(function (a, b) {
        if (byName) return dir * (a.getAttribute('data-name') || '').localeCompare(b.getAttribute('data-name') || '', 'it', { sensitivity: 'base' });
        return dir * (((+a.getAttribute('data-date')) - (+b.getAttribute('data-date'))) || ((+a.getAttribute('data-id')) - (+b.getAttribute('data-id'))));
      }).forEach(function (d) { list.appendChild(d); });
    };
    try { var saved = localStorage.getItem('mktSort'); if (saved && sel.querySelector('option[value="' + saved + '"]')) sel.value = saved; } catch (e) {}
    sel.addEventListener('change', function () { try { localStorage.setItem('mktSort', sel.value); } catch (e) {} apply(); });
    apply();
  }
})();
