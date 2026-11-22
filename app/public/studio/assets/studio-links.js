// Studio — pagina Link: aggiunta intelligente (incolla e riconosce), trascinamento, interruttore visibilità,
// modifica in popup e anteprima dal vivo della pagina pubblica.
(function () {
  var root = document.querySelector('.lkx');
  if (!root) return;
  var $ = function (id) { return document.getElementById(id); };
  var platforms = []; try { platforms = JSON.parse(root.getAttribute('data-platforms')) || []; } catch (e) {}
  var form = $('lkx-add'), q = $('lkx-q'), csrf = form.querySelector('input[name=csrf]').value;
  var list = $('lkx-list');

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var toastEl;
  function toast(msg) {
    if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'stc-toast'; toastEl.setAttribute('role', 'status'); document.body.appendChild(toastEl); }
    toastEl.textContent = msg; toastEl.classList.add('show');
    clearTimeout(toast.t); toast.t = setTimeout(function () { toastEl.classList.remove('show'); }, 2200);
  }
  function post(data) {
    var fd = new FormData(); fd.set('csrf', csrf);
    Object.keys(data).forEach(function (k) { fd.set(k, data[k]); });
    return fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
  }

  // ---------- Riconoscimento del link ----------
  var URLRE = /^(https?:\/\/)?([\w-]+\.)+[a-z]{2,}(:\d+)?([\/?#]\S*)?$/i;
  function normUrl(v) { v = v.trim(); return /^https?:\/\//i.test(v) ? v : 'https://' + v; }
  function detect(url) {
    var host = ''; try { host = new URL(url).hostname.toLowerCase().replace(/^www\./, ''); } catch (e) { return null; }
    for (var i = 0; i < platforms.length; i++) { var d = platforms[i].d; if (host === d || host.slice(-(d.length + 1)) === '.' + d) return platforms[i]; }
    return null;
  }

  // ---------- Barra di aggiunta ----------
  var sug = $('lkx-sug'), text = $('lkx-text'), tiles = $('lkx-tiles'), labelIn = $('lkx-sug-label'), siteRow = $('lkx-site-row'), site = $('lkx-site');
  var labelTouched = false, kind = '', mode = '';
  function setPlaceholder() { q.placeholder = kind === 'map' ? "Scrivi l'indirizzo, es. Via Roma 1, Napoli" : kind === 'divider' ? 'Scrivi il titolo della sezione, es. Per prenotare' : 'Incolla un link, scrivi un titolo o un indirizzo…'; }
  function refreshBar() {
    var v = q.value.trim(); $('lkx-clear').hidden = !v && !kind;
    sug.hidden = true; text.hidden = true; mode = '';
    tiles.hidden = !!v || !!kind;
    if (!v) { if (kind) showKind(false); return; }
    if (!kind && URLRE.test(v)) {
      mode = 'link';
      var url = normUrl(v), p = detect(url);
      $('lkx-sug-ic').innerHTML = '<i class="' + (p ? p.i : 'fa-solid fa-link') + '"></i>';
      $('lkx-sug-t').textContent = p ? p.l + ' riconosciuto: comparirà come icona in cima' : 'Link riconosciuto';
      if (!labelTouched) { try { labelIn.value = p ? p.l : new URL(url).hostname.replace(/^www\./, ''); } catch (e) { labelIn.value = ''; } }
      siteRow.hidden = !!p; if (p) site.checked = false;
      $('lkx-go').querySelector('span').textContent = 'Aggiungi';
      sug.hidden = false;
    } else if (kind) { showKind(true); }
    else { mode = 'text'; text.hidden = false; text.querySelectorAll('.lkx-opt').forEach(function (b) { b.hidden = false; }); text.querySelector('p').textContent = 'Non è un link. Cosa vuoi creare?'; }
  }
  function showKind(on) {
    text.hidden = !on; if (!on) return;
    text.querySelector('p').textContent = kind === 'map' ? "Scrivi l'indirizzo e premi Invio" : 'Scrivi il titolo e premi Invio';
    text.querySelectorAll('.lkx-opt').forEach(function (b) { b.hidden = b.getAttribute('data-kind') !== kind; });
    mode = 'text';
  }
  q.addEventListener('input', function () { labelTouched = false; refreshBar(); });
  labelIn.addEventListener('input', function () { labelTouched = true; });
  $('lkx-clear').addEventListener('click', function () { q.value = ''; kind = ''; labelTouched = false; setPlaceholder(); refreshBar(); q.focus(); });
  tiles.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    if (b.getAttribute('data-kind')) { kind = b.getAttribute('data-kind'); q.value = ''; setPlaceholder(); refreshBar(); q.focus(); return; }
    q.value = b.getAttribute('data-p'); labelTouched = false; refreshBar(); q.focus();
    try { q.setSelectionRange(q.value.length, q.value.length); } catch (x) {}
    if (b.getAttribute('data-site')) { setTimeout(function () { site.checked = true; }, 0); }
  });
  function submitAs(action, fields) {
    $('lkx-action').value = action;
    $('lkx-label-h').value = fields.label || ''; $('lkx-url-h').value = fields.url || ''; $('lkx-addr-h').value = fields.address || '';
    $('lkx-site-h').checked = !!fields.site;
    form.submit();
  }
  text.addEventListener('click', function (e) {
    var b = e.target.closest('.lkx-opt'); if (!b) return;
    var v = q.value.trim(); if (!v) { q.focus(); return; }
    if (b.getAttribute('data-kind') === 'divider') submitAs('add_divider', { label: v }); else submitAs('add_map', { address: v });
  });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var v = q.value.trim();
    if (mode === 'link') {
      var label = labelIn.value.trim() || 'Link';
      submitAs('add', { label: label, url: normUrl(v), site: site.checked && !siteRow.hidden });
    } else if (mode === 'text' && kind && v) {
      if (kind === 'divider') submitAs('add_divider', { label: v }); else submitAs('add_map', { address: v });
    } else if (!v) { q.focus(); }
  });

  // ---------- Elenco: stato, anteprima ----------
  function items() { return Array.prototype.slice.call(list.querySelectorAll('.lkx-item')); }
  function refresh() {
    var seen = {}, social = [], rows = [], active = 0;
    items().forEach(function (it) {
      var tag = it.querySelector('.lkx-tag'); if (tag) tag.remove();
      var type = it.getAttribute('data-type'), on = it.getAttribute('data-active') === '1';
      var row = { type: type, label: it.getAttribute('data-label'), icon: it.getAttribute('data-icon') };
      if (on) active++;
      if (!on) return;
      if (type === 'link') {
        var pk = it.getAttribute('data-pk');
        if (it.getAttribute('data-site') === '1' && !seen.website) { seen.website = 1; social.push(row); row.icon = 'fa-solid fa-globe'; markTop(it); return; }
        if (pk && !seen[pk]) { seen[pk] = 1; social.push(row); markTop(it); return; }
      }
      rows.push(row);
    });
    $('lkx-n').textContent = active; $('lkx-count').textContent = '(' + items().length + ')';
    var html = '<div class="lkx-p-head">' + (root.getAttribute('data-avatar') ? '<img src="' + esc(root.getAttribute('data-avatar')) + '" alt="">' : '<span>' + esc((root.getAttribute('data-profile') || 'C').charAt(0).toUpperCase()) + '</span>') + '<b>' + esc(root.getAttribute('data-profile')) + '</b><small>@' + esc(root.getAttribute('data-slug')) + '</small></div>';
    if (social.length) html += '<div class="lkx-p-soc">' + social.map(function (s) { return '<i class="' + esc(s.icon) + '"></i>'; }).join('') + '</div>';
    html += rows.map(function (r) {
      if (r.type === 'divider') return '<div class="lkx-p-div">' + esc(r.label) + '</div>';
      if (r.type === 'map') return '<div class="lkx-p-map"><i class="fa-solid fa-location-dot"></i>' + esc(r.label) + '</div>';
      return '<div class="lkx-p-btn">' + esc(r.label) + '</div>';
    }).join('');
    if (!social.length && !rows.length) html += '<p class="lkx-p-empty">I tuoi link compariranno qui.</p>';
    $('lkx-prev').innerHTML = html; if ($('lkx-pdlg').open) $('lkx-prev2').innerHTML = html;
  }
  function markTop(it) {
    var t = document.createElement('span'); t.className = 'lkx-tag'; t.title = 'Compare come icona in cima alla tua pagina';
    t.innerHTML = '<i class="fa-solid fa-arrow-up"></i> In alto'; it.insertBefore(t, it.querySelector('.lkx-act'));
  }

  list.addEventListener('change', function (e) {
    if (!e.target.classList.contains('lkx-toggle')) return;
    var it = e.target.closest('.lkx-item'), on = e.target.checked;
    it.setAttribute('data-active', on ? '1' : '0'); it.classList.toggle('off', !on); refresh();
    post({ action: 'toggle', id: it.getAttribute('data-id') }).then(function (r) { if (!r.ok) throw 0; toast(on ? 'Link visibile' : 'Link nascosto'); })
      .catch(function () { e.target.checked = !on; it.setAttribute('data-active', on ? '0' : '1'); it.classList.toggle('off', on); refresh(); toast('Non riuscito, riprova'); });
  });
  list.addEventListener('click', function (e) {
    var it = e.target.closest('.lkx-item'); if (!it) return;
    if (e.target.closest('.lkx-del')) {
      if (!confirm('Eliminare «' + it.getAttribute('data-label') + '»?')) return;
      it.classList.add('gone');
      post({ action: 'delete', id: it.getAttribute('data-id') }).then(function (r) { if (!r.ok) throw 0; it.remove(); refresh(); toast('Eliminato'); })
        .catch(function () { it.classList.remove('gone'); toast('Non riuscito, riprova'); });
    } else if (e.target.closest('.lkx-edit')) { openEdit(it); }
  });

  if (window.Sortable || true) {
    var tryInit = function () {
      if (!window.Sortable) { return setTimeout(tryInit, 100); }
      Sortable.create(list, {
        handle: '.lkx-grip', animation: 160, ghostClass: 'lkx-ghost', chosenClass: 'lkx-chosen', forceFallback: false,
        onEnd: function () {
          refresh();
          post({ action: 'reorder', ids: items().map(function (i) { return i.getAttribute('data-id'); }).join(',') })
            .then(function (r) { if (r.status !== 204 && !r.ok) throw 0; toast('Ordine salvato'); }).catch(function () { toast('Ordine non salvato, riprova'); });
        }
      });
    };
    tryInit();
  }

  // ---------- Modifica in popup ----------
  var dlg = $('lkx-dlg');
  function openEdit(it) {
    var type = it.getAttribute('data-type');
    $('lkx-d-action').value = type === 'divider' ? 'update_divider' : type === 'map' ? 'update_map' : 'update_link';
    $('lkx-d-id').value = it.getAttribute('data-id');
    $('lkx-dlg-t').textContent = type === 'divider' ? 'Modifica separatore' : type === 'map' ? 'Modifica mappa' : 'Modifica link';
    $('lkx-d-label').value = it.getAttribute('data-label');
    dlg.querySelectorAll('[data-t]').forEach(function (s) { s.hidden = s.getAttribute('data-t') !== type; });
    var url = $('lkx-d-url'); url.value = it.getAttribute('data-url'); url.required = type === 'link'; url.disabled = type !== 'link';
    $('lkx-d-site').checked = it.getAttribute('data-site') === '1'; $('lkx-d-site').disabled = type !== 'link';
    var cov = it.getAttribute('data-cover'), im = $('lkx-d-cover'); im.hidden = !cov; if (cov) im.src = '/' + cov;
    $('lkx-d-addr').value = ''; $('lkx-d-lat').value = ''; $('lkx-d-lng').value = '';
    dlg.showModal();
  }
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-lkx-close]')) { var d = e.target.closest('dialog'); if (d) d.close(); }
  });
  [dlg, $('lkx-pdlg')].forEach(function (d) { d.addEventListener('click', function (e) { if (e.target === d) d.close(); }); });
  $('lkx-prev-open').addEventListener('click', function () { $('lkx-pdlg').showModal(); refresh(); });

  refresh();
})();
