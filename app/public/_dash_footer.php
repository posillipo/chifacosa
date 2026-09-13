</div>
<script>
// Riempie ogni campo nascosto "tz_offset_minutes" con l'offset di fuso orario reale del
// dispositivo di chi sta compilando il form in questo momento — usato da parseLocalDateTime()
// in functions.php per interpretare correttamente le date digitate nei campi datetime-local
// della pagina (programmazione pubblicazione, validità offerte, data eventi...), a prescindere
// da dove si trova chi lo scrive rispetto al fuso configurato sul profilo.
document.querySelectorAll('input[name="tz_offset_minutes"]').forEach(function (el) {
  el.value = new Date().getTimezoneOffset();
});

// Mantiene la posizione di scorrimento tra un ricaricamento e l'altro di questa pagina (es. dopo
// aver eliminato una singola foto da una galleria, o un normale F5) — senza questo, ogni submit
// di un form che ricarica la pagina riporterebbe la vista in cima, perdendo il punto in cui si
// stava lavorando più in basso nella lista.
(function () {
  var scrollKey = 'cfc_scroll_' + location.pathname;
  var savedY = null;
  try { savedY = sessionStorage.getItem(scrollKey); } catch (e) {}
  if (savedY !== null) {
    window.scrollTo(0, parseInt(savedY, 10) || 0);
  }
  window.addEventListener('beforeunload', function () {
    try { sessionStorage.setItem(scrollKey, String(window.scrollY)); } catch (e) {}
  });
})();
</script>
</body>
</html>
