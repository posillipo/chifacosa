// Studio — pagina Timeline: filtri della linea del tempo (il popup di inserimento è in studio-composer.js).
(function () {
  var $ = function (id) { return document.getElementById(id); };
  // Filtri della linea del tempo
  var filters = $('tlx-filters');
  if (filters) filters.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-f]'); if (!b) return;
    Array.prototype.forEach.call(filters.children, function (x) { x.classList.toggle('on', x === b); });
    var f = b.dataset.f, list = $('tl-posts-list');
    Array.prototype.forEach.call(list.querySelectorAll('.tlx-item'), function (it) { it.hidden = f !== 'all' && it.dataset.state !== f; });
    Array.prototype.forEach.call(list.querySelectorAll('.tlx-day'), function (d) {
      var n = d.nextElementSibling, any = false;
      while (n && !n.classList.contains('tlx-day')) { if (!n.hidden) any = true; n = n.nextElementSibling; }
      d.hidden = !any;
    });
  });

})();
