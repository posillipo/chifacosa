// Studio — chat: scorre in fondo ai messaggi, il campo cresce da solo, Invio invia (Maiusc+Invio va a capo).
(function () {
  var list = document.getElementById('chx-list'), form = document.getElementById('chx-form');
  if (list) { window.scrollTo(0, document.body.scrollHeight); }
  if (!form) return;
  var ta = form.querySelector('textarea');
  function grow() { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 160) + 'px'; }
  ta.addEventListener('input', grow);
  ta.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && window.matchMedia('(min-width: 640px)').matches) { e.preventDefault(); if (ta.value.trim()) form.submit(); }
  });
  ta.focus();
})();
