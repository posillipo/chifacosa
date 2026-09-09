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
</script>
</body>
</html>
