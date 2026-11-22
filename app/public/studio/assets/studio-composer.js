// Studio — popup "Crea aggiornamento": foto (trascina, incolla, rimuovi), pannello opzioni, programmazione, AI.
(function () {
  var dlg = document.getElementById('cmp'), form = document.getElementById('tlx-form');
  if (!dlg || !form) return;
  var $ = function (id) { return document.getElementById(id); };
  var text = $('ai-testo'), input = $('post-image-input'), strip = $('tlx-photos'), thumbData = $('post-image-thumb-data');
  var files = [], panels = dlg.querySelectorAll('.cmp-panel');

  function show(name) {
    Array.prototype.forEach.call(panels, function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
    summary();
  }
  function openDlg() { show('main'); if (!dlg.open) dlg.showModal(); document.body.classList.add('cmp-lock'); setTimeout(function () { text.focus(); }, 50); }
  function closeDlg() { if (dlg.open) dlg.close(); }
  dlg.addEventListener('close', function () { document.body.classList.remove('cmp-lock'); });
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-cmp-open]')) openDlg();
    else if (e.target.closest('[data-cmp-close]')) closeDlg();
    else if (e.target.closest('[data-cmp-opts]')) show('opts');
    else if (e.target.closest('[data-cmp-back]')) show('main');
  });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) closeDlg(); });
  if (dlg.getAttribute('data-open') === '1') openDlg();

  function autoGrow() { text.style.height = 'auto'; text.style.height = Math.min(text.scrollHeight, 260) + 'px'; }
  function sync() { var dt = new DataTransfer(); files.forEach(function (f) { dt.items.add(f); }); input.files = dt.files; }

  // Miniatura JPEG leggera della prima foto (come nella pagina originale)
  function makeThumb() {
    thumbData.value = '';
    if (!files[0]) return;
    var img = new Image(), r = new FileReader();
    r.onload = function (e) {
      img.onload = function () {
        var s = Math.min(1, 600 / Math.max(img.width, img.height)), c = document.createElement('canvas');
        c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        thumbData.value = c.toDataURL('image/jpeg', 0.82);
      };
      img.src = e.target.result;
    };
    r.readAsDataURL(files[0]);
  }
  function renderPhotos() {
    strip.innerHTML = ''; strip.hidden = !files.length;
    files.forEach(function (f, i) {
      var d = document.createElement('div'); d.className = 'tlx-ph';
      var im = document.createElement('img'); im.alt = ''; im.src = URL.createObjectURL(f);
      var x = document.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', 'Rimuovi foto'); x.innerHTML = '&times;';
      x.addEventListener('click', function () { files.splice(i, 1); sync(); renderPhotos(); makeThumb(); });
      d.appendChild(im); d.appendChild(x);
      if (i === 0) { var b = document.createElement('span'); b.textContent = 'Copertina'; d.appendChild(b); }
      strip.appendChild(d);
    });
  }
  function addFiles(list) {
    Array.prototype.forEach.call(list, function (f) { if (f.type.indexOf('image/') === 0 && files.length < 10) files.push(f); });
    sync(); renderPhotos(); makeThumb();
  }
  input.addEventListener('change', function () { files = Array.prototype.slice.call(input.files); renderPhotos(); makeThumb(); });
  dlg.addEventListener('dragover', function (e) { e.preventDefault(); dlg.classList.add('over'); });
  dlg.addEventListener('dragleave', function () { dlg.classList.remove('over'); });
  dlg.addEventListener('drop', function (e) { e.preventDefault(); dlg.classList.remove('over'); if (e.dataTransfer && e.dataTransfer.files.length) addFiles(e.dataTransfer.files); });
  text.addEventListener('paste', function (e) { var f = e.clipboardData && e.clipboardData.files; if (f && f.length) addFiles(f); });

  // Riepilogo delle scelte (etichetta privacy, programmazione, Feed, link) e testo del pulsante
  function summary() {
    var vis = form.querySelector('[name=visibility]:checked').value;
    var later = form.querySelector('[name=when]:checked').value === 'later' && $('tlx-publish-at').value;
    var feed = form.querySelector('[name=in_feed]').checked, guid = $('tlx-guid').value.trim();
    var pill = $('cmp-vis');
    pill.querySelector('span').textContent = vis === 'private' ? 'Solo io' : 'Pubblico';
    pill.querySelector('i').className = vis === 'private' ? 'fa-solid fa-lock' : 'fa-solid fa-earth-europe';
    var chips = [];
    if (later) chips.push('<i class="fa-regular fa-clock"></i> ' + later.replace('T', ' '));
    if (!feed) chips.push('Fuori dal Feed');
    if (guid) chips.push('<i class="fa-solid fa-link"></i> Link personalizzato');
    var sum = $('cmp-sum'); sum.hidden = !chips.length;
    sum.innerHTML = chips.map(function (c) { return '<span>' + c + '</span>'; }).join('');
    $('tlx-submit').querySelector('span').textContent = later ? 'Programma' : vis === 'private' ? 'Salva per me' : 'Pubblica';
  }
  text.addEventListener('input', function () { $('tlx-count').textContent = text.value.length; autoGrow(); });
  form.addEventListener('change', function (e) {
    if (e.target.name === 'when') {
      var later = e.target.value === 'later', pa = $('tlx-publish-at');
      $('tlx-when').hidden = !later; pa.disabled = !later;
      if (later && !pa.value) {
        var d = new Date(Date.now() + 3600e3); d.setMinutes(0, 0, 0);
        pa.value = new Date(d - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
      }
    }
    summary();
  });
  $('tlx-publish-at').disabled = true;
  var tz = form.querySelector('[name=tz_offset_minutes]'); if (tz) tz.value = new Date().getTimezoneOffset();
  form.addEventListener('submit', function () { $('tlx-submit').classList.add('busy'); });

  // AI (stesso servizio della pagina originale)
  var panel = $('ai-caption-panel'), st = $('ai-caption-status'), gen = $('ai-caption-generate');
  $('ai-caption-toggle').addEventListener('click', function () { panel.hidden = !panel.hidden; if (!panel.hidden) $('ai-caption-keywords').focus(); });
  $('ai-caption-cancel').addEventListener('click', function () { panel.hidden = true; st.textContent = ''; });
  gen.addEventListener('click', function () {
    var k = $('ai-caption-keywords').value.trim();
    if (!k) { st.textContent = 'Scrivi almeno qualche parola chiave.'; return; }
    gen.disabled = true; st.textContent = 'Generazione in corso...';
    var body = new URLSearchParams(); body.set('csrf', form.querySelector('input[name=csrf]').value); body.set('keywords', k);
    fetch('/dashboard_ai_caption.php', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (d) {
      gen.disabled = false;
      if (d.ok) { text.value = d.text; text.dispatchEvent(new Event('input')); st.textContent = 'Fatto! Puoi modificare il testo prima di pubblicare.'; }
      else { st.textContent = d.error || 'Qualcosa è andato storto.'; }
    }).catch(function () { gen.disabled = false; st.textContent = 'Errore di connessione. Riprova.'; });
  });
  $('tlx-count').textContent = text.value.length; autoGrow(); summary();
})();
