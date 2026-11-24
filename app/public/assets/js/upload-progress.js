/*
 * Barra di avanzamento per TUTTI i caricamenti di file della dashboard (classica e Studio).
 *  - Moduli normali: ogni <form method="post"> che ha almeno un file scelto viene inviato con
 *    XMLHttpRequest, così si vede l'avanzamento. Il server riceve lo stesso invio di sempre (stessi campi,
 *    stesso token CSRF). Senza JavaScript, o senza file scelti, il modulo funziona come prima.
 *    Per escluderne uno: data-no-upload-progress.
 *  - Caricamenti fatti da JavaScript (FormData con un file): window.uploadWithProgress(url, formData)
 *    mostra la stessa barra e restituisce una promessa con la risposta JSON del server
 *    ({ ok: false, error } in caso di errore o annullamento, come si aspettano già i chiamanti).
 * Limiti del server (post_max_size, max_file_uploads): letti da [data-upload-limits] (messo nel piè di
 * pagina) oppure da data-max-bytes / data-max-files del modulo.
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
      'dialog.up-ov{border:0;margin:0;max-width:none;max-height:none;width:100%;height:100%;box-sizing:border-box;display:none}' +
      'dialog.up-ov[open]{display:flex}' +
      'dialog.up-ov::backdrop{background:transparent}' +
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
    // Un <dialog> modale sta nello strato più alto della pagina: la barra resta DAVANTI anche ai popup (Studio)
    // che sono a loro volta <dialog> e che nessun z-index potrebbe superare.
    var useDialog = typeof HTMLDialogElement === 'function';
    var ov = document.createElement(useDialog ? 'dialog' : 'div');
    ov.className = 'up-ov';
    if (!useDialog) { ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); }
    ov.setAttribute('aria-live', 'polite');
    ov.innerHTML =
      '<div class="up-box">' +
      '<p class="up-title"></p><p class="up-sub"></p>' +
      '<div class="up-bar"><div class="up-fill"></div></div>' +
      '<div class="up-pct"><span class="up-pct-n">0%</span><span class="up-pct-b"></span></div>' +
      '<div class="up-actions"><button type="button" class="up-btn"></button></div>' +
      '</div>';
    document.body.appendChild(ov);
    if (useDialog) {
      ov.addEventListener('cancel', function (e) { e.preventDefault(); }); // Esc non deve nascondere un caricamento in corso
      try { ov.showModal(); } catch (err) { ov.setAttribute('open', ''); }
    }
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
    if (ui.sub.parentNode) ui.sub.remove();
    ui.bar.style.display = 'none';
    ui.pct.style.display = 'none';
    var p = document.createElement('p');
    p.className = 'up-err';
    p.textContent = message; // textContent: nessun HTML dal server finisce nella pagina
    ui.box.insertBefore(p, ui.box.querySelector('.up-actions'));
    ui.btn.style.display = '';
    ui.btn.textContent = 'Chiudi';
    ui.btn.onclick = function () { ui.ov.remove(); };
  }

  function countFilesInForm(form) {
    var n = 0, total = 0;
    form.querySelectorAll('input[type=file]').forEach(function (inp) {
      Array.prototype.forEach.call(inp.files || [], function (f) { n++; total += f.size; });
    });
    return { n: n, total: total };
  }

  function countFilesInData(data) {
    var n = 0, total = 0;
    data.forEach(function (v) { if (typeof File !== 'undefined' && v instanceof File && v.size > 0) { n++; total += v.size; } });
    return { n: n, total: total };
  }

  // Limiti del server: prima quelli del modulo, poi quelli globali del piè di pagina.
  function limits(form) {
    var g = document.querySelector('[data-upload-limits]');
    function pick(attr) {
      var v = form ? parseInt(form.getAttribute(attr) || '0', 10) : 0;
      if (!v && g) v = parseInt(g.getAttribute(attr) || '0', 10);
      return v || 0;
    }
    return { maxBytes: pick('data-max-bytes'), maxFiles: pick('data-max-files') };
  }

  /**
   * Esegue l'invio con la barra. opts: { url, method, data, info, limits, mode: 'page' | 'json', done(result) }
   * mode 'page': alla fine mostra la pagina di risposta (o va alla pagina di destinazione).
   * mode 'json': alla fine chiama done(oggetto JSON).
   */
  function start(opts) {
    var ui = buildOverlay();
    var info = opts.info, lim = opts.limits || {};
    var json = opts.mode === 'json';
    var settled = false;
    function fail(message) {
      showError(ui, message);
      if (json && !settled) { settled = true; opts.done({ ok: false, error: message }); }
    }
    if (lim.maxBytes > 0 && info.total > lim.maxBytes * 0.98) {
      return fail('Hai scelto ' + info.n + ' file per ' + fmtBytes(info.total) + ': il massimo per ogni invio è ' + fmtBytes(lim.maxBytes) + '. Carica meno file per volta.');
    }
    if (lim.maxFiles > 0 && info.n > lim.maxFiles) {
      return fail('Hai scelto ' + info.n + ' file: il massimo per ogni invio è ' + lim.maxFiles + '. Carica meno file per volta.');
    }

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
        // Il file è arrivato: ora il server lo controlla, lo ridimensiona e lo salva.
        ui.title.textContent = 'Elaborazione sul server…';
        ui.fill.classList.add('busy');
        ui.pctN.textContent = '';
        ui.pctB.textContent = 'Salvataggio in corso, può richiedere qualche istante';
        ui.btn.style.display = 'none';
      }
    };
    xhr.onload = function () {
      finished = true;
      if (xhr.status === 413) {
        return fail('Il caricamento supera il limite del server. Riprova con meno file per volta.');
      }
      if (xhr.status >= 500 || xhr.status === 0) {
        return fail('Il server non ha completato l\'operazione (errore ' + xhr.status + '). Controlla l\'elenco prima di riprovare: alcuni file potrebbero essere già stati salvati.');
      }
      if (json) {
        var d = null;
        try { d = JSON.parse(xhr.responseText); } catch (err) { d = null; }
        ui.ov.remove();
        settled = true;
        opts.done(d || { ok: false, error: 'Risposta non valida dal server: ricarica la pagina e controlla.' });
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
      fail('Errore di connessione durante il caricamento. Controlla la rete e riprova.');
    };
    xhr.onabort = function () {
      finished = true;
      ui.ov.remove();
      if (json && !settled) { settled = true; opts.done({ ok: false, error: 'Caricamento annullato.' }); }
    };
    xhr.open(opts.method, opts.url);
    xhr.send(opts.data);
  }

  // Caricamenti fatti da JavaScript (es. la foto di un elemento «Che amo»): stessa barra, risposta JSON.
  window.uploadWithProgress = function (url, formData) {
    return new Promise(function (resolve) {
      start({ url: url, method: 'POST', data: formData, info: countFilesInData(formData), limits: limits(null), mode: 'json', done: resolve });
    });
  };

  // Tutti i moduli: un solo ascoltatore sul documento, così vale anche per i moduli creati dopo.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM' || form.hasAttribute('data-no-upload-progress')) return;
    // Un modulo con una conferma (onsubmit="return confirm(...)") l'ha già gestita prima di arrivare qui.
    if (e.defaultPrevented) return;
    var method = (form.getAttribute('method') || 'get').toUpperCase();
    var target = form.getAttribute('target');
    if (method !== 'POST' || (target && target !== '_self')) return;
    var info = countFilesInForm(form);
    if (info.n === 0) return; // niente file: invio normale, senza barra
    e.preventDefault();
    var data;
    try { data = new FormData(form, e.submitter || undefined); } catch (err) { data = new FormData(form); }
    start({
      url: form.getAttribute('action') || window.location.href,
      method: 'POST',
      data: data,
      info: info,
      limits: limits(form),
      mode: 'page'
    });
  });
})();
