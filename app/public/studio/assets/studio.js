// Studio — piccoli comportamenti (JavaScript semplice).
(function () {
  var toast;
  function say(msg) {
    if (!toast) { toast = document.createElement('div'); toast.className = 'stc-toast'; toast.setAttribute('role', 'status'); document.body.appendChild(toast); }
    toast.textContent = msg; toast.classList.add('show');
    clearTimeout(say.t); say.t = setTimeout(function () { toast.classList.remove('show'); }, 2200);
  }
  // Condividi: foglio di condivisione del telefono, altrimenti copia il link.
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-share]');
    if (!b) return;
    var d; try { d = JSON.parse(b.getAttribute('data-share')); } catch (x) { return; }
    if (navigator.share) { navigator.share({ title: d.t, url: d.u }).catch(function () {}); }
    else if (navigator.clipboard) { navigator.clipboard.writeText(d.u).then(function () { say('Link copiato'); }, function () { say(d.u); }); }
    else { say(d.u); }
  });
  // Conferma prima di inviare un modulo (data-confirm="testo") — al posto di onsubmit dentro le pagine.
  document.addEventListener('submit', function (e) {
    var c = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (c && !window.confirm(c)) e.preventDefault();
  });
  // "In coda social": apre il pannello già esistente della dashboard (window.sqSuggest).
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-sq]');
    if (!b || typeof window.sqSuggest !== 'function') return;
    try { window.sqSuggest(JSON.parse(b.getAttribute('data-sq'))); } catch (x) {}
  });
})();
