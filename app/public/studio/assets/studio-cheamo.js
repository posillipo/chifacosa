// Studio — pagina Che Amo: riordino delle raccolte per trascinamento (stesso servizio della pagina originale).
(function () {
  var list = document.getElementById('che-amo-sortable-list');
  if (!list) return;
  var toastEl;
  function toast(msg) {
    if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'stc-toast'; toastEl.setAttribute('role', 'status'); document.body.appendChild(toastEl); }
    toastEl.textContent = msg; toastEl.classList.add('show');
    clearTimeout(toast.t); toast.t = setTimeout(function () { toastEl.classList.remove('show'); }, 2000);
  }
  function init() {
    if (!window.Sortable) { return setTimeout(init, 100); }
    Sortable.create(list, {
      handle: '.che-amo-drag-handle', animation: 160, ghostClass: 'lkx-ghost', chosenClass: 'lkx-chosen',
      onEnd: function () {
        var body = new URLSearchParams();
        body.set('csrf', list.getAttribute('data-csrf')); body.set('action', 'reorder');
        Array.prototype.forEach.call(list.querySelectorAll('.che-amo-tile-wrap'), function (el) { body.append('order[]', el.getAttribute('data-id')); });
        fetch(location.pathname, { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); }).then(function () { toast('Ordine aggiornato'); })
          .catch(function () { toast('Ordine non salvato, riprova'); });
      }
    });
  }
  init();
})();
