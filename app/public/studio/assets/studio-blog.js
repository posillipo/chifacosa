// Studio — pagine di scrittura del Blog: conteggio parole, copertina, programmazione, tag, AI.
(function () {
  var form = document.getElementById('blx-form');
  if (!form) return;
  var $ = function (id) { return document.getElementById(id); };
  var text = $('blog-ai-testo');

  function stats() {
    var n = (text.value.trim().match(/\S+/g) || []).length;
    $('blx-stats').textContent = n + ' parol' + (n === 1 ? 'a' : 'e') + ' · ' + Math.max(n ? 1 : 0, Math.round(n / 200)) + ' min di lettura';
  }
  text.addEventListener('input', stats); stats();

  // Anteprima della copertina scelta
  var cover = $('blx-cover'), prev = $('blx-coverprev'), drop = $('blx-drop');
  cover.addEventListener('change', function () {
    var f = cover.files && cover.files[0];
    prev.hidden = !f;
    prev.innerHTML = f ? '<img alt="" src="' + URL.createObjectURL(f) + '">' : '';
  });
  ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
  drop.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files.length) { cover.files = e.dataTransfer.files; cover.dispatchEvent(new Event('change')); }
  });

  // Quando: subito / programma (solo nella pagina "nuovo")
  var when = $('blx-when'), box = $('blx-whenbox'), btn = $('blx-submit').querySelector('span');
  if (box) {
    when.disabled = true;
    form.addEventListener('change', function (e) {
      if (e.target.name !== 'when') return;
      var later = e.target.value === 'later';
      box.hidden = !later; when.disabled = !later;
      btn.textContent = later ? 'Programma' : 'Pubblica';
      if (later && !when.value) {
        var d = new Date(Date.now() + 3600e3); d.setMinutes(0, 0, 0);
        when.value = new Date(d - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
      }
    });
  }
  var tz = form.querySelector('[name=tz_offset_minutes]'); if (tz) tz.value = new Date().getTimezoneOffset();

  // Tag già usati: un tocco li aggiunge al campo
  var tags = $('blx-tags'), sugg = $('blx-sugg');
  if (sugg) sugg.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-tag]'); if (!b) return;
    var list = tags.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    if (list.indexOf(b.dataset.tag) < 0) list.push(b.dataset.tag);
    tags.value = list.join(', ');
  });

  form.addEventListener('submit', function () { $('blx-submit').classList.add('busy'); });

  // AI (stesso servizio della pagina originale)
  var panel = $('blog-ai-caption-panel'), st = $('blog-ai-caption-status'), gen = $('blog-ai-caption-generate');
  $('blog-ai-caption-toggle').addEventListener('click', function () { panel.hidden = !panel.hidden; if (!panel.hidden) $('blog-ai-caption-keywords').focus(); });
  $('blog-ai-caption-cancel').addEventListener('click', function () { panel.hidden = true; st.textContent = ''; });
  gen.addEventListener('click', function () {
    var k = $('blog-ai-caption-keywords').value.trim();
    if (!k) { st.textContent = 'Scrivi almeno qualche parola chiave.'; return; }
    gen.disabled = true; st.textContent = 'Generazione in corso...';
    var body = new URLSearchParams(); body.set('csrf', form.querySelector('input[name=csrf]').value); body.set('keywords', k);
    fetch('/dashboard_ai_caption.php', { method: 'POST', body: body }).then(function (r) { return r.json(); }).then(function (d) {
      gen.disabled = false;
      if (d.ok) { text.value = d.text; stats(); st.textContent = 'Fatto! Puoi modificare il testo prima di pubblicare.'; }
      else { st.textContent = d.error || 'Qualcosa è andato storto.'; }
    }).catch(function () { gen.disabled = false; st.textContent = 'Errore di connessione. Riprova.'; });
  });
})();
