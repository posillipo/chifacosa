/* Coda Social (Studio): anteprima, sospendi/riattiva, rimuovi, riprogramma. Stessa API dell'originale. */
(function () {
  var apiUrl = '/api_social_queue_action.php';
  function apiPost(payload) {
    return fetch(apiUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }).then(function (r) { return r.json(); });
  }

  var dlg = document.getElementById('sq-dlg');
  var mediaBox = document.getElementById('sq-dlg-media');
  function fig(cap, el, cls) {
    var f = document.createElement('figure'); if (cls) f.className = cls;
    var c = document.createElement('figcaption'); c.textContent = cap; f.appendChild(c); f.appendChild(el); return f;
  }
  if (dlg && mediaBox) {
    document.querySelectorAll('.sq-prev').forEach(function (b) {
      b.addEventListener('click', function () {
        var d; try { d = JSON.parse(b.getAttribute('data-prev')); } catch (e) { return; }
        document.getElementById('sq-dlg-title').textContent = d.title || '';
        document.getElementById('sq-dlg-text').textContent = d.text || '';
        var meta = document.getElementById('sq-dlg-meta'); meta.textContent = '';
        (d.meta || []).forEach(function (m) { var s = document.createElement('span'); s.textContent = m; meta.appendChild(s); });
        mediaBox.textContent = '';
        var imgs = (d.images && d.images.length) ? d.images : (d.image ? [d.image] : []);
        imgs.forEach(function (u, n) { var i = document.createElement('img'); i.src = u; i.alt = ''; mediaBox.appendChild(fig(imgs.length > 1 ? 'Immagine ' + (n + 1) + ' di ' + imgs.length : 'Immagine', i)); });
        if (d.story) { var j = document.createElement('img'); j.src = d.story; j.alt = ''; mediaBox.appendChild(fig('Immagine Story', j, 'story')); }
        if (d.video) { var v = document.createElement('video'); v.src = d.video; v.controls = true; v.preload = 'metadata'; v.playsInline = true; mediaBox.appendChild(fig('Video', v)); }
        dlg.showModal();
      });
    });
    dlg.addEventListener('close', function () { mediaBox.querySelectorAll('video').forEach(function (v) { v.pause(); }); });
    var x = document.getElementById('sq-dlg-x'); if (x) x.addEventListener('click', function () { dlg.close(); });
    dlg.addEventListener('click', function (ev) { if (ev.target === dlg) dlg.close(); });
  }

  document.querySelectorAll('.sq-suspend-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = parseInt(btn.dataset.id, 10);
      btn.disabled = true;
      apiPost({ action: 'suspend', id: id }).then(function (data) {
        btn.disabled = false;
        if (!data.ok) { alert(data.error || 'Errore'); return; }
        btn.dataset.suspended = data.suspended ? '1' : '0';
        btn.querySelector('span').textContent = data.suspended ? 'Riattiva' : 'Sospendi';
        var ic = btn.querySelector('i'); if (ic) ic.className = data.suspended ? 'fa-solid fa-play' : 'fa-solid fa-pause';
        var row = document.getElementById('sq-row-' + id);
        var st = row && row.querySelector('.sq-status');
        if (st) {
          var names = { pending: 'In attesa', suspended: 'Sospeso' };
          st.textContent = names[data.status] || data.status;
          st.setAttribute('data-s', data.status);
        }
      }).catch(function () { btn.disabled = false; alert('Errore di connessione'); });
    });
  });

  document.querySelectorAll('.sq-delete-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('Rimuovere questo elemento dalla coda?')) return;
      var id = parseInt(btn.dataset.id, 10);
      btn.disabled = true;
      apiPost({ action: 'delete', id: id }).then(function (data) {
        if (!data.ok) { btn.disabled = false; alert(data.error || 'Errore'); return; }
        var row = document.getElementById('sq-row-' + id);
        if (row) row.remove();
      }).catch(function () { btn.disabled = false; alert('Errore di connessione'); });
    });
  });

  document.querySelectorAll('.sq-scheduled-input').forEach(function (input) {
    input.addEventListener('change', function () {
      var id = parseInt(input.dataset.id, 10);
      apiPost({ action: 'reschedule', id: id, scheduled_for: input.value || null }).then(function (data) {
        if (!data.ok) alert(data.error || 'Errore salvataggio data');
      });
    });
  });
})();
