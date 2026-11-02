/*
 * Barra di avanzamento per i moduli di caricamento file (dashboard).
 * Si attiva SOLO sui <form data-upload-progress> (vedi uploadProgressAttrs() in functions.php),
 * quindi gli altri moduli non vengono toccati. Il server non cambia: riceve lo stesso invio di
 * sempre (stessi campi, stesso token CSRF), ma fatto con XMLHttpRequest per poter mostrare
 * l'avanzamento. Senza JavaScript, o su browser molto vecchi, il modulo funziona come prima.
 *   data-max-bytes  dimensione massima totale per invio (post_max_size del server)
 *   data-max-files  numero massimo di file per invio (max_file_uploads del server)
 */
(function () {
  'use strict';
  if (!window.XMLHttpRequest || !window.FormData) return;

  function fmtBytes(n) {
    if (n >= 1073741824) return (n / 1073741824).toFixed(1) + ' GB';
    if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
    return Math.max(1, Math.round(n / 1024)) + ' KB';
  }

  var styleAdded = false;
  function addStyle() {
    if (styleAdded) return;
    styleAdded = true;
    var st = document.createElement('style');
    st.textContent =
      '.up-ov{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:900;display:flex;align-items:center;justify-content:center;padding:16px}' +
      '.up-box{background:#fff;border-radius:14px;padding:22px 22px 18px;width:min(440px,100%);box-shadow:0 8px 40px rgba(0,0,0,.25);color:#22223b}' +
      '.up-title{font-weight:700;font-size:16px;margin:0 0 4px}' +
      '.up-sub{font-size:13px;color:#6b6b7a;margin:0 0 14px}' +
      '.up-bar{height:12px;border-radius:999px;background:#ecebf8;overflow:hidden}' +
      '.up-fill{height:100%;width:0;background:var(--accent,#6c5ce7);border-radius:999px;transition:width .15s linear}' +
      '.up-fill.busy{width:100%;background:repeating-linear-gradient(45deg,var(--accent,#6c5ce7) 0 12px,#8f84ee 12px 24px);background-size:34px 34px;animation:upmove .8s linear infinite}' +
      '@keyframes upmove{to{background-position:34px 0}}' +
      '.up-pct{display:flex;justify-content:space-between;font-size:13px;margin-top:8px;color:#22223b}' +
      '.up-err{color:#c0392b;font-size:14px;margin:0 0 14px}' +
      '.up-actions{display:flex;justify-content:flex-end;margin-top:16px}' +
      '.up-btn{background:#fff;border:1px solid #c9c9d6;border-radius:8px;padding:8px 16px;cursor:pointer;font-size:14px;color:#22223b}';
    document.head.appendChild(st);
  }

  function buildOverlay() {
    addStyle();
    var ov = document.createElement('div');
    ov.className = 'up-ov';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.setAttribute('aria-live', 'polite');
    ov.innerHTML =
      '<div class="up-box">' +
      '<p class="up-title"></p><p class="up-sub"></p>' +
      '<div class="up-bar"><div class="up-fill"></div></div>' +
      '<div class="up-pct"><span class="up-pct-n">0%</span><span class="up-pct-b"></span></div>' +
      '<div class="up-actions"><button type="button" class="up-btn"></button></div>' +
      '</div>';
    document.body.appendChild(ov);
    return {
      ov: ov,
      title: ov.querySelector('.up-title'),
      sub: ov.querySelector('.up-sub'),
      fill: ov.querySelector('.up-fill'),
      pctN: ov.querySelector('.up-pct-n'),
      pctB: ov.querySelector('.up-pct-b'),
      box: ov.querySelector('.up-box'),
      btn: ov.querySelector('.up-btn'),
      bar: ov.querySelector('.up-bar'),
      pct: ov.querySelector('.up-pct')
    };
  }

  function showError(ui, message) {
    ui.title.textContent = 'Caricamento non riuscito';
    ui.sub.remove();
    ui.bar.style.display = 'none';
    ui.pct.style.display = 'none';
    var p = document.createElement('p');
    p.className = 'up-err';
    p.textContent = message; // textContent: nessun HTML dal server finisce nella pagina
    ui.box.insertBefore(p, ui.box.querySelector('.up-actions'));
    ui.btn.textContent = 'Chiudi';
    ui.btn.onclick = function () { ui.ov.remove(); };
  }

  function countFiles(form) {
    var n = 0, total = 0;
    form.querySelectorAll('input[type=file]').forEach(function (inp) {
      Array.prototype.forEach.call(inp.files || [], function (f) { n++; total += f.size; });
    });
    return { n: n, total: total };
  }

  function attach(form) {
    form.addEventListener('submit', function (e) {
      // Un modulo con una conferma (onsubmit="return confirm(...)") l'ha già gestita prima di arrivare qui.
      if (e.defaultPrevented) return;
      var info = countFiles(form);
      if (info.n === 0) return; // niente file: invio normale, senza barra

      var maxBytes = parseInt(form.getAttribute('data-max-bytes') || '0', 10);
      var maxFiles = parseInt(form.getAttribute('data-max-files') || '0', 10);
      e.preventDefault();

      var ui = buildOverlay();
      if (maxBytes > 0 && info.total > maxBytes * 0.98) {
        showError(ui, 'Hai scelto ' + info.n + ' file per ' + fmtBytes(info.total) + ': il massimo per ogni invio è ' + fmtBytes(maxBytes) + '. Carica meno foto per volta.');
        return;
      }
      if (maxFiles > 0 && info.n > maxFiles) {
        showError(ui, 'Hai scelto ' + info.n + ' file: il massimo per ogni invio è ' + maxFiles + '. Carica meno foto per volta.');
        return;
      }

      var data;
      try { data = new FormData(form, e.submitter || undefined); } catch (err) { data = new FormData(form); }
      var xhr = new XMLHttpRequest();
      var finished = false;
      ui.title.textContent = 'Caricamento in corso…';
      ui.sub.textContent = info.n + (info.n === 1 ? ' file' : ' file') + ' · ' + fmtBytes(info.total) + '. Non chiudere la pagina.';
      ui.btn.textContent = 'Annulla';
      ui.btn.onclick = function () { if (!finished) { xhr.abort(); } };

      xhr.upload.onprogress = function (ev) {
        if (!ev.lengthComputable) return;
        var pct = Math.min(100, Math.round((ev.loaded / ev.total) * 100));
        ui.fill.style.width = pct + '%';
        ui.pctN.textContent = pct + '%';
        ui.pctB.textContent = fmtBytes(ev.loaded) + ' di ' + fmtBytes(ev.total);
        if (pct >= 100) {
          // Il file è arrivato: ora il server ridimensiona e salva le foto.
          ui.title.textContent = 'Elaborazione sul server…';
          ui.fill.classList.add('busy');
          ui.pctN.textContent = '';
          ui.pctB.textContent = 'Salvataggio delle foto, può richiedere qualche istante';
          ui.btn.style.display = 'none';
        }
      };
      xhr.onload = function () {
        finished = true;
        if (xhr.status === 413) {
          showError(ui, 'Il caricamento supera il limite del server. Riprova con meno foto per volta.');
          return;
        }
        if (xhr.status >= 500 || xhr.status === 0) {
          showError(ui, 'Il server non ha completato l\'operazione (errore ' + xhr.status + '). Controlla l\'elenco prima di riprovare: alcune foto potrebbero essere già state salvate.');
          return;
        }
        // Il server risponde con un reindirizzamento alla pagina di destinazione (o con la pagina stessa).
        var target = xhr.responseURL || '';
        if (target && target !== window.location.href) {
          window.location.href = target;
        } else {
          document.open();
          document.write(xhr.responseText);
          document.close();
        }
      };
      xhr.onerror = function () {
        finished = true;
        showError(ui, 'Errore di connessione durante il caricamento. Controlla la rete e riprova.');
      };
      xhr.onabort = function () {
        finished = true;
        ui.ov.remove();
      };
      xhr.open((form.getAttribute('method') || 'post').toUpperCase(), form.getAttribute('action') || window.location.href);
      xhr.send(data);
    });
  }

  function init() {
    document.querySelectorAll('form[data-upload-progress]').forEach(attach);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
