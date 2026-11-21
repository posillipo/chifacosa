// Studio — comportamento (JavaScript semplice, nessuna libreria).
(function () {
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function put(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  var toast = $('#st-toast'), tt;
  function say(msg) { toast.textContent = msg; toast.classList.add('show'); clearTimeout(tt); tt = setTimeout(function () { toast.classList.remove('show'); }, 2200); }

  // Menu: cassetto su telefono, riducibile su desktop
  var burger = $('#st-burger'), overlay = $('#st-overlay');
  function setOpen(o) { document.body.classList.toggle('st-open', o); if (burger) burger.setAttribute('aria-expanded', o ? 'true' : 'false'); }
  if (burger) burger.addEventListener('click', function () { setOpen(!document.body.classList.contains('st-open')); });
  if (overlay) overlay.addEventListener('click', function () { setOpen(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
  if (get('stCollapsed') === '1') document.body.classList.add('st-collapsed');
  var col = $('#st-collapse');
  if (col) col.addEventListener('click', function () { var c = document.body.classList.toggle('st-collapsed'); put('stCollapsed', c ? '1' : '0'); });

  // Ricerca profili nella barra in alto
  var sIn = $('#st-search-input'), sList = $('#st-search-list'), sT, sSeq = 0;
  if (sIn) {
    sIn.addEventListener('input', function () {
      clearTimeout(sT);
      sT = setTimeout(function () {
        var q = sIn.value.trim().replace(/^@/, ''), my = ++sSeq;
        if (q.length < 2) { sList.hidden = true; return; }
        fetch('/api_profile_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
          if (my !== sSeq) return;
          var rs = d.results || [];
          sList.innerHTML = rs.length ? rs.map(function (x) {
            return '<a class="st-hit" href="/dashboard_profile_view.php?slug=' + encodeURIComponent(x.slug) + '">'
              + (x.avatar ? '<img class="st-av" style="width:32px;height:32px" src="' + esc(x.avatar) + '" alt="">' : '<span class="st-av st-ph" style="width:32px;height:32px">' + esc((x.name || '?').charAt(0).toUpperCase()) + '</span>')
              + '<span><b>' + esc(x.name) + '</b><small>' + esc(x.sub) + '</small></span></a>';
          }).join('') : '<div class="st-muted" style="padding:10px">Nessun profilo trovato</div>';
          sList.hidden = false;
        }).catch(function () {});
      }, 250);
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('#st-search')) sList.hidden = true; });
  }

  // Finestre
  document.addEventListener('click', function (e) {
    var o = e.target.closest('[data-open]');
    if (o) { var d = document.getElementById(o.getAttribute('data-open')); if (d && d.showModal) d.showModal(); return; }
    if (e.target.closest('[data-close]')) { var dd = e.target.closest('dialog'); if (dd) dd.close(); return; }
    if (e.target.tagName === 'DIALOG') e.target.close();
    var sh = e.target.closest('[data-share]');
    if (sh) {
      var d2; try { d2 = JSON.parse(sh.getAttribute('data-share')); } catch (x) { return; }
      if (navigator.share) { navigator.share({ title: d2.t, url: d2.u }).catch(function () {}); }
      else if (navigator.clipboard) { navigator.clipboard.writeText(d2.u).then(function () { say('Link copiato'); }, function () { say(d2.u); }); }
      else { say(d2.u); }
    }
  });

  // Feed: schede, ricerca, vista e scorrimento infinito
  var feed = $('#st-feed');
  if (!feed) return;
  var tab = 'tutti', q = '', empty = $('#st-empty'), skel = $('#st-skel');
  function applyFilter(root) {
    var any = false;
    $$('.st-item', root || document).forEach(function (it) {
      var ok = (tab === 'tutti' || it.getAttribute('data-type') === tab) && (!q || (it.getAttribute('data-q') || '').indexOf(q) !== -1);
      it.hidden = !ok; if (ok) any = true;
    });
    return any;
  }
  function refresh() { var any = applyFilter(feed); empty.hidden = any; }
  $$('#st-tabs button').forEach(function (b) {
    b.addEventListener('click', function () {
      $$('#st-tabs button').forEach(function (x) { x.classList.toggle('active', x === b); });
      tab = b.getAttribute('data-tab'); refresh();
    });
  });
  var qIn = $('#st-q'), qT;
  if (qIn) qIn.addEventListener('input', function () { clearTimeout(qT); qT = setTimeout(function () { q = qIn.value.trim().toLowerCase(); refresh(); }, 250); });
  function setView(v) {
    feed.classList.toggle('card', v === 'card'); feed.classList.toggle('list', v !== 'card');
    $$('#st-view button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-view') === v); });
    put('stView', v);
  }
  $$('#st-view button').forEach(function (b) { b.addEventListener('click', function () { setView(b.getAttribute('data-view')); }); });
  setView(get('stView') === 'card' ? 'card' : 'list');

  var next = parseInt(feed.getAttribute('data-next'), 10) || 0, done = feed.getAttribute('data-done') === '1', loading = false;
  function more() {
    if (loading || done) return;
    loading = true; skel.hidden = false;
    fetch('/studio/feed_more.php?offset=' + next, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      skel.hidden = true;
      if (d.html) { feed.insertAdjacentHTML('beforeend', d.html); }
      next += d.count; if (d.count < 20) done = true;
      loading = false; refresh();
    }).catch(function () { loading = false; skel.hidden = true; });
  }
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (en) { if (en[0].isIntersecting) more(); }, { rootMargin: '300px' }).observe($('#st-sentinel'));
  }
})();
