/* Barra superiore admin: i tasti Social / Automazioni / Marketing aprono la barra secondaria con le loro voci. */
(function () {
  'use strict';
  var bar = document.getElementById('dash-subbar');
  if (!bar) return;
  var buttons = document.querySelectorAll('button.dash-topgroup');
  var navs = bar.querySelectorAll('.dash-subnav');
  function show(group) {
    buttons.forEach(function (b) {
      var on = b.getAttribute('data-group') === group;
      b.classList.toggle('on', on);
      b.setAttribute('aria-expanded', on ? 'true' : 'false');
    });
    navs.forEach(function (n) { n.hidden = n.getAttribute('data-group') !== group; });
    bar.hidden = !group;
    document.body.classList.toggle('has-subbar', !!group);
    var row = document.getElementById('dash-adminrow');
    if (row) { row.classList.toggle('has-open', !!group); }
  }
  buttons.forEach(function (b) {
    b.addEventListener('click', function () {
      var g = b.getAttribute('data-group');
      show(b.classList.contains('on') ? '' : g);
    });
  });
})();
