// Studio — comportamenti condivisi dalle pagine di gestione: popup con compilazione automatica,
// "scrivi con l'AI", anteprima foto, interruttori e riordino che salvano subito, filtri.
(function () {
  var csrfEl = document.querySelector('input[name=csrf]');
  var csrf = csrfEl ? csrfEl.value : '';
  var toastEl;
  function toast(msg) {
    if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'stc-toast'; toastEl.setAttribute('role', 'status'); document.body.appendChild(toastEl); }
    toastEl.textContent = msg; toastEl.classList.add('show');
    clearTimeout(toast.t); toast.t = setTimeout(function () { toastEl.classList.remove('show'); }, 2000);
  }
  function post(data) {
    var fd = new FormData(); fd.set('csrf', csrf);
    Object.keys(data).forEach(function (k) { fd.set(k, data[k]); });
    return fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
  }
  window.studioKit = { toast: toast, post: post };

  // ---------- Popup: apri / chiudi / compila ----------
  function fillForm(form, data) {
    Object.keys(data).forEach(function (k) {
      var els = form.querySelectorAll('[name="' + k + '"]'); if (!els.length) return;
      var v = data[k];
      Array.prototype.forEach.call(els, function (el) {
        if (el.type === 'radio') { el.checked = String(el.value) === String(v); }
        else if (el.type === 'checkbox') {
          if (Array.isArray(v)) el.checked = v.map(String).indexOf(el.value) >= 0; else el.checked = !!Number(v) || v === true;
        } else if (el.type !== 'file') { el.value = v === null ? '' : v; }
      });
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-dlg]');
    if (b) {
      var dlg = document.getElementById(b.getAttribute('data-dlg')); if (!dlg) return;
      var form = dlg.querySelector('form'); form.reset();
      dlg.querySelectorAll('.ai-panel').forEach(function (p) { p.hidden = true; });
      dlg.querySelectorAll('[data-preview-box]').forEach(function (p) { p.innerHTML = ''; p.hidden = true; });
      var raw = b.getAttribute('data-fill'), data = null; try { data = raw ? JSON.parse(raw) : null; } catch (x) {}
      form.querySelector('[name=action]').value = dlg.getAttribute(data ? 'data-action-edit' : 'data-action-new');
      form.querySelector('[name=id]').value = data && data.id ? data.id : '';
      dlg.querySelector('.cmp-head h2').textContent = dlg.getAttribute(data ? 'data-title-edit' : 'data-title-new');
      form.querySelectorAll('[data-req-new]').forEach(function (el) { el.required = !data; });
      if (data) fillForm(form, data);
      var pre = b.getAttribute('data-preset'); if (pre) { try { fillForm(form, JSON.parse(pre)); } catch (x) {} }
      var tz = form.querySelector('[name=tz_offset_minutes]'); if (tz) tz.value = new Date().getTimezoneOffset();
      dlg.dispatchEvent(new CustomEvent('kit:open', { detail: data }));
      dlg.showModal(); return;
    }
    if (e.target.closest('[data-kit-close]')) { var d = e.target.closest('dialog'); if (d) d.close(); }
  });
  document.querySelectorAll('dialog.kit-dlg').forEach(function (d) { d.addEventListener('click', function (e) { if (e.target === d) d.close(); }); });

  // Moduli con data-autosubmit: si inviano appena cambia un interruttore (es. impostazioni a un solo campo)
  document.addEventListener('change', function (e) { var f = e.target.closest('form[data-autosubmit]'); if (f) f.submit(); });

  // ---------- "Scrivi con l'AI" (stesso servizio delle altre pagine) ----------
  document.addEventListener('click', function (e) {
    var box = e.target.closest('.kit-ai'); if (!box) return;
    var panel = box.querySelector('.ai-panel'), st = box.querySelector('.ai-status');
    var form = box.closest('form');
    if (e.target.closest('.ai-toggle')) { panel.hidden = !panel.hidden; if (!panel.hidden) box.querySelector('.ai-keywords').focus(); }
    else if (e.target.closest('.ai-cancel')) { panel.hidden = true; st.textContent = ''; }
    else if (e.target.closest('.ai-generate')) {
      var btn = e.target.closest('.ai-generate'), k = box.querySelector('.ai-keywords').value.trim();
      if (!k) { st.textContent = 'Scrivi almeno qualche parola chiave.'; return; }
      var target = form.querySelector('[data-ai-target]');
      btn.disabled = true; st.textContent = 'Generazione in corso...';
      var body = new URLSearchParams(); body.set('csrf', csrf); body.set('keywords', k);
      fetch('/dashboard_ai_caption.php', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (d) {
        btn.disabled = false;
        if (d.ok) { target.value = d.text; st.textContent = 'Fatto! Puoi modificare il testo prima di salvare.'; } else { st.textContent = d.error || 'Qualcosa è andato storto.'; }
      }).catch(function () { btn.disabled = false; st.textContent = 'Errore di connessione. Riprova.'; });
    }
  });

  // ---------- Anteprima delle foto scelte ----------
  document.addEventListener('change', function (e) {
    var inp = e.target.closest('input[type=file][data-preview]'); if (!inp) return;
    var box = inp.closest('form').querySelector(inp.getAttribute('data-preview')); if (!box) return;
    box.innerHTML = ''; var files = Array.prototype.slice.call(inp.files || []);
    box.hidden = !files.length;
    files.forEach(function (f, i) {
      var d = document.createElement('div'); d.className = 'tlx-ph';
      var im = document.createElement('img'); im.alt = ''; im.src = URL.createObjectURL(f); d.appendChild(im);
      if (i === 0) { var s = document.createElement('span'); s.textContent = 'Copertina'; d.appendChild(s); }
      box.appendChild(d);
    });
  });

  // ---------- Interruttori che salvano subito: input[data-post='{"action":"..","id":3}'] ----------
  document.addEventListener('change', function (e) {
    var inp = e.target.closest('input[data-post]'); if (!inp) return;
    var data; try { data = JSON.parse(inp.getAttribute('data-post')); } catch (x) { return; }
    var on = inp.checked, row = inp.closest('[data-row]');
    if (row) { row.classList.toggle('off', !on); row.setAttribute('data-on', on ? '1' : '0'); }
    post(data).then(function (r) { if (!r.ok) throw 0; toast(on ? (inp.getAttribute('data-msg-on') || 'Attivato') : (inp.getAttribute('data-msg-off') || 'Disattivato')); })
      .catch(function () { inp.checked = !on; if (row) { row.classList.toggle('off', on); row.setAttribute('data-on', on ? '0' : '1'); } toast('Non riuscito, riprova'); });
  });

  // ---------- Riordino per trascinamento: [data-sortable data-reorder='azione' data-extra='{..}' data-handle='.grip'] ----------
  function initSortable() {
    var lists = document.querySelectorAll('[data-sortable]'); if (!lists.length) return;
    if (!window.Sortable) { return setTimeout(initSortable, 100); }
    lists.forEach(function (list) {
      var opts = {
        handle: list.getAttribute('data-handle') || '.kit-grip', animation: 160, ghostClass: 'lkx-ghost', chosenClass: 'lkx-chosen',
        onEnd: function () {
          var ids = Array.prototype.map.call(list.querySelectorAll(':scope > [data-id]'), function (el) { return el.getAttribute('data-id'); });
          var data = { action: list.getAttribute('data-sortable'), ids: ids.join(',') };
          try { Object.assign(data, JSON.parse(list.getAttribute('data-extra') || '{}')); } catch (x) {}
          post(data).then(function (r) { if (r.status !== 204 && !r.ok) throw 0; toast('Ordine salvato'); }).catch(function () { toast('Ordine non salvato, riprova'); });
        }
      };
      if (list.getAttribute('data-draggable')) opts.draggable = list.getAttribute('data-draggable');
      Sortable.create(list, opts);
    });
  }
  initSortable();

  // ---------- Filtri a chip: [data-filters="#lista"] button[data-f] ; elementi [data-state="a b"] ----------
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-filters] button[data-f]'); if (!b) return;
    var bar = b.closest('[data-filters]'), list = document.querySelector(bar.getAttribute('data-filters')); if (!list) return;
    Array.prototype.forEach.call(bar.querySelectorAll('button'), function (x) { x.classList.toggle('on', x === b); });
    var f = b.getAttribute('data-f'), n = 0;
    list.querySelectorAll('[data-state]').forEach(function (it) {
      var show = f === 'all' || (' ' + it.getAttribute('data-state') + ' ').indexOf(' ' + f + ' ') >= 0; it.hidden = !show; if (show) n++;
    });
    var empty = list.parentNode.querySelector('[data-filter-empty]'); if (empty) empty.hidden = n > 0;
  });
})();
