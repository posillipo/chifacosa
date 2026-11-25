/* Tema "Academic": tema chiaro/scuro (ricordato sul dispositivo) e menu a scomparsa su schermi piccoli. */
(function () {
  'use strict';
  var root = document.documentElement;
  root.classList.remove('no-js');
  var themeBtn = document.getElementById('acad-theme');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var dark = root.getAttribute('data-theme') !== 'dark';
      if (dark) { root.setAttribute('data-theme', 'dark'); root.setAttribute('data-bs-theme', 'dark'); } else { root.removeAttribute('data-theme'); root.setAttribute('data-bs-theme', 'light'); }
      try { localStorage.setItem('acadTheme', dark ? 'dark' : 'light'); } catch (e) {}
    });
  }
  var burger = document.getElementById('acad-burger'), nav = document.getElementById('acad-nav');
  if (burger && nav) {
    burger.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }
})();
