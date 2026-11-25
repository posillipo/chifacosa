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
  // «Carica altri» nella timeline della Home: voci successive da /timeline_more.php (20 alla volta).
  var more = document.getElementById('acad-more'), feed = document.getElementById('acad-feed');
  if (more && feed) {
    more.addEventListener('click', function () {
      var offset = parseInt(more.getAttribute('data-offset') || '0', 10);
      more.disabled = true;
      var label = more.textContent; more.textContent = 'Caricamento…';
      fetch('/timeline_more.php?slug=' + encodeURIComponent(more.getAttribute('data-slug')) + '&offset=' + offset, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : { html: '', count: 0 }; })
        .then(function (d) {
          if (d.html) feed.insertAdjacentHTML('beforeend', d.html);
          offset += d.count || 0;
          more.setAttribute('data-offset', String(offset));
          if (!d.count || d.count < 20) { more.parentNode.removeChild(more); return; }
          more.disabled = false; more.textContent = label;
        })
        .catch(function () { more.disabled = false; more.textContent = label; });
    });
  }
})();
