// Ritaglio della foto profilo prima del salvataggio: appena si sceglie un file si apre una
// finestra con cropper.js (trascina per spostare, rotellina/pizzica per ingrandire), sempre
// quadrato (l'avatar è sempre mostrato come cerchio). Alla conferma il ritaglio viene disegnato
// su un canvas 500x500 e trasformato in JPEG base64, messo in un campo nascosto — il file
// originale scelto NON viene più inviato, solo l'immagine già ritagliata. Se cropper.js non
// carica per qualunque motivo, il campo file resta quello di sempre e il caricamento funziona
// comunque, semplicemente senza ritaglio (vedi il gestore POST in dashboard_profile.php).
(function () {
  var input = document.getElementById('avatar-input');
  var modal = document.getElementById('avatar-crop-modal');
  var cropImg = document.getElementById('avatar-crop-image');
  var cancelBtn = document.getElementById('avatar-crop-cancel');
  var confirmBtn = document.getElementById('avatar-crop-confirm');
  var hiddenField = document.getElementById('avatar-cropped-data');
  var preview = document.getElementById('avatar-preview');
  if (!input || !modal || !cropImg || typeof Cropper === 'undefined') return;

  var cropper = null;

  function closeModal() {
    if (cropper) { cropper.destroy(); cropper = null; }
    modal.style.display = 'none';
  }

  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function (e) {
      cropImg.src = e.target.result;
      modal.style.display = 'flex';
      if (cropper) { cropper.destroy(); }
      cropper = new Cropper(cropImg, {
        aspectRatio: 1,
        viewMode: 1,
        dragMode: 'move',
        background: false,
        autoCropArea: 0.9,
        guides: false,
        center: false,
        highlight: false,
      });
    };
    reader.readAsDataURL(file);
  });

  cancelBtn.addEventListener('click', function () {
    closeModal();
    input.value = ''; // niente file "a metà": o si ritaglia, o si riparte da capo
  });

  confirmBtn.addEventListener('click', function () {
    if (!cropper) return;
    var canvas = cropper.getCroppedCanvas({
      width: 500,
      height: 500,
      imageSmoothingQuality: 'high',
    });
    if (!canvas) return;
    var dataUrl = canvas.toDataURL('image/jpeg', 0.9);
    hiddenField.value = dataUrl;
    if (preview) {
      preview.src = dataUrl;
      preview.style.display = '';
    }
    input.value = ''; // evita di inviare anche il file originale non ritagliato
    closeModal();
  });
})();
