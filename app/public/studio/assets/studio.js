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
})();
