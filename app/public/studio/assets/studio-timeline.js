// Studio — pagina Timeline: anteprima dal vivo, foto con trascina e rilascia, programmazione, filtri, AI.
(function () {
  var form = document.getElementById('tlx-form');
  if (!form) return;
  var root = document.querySelector('.tlx');
  var $ = function (id) { return document.getElementById(id); };
  var text = $('ai-testo'), count = $('tlx-count'), input = $('post-image-input'), drop = $('tlx-drop');
  var strip = $('tlx-photos'), thumbData = $('post-image-thumb-data');
  var files = [];

  function autoGrow() { text.style.height = 'auto'; text.style.height = Math.min(text.scrollHeight, 320) + 'px'; }
  function sync() { input.files = toList(files); }
  function toList(arr) { var dt = new DataTransfer(); arr.forEach(function (f) { dt.items.add(f); }); return dt.files; }

  // Miniatura JPEG leggera della prima foto (come nella pagina originale): alleggerisce Feed e lista.
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
    strip.innerHTML = '';
    strip.hidden = !files.length;
    files.forEach(function (f, i) {
      var d = document.createElement('div'); d.className = 'tlx-ph';
      var im = document.createElement('img'); im.alt = ''; im.src = URL.createObjectURL(f);
      var x = document.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', 'Rimuovi foto'); x.innerHTML = '&times;';
      x.addEventListener('click', function () { files.splice(i, 1); sync(); renderPhotos(); makeThumb(); preview(); });
      d.appendChild(im); d.appendChild(x);
      if (i === 0) { var b = document.createElement('span'); b.textContent = 'Copertina'; d.appendChild(b); }
      strip.appendChild(d);
    });
    drop.classList.toggle('compact', files.length > 0);
  }

  function addFiles(list) {
    Array.prototype.forEach.call(list, function (f) {
      if (f.type.indexOf('image/') === 0 && files.length < 10) files.push(f);
    });
    sync(); renderPhotos(); makeThumb(); preview();
  }
  input.addEventListener('change', function () { files = Array.prototype.slice.call(input.files); renderPhotos(); makeThumb(); preview(); });
  ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
  drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) addFiles(e.dataTransfer.files); });
  // Incolla un'immagine dagli appunti dentro il testo
  text.addEventListener('paste', function (e) {
    var it = e.clipboardData && e.clipboardData.files; if (it && it.length) { addFiles(it); }
  });

  // Anteprima dal vivo
  function preview() {
    var t = text.value.trim(), vis = form.querySelector('[name=visibility]:checked').value;
    var later = form.querySelector('[name=when]:checked').value === 'later' && $('tlx-publish-at').value;
    $('tlx-prev-text').textContent = t || (files.length ? 'Foto condivisa' : 'Il tuo testo comparirà qui');
    var th = $('tlx-prev-thumb');
    if (files[0]) { th.innerHTML = '<img alt="" src="' + URL.createObjectURL(files[0]) + '">'; }
    else { th.innerHTML = '<span class="stc-ph">' + ((root.dataset.name || 'T').charAt(0).toUpperCase()) + '</span>'; }
    var st = $('tlx-prev-status');
    st.className = 'stc-status ' + (later ? 'stc-s-warn' : vis === 'private' ? 'stc-s-grey' : 'stc-s-ok');
    st.innerHTML = later ? '<i class="fa-regular fa-clock"></i> Programmato' : vis === 'private' ? '<i class="fa-solid fa-lock"></i> Solo io' : '<i class="fa-solid fa-circle-check"></i> Pubblicato';
    $('tlx-prev-meta').textContent = (root.dataset.name || '') + ' · ' + (later ? $('tlx-publish-at').value.replace('T', ' ') : 'adesso');
    var btn = $('tlx-submit').querySelector('span');
    btn.textContent = later ? 'Programma' : vis === 'private' ? 'Salva per me' : 'Pubblica';
  }
  text.addEventListener('input', function () { count.textContent = text.value.length; autoGrow(); preview(); });
  form.addEventListener('change', function (e) {
    if (e.target.name === 'when') {
      var later = e.target.value === 'later';
      $('tlx-when').hidden = !later;
      $('tlx-publish-at').disabled = !later;
      if (later && !$('tlx-publish-at').value) {
        var d = new Date(Date.now() + 3600e3); d.setMinutes(0, 0, 0);
        $('tlx-publish-at').value = new Date(d - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
      }
    }
    preview();
  });
  $('tlx-publish-at').disabled = true;
  // Fuso orario del browser (come nella pagina originale)
  var tz = form.querySelector('[name=tz_offset_minutes]'); if (tz) tz.value = new Date().getTimezoneOffset();
  form.addEventListener('submit', function () { $('tlx-submit').classList.add('busy'); });

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

  // AI: genera il testo (stesso servizio della pagina originale)
  var panel = $('ai-caption-panel'), status = $('ai-caption-status'), gen = $('ai-caption-generate');
  $('ai-caption-toggle').addEventListener('click', function () { panel.hidden = !panel.hidden; if (!panel.hidden) $('ai-caption-keywords').focus(); });
  $('ai-caption-cancel').addEventListener('click', function () { panel.hidden = true; status.textContent = ''; });
  gen.addEventListener('click', function () {
    var k = $('ai-caption-keywords').value.trim();
    if (!k) { status.textContent = 'Scrivi almeno qualche parola chiave.'; return; }
    gen.disabled = true; status.textContent = 'Generazione in corso...';
    var body = new URLSearchParams(); body.set('csrf', form.querySelector('input[name=csrf]').value); body.set('keywords', k);
    fetch('/dashboard_ai_caption.php', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (d) {
      gen.disabled = false;
      if (d.ok) { text.value = d.text; text.dispatchEvent(new Event('input')); status.textContent = 'Fatto! Puoi modificare il testo prima di pubblicare.'; }
      else { status.textContent = d.error || 'Qualcosa è andato storto.'; }
    }).catch(function () { gen.disabled = false; status.textContent = 'Errore di connessione. Riprova.'; });
  });
  autoGrow(); preview();
})();
