/*!
 * Pilota i caroselli foto stile Instagram generati da renderPhotoCarousel() (functions.php) —
 * usato dalle pagine di dettaglio condivisibili che possono avere più di una foto (post
 * Timeline, viaggi...). Sulla stessa pagina possono comparirne più di uno (l'elemento
 * principale + quelli "della stessa giornata" mostrati sotto), quindi niente id fissi: ogni
 * .ig-carousel/.ig-lightbox si accoppia tramite l'attributo data-post condiviso.
 *
 * Pilota anche le lightbox "a sé stanti" (nessuna .ig-carousel di anteprima abbinata), usate
 * per aprire a tutto schermo le griglie di foto singole di post diversi (es. la sezione Foto
 * del profilo pubblico): ogni miniatura .ig-grid-item apre la lightbox già posizionata sulla
 * foto cliccata, navigabile con le frecce a schermo e con i tasti freccia della tastiera.
 */
(function () {
  // Ogni lightbox nasce dentro il markup dell'elemento (comodo da generare in PHP), ma per
  // essere DAVVERO a tutto schermo su ogni telefono non deve avere nessun antenato con
  // transform/filter/opacity — condizione che non possiamo garantire con certezza (temi
  // diversi, sfondi animati...). Spostarla come figlio diretto di <body> la mette al riparo da
  // qualsiasi antenato del genere, prima ancora che l'utente la apra.
  document.querySelectorAll('.ig-lightbox').forEach(function (lb) {
    document.body.appendChild(lb);
  });

  // Tiene traccia di goTo()/currentIndex() di ogni lightbox aperta, per poterla far scorrere
  // con le frecce della tastiera (vedi il listener keydown più sotto).
  var lightboxControllers = new Map();

  // Un solo carosello (nell'anteprima o nella vista a tutto schermo) è sempre lo stesso
  // meccanismo: scroll-snap orizzontale + frecce/puntini/contatore che leggono/impostano la
  // posizione — condiviso qui invece di duplicarlo. counterEl è opzionale, usato al posto dei
  // puntini quando le foto sono troppe per mostrarne uno a testa (es. la griglia Foto).
  function wireCarousel(track, dots, prevBtn, nextBtn, counterEl) {
    if (!track) return null;
    var count = dots.length || track.children.length;
    if (!count) return null;
    function goTo(idx) {
      idx = Math.max(0, Math.min(count - 1, idx));
      track.scrollTo({ left: idx * track.clientWidth, behavior: 'smooth' });
    }
    function currentIndex() {
      return Math.round(track.scrollLeft / track.clientWidth);
    }
    function updateIndicators(idx) {
      dots.forEach(function (dot, i) { dot.classList.toggle('active', i === idx); });
      if (counterEl) counterEl.textContent = (idx + 1) + ' / ' + count;
    }
    dots.forEach(function (dot) {
      dot.addEventListener('click', function () { goTo(parseInt(dot.dataset.index, 10)); });
    });
    if (prevBtn) prevBtn.addEventListener('click', function () { goTo(currentIndex() - 1); });
    if (nextBtn) nextBtn.addEventListener('click', function () { goTo(currentIndex() + 1); });
    var ticking = false;
    track.addEventListener('scroll', function () {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () {
        updateIndicators(currentIndex());
        ticking = false;
      });
    });
    updateIndicators(0);
    return { goTo: goTo, currentIndex: currentIndex };
  }

  function openLightbox(lb, startIdx) {
    lb.classList.add('open');
    document.body.style.overflow = 'hidden';
    var track = lb.querySelector('.ig-lightbox-track');
    if (track) track.scrollTo({ left: (startIdx || 0) * track.clientWidth, behavior: 'auto' });
  }

  function closeLightbox(lb) {
    lb.classList.remove('open');
    document.body.style.overflow = '';
  }

  document.querySelectorAll('.ig-carousel').forEach(function (carousel) {
    var postId = carousel.dataset.post;
    var main = wireCarousel(
      carousel.querySelector('.ig-carousel-track'),
      carousel.querySelectorAll('.ig-dot'),
      carousel.querySelector('.ig-arrow-prev'),
      carousel.querySelector('.ig-arrow-next')
    );

    var lightboxEl = document.querySelector('.ig-lightbox[data-post="' + postId + '"]');
    if (!lightboxEl) return;
    var lbCtrl = wireCarousel(
      lightboxEl.querySelector('.ig-lightbox-track'),
      lightboxEl.querySelectorAll('.ig-dot'),
      lightboxEl.querySelector('.ig-arrow-prev'),
      lightboxEl.querySelector('.ig-arrow-next')
    );
    lightboxControllers.set(lightboxEl, lbCtrl);

    var expandBtn = carousel.querySelector('.ig-expand-btn');
    if (expandBtn) {
      // Apre sulla stessa foto che si stava già guardando nell'anteprima, senza scatto.
      expandBtn.addEventListener('click', function () { openLightbox(lightboxEl, main ? main.currentIndex() : 0); });
    }
    var closeBtn = lightboxEl.querySelector('.ig-lightbox-close');
    if (closeBtn) closeBtn.addEventListener('click', function () { closeLightbox(lightboxEl); });
  });

  // Lightbox rimaste senza una .ig-carousel di anteprima abbinata: sono quelle "a sé stanti"
  // usate per aprire a tutto schermo una griglia di miniature (.ig-grid-item).
  document.querySelectorAll('.ig-lightbox').forEach(function (lightboxEl) {
    if (lightboxControllers.has(lightboxEl)) return;
    var lbCtrl = wireCarousel(
      lightboxEl.querySelector('.ig-lightbox-track'),
      lightboxEl.querySelectorAll('.ig-dot'),
      lightboxEl.querySelector('.ig-arrow-prev'),
      lightboxEl.querySelector('.ig-arrow-next'),
      lightboxEl.querySelector('.ig-lightbox-counter')
    );
    lightboxControllers.set(lightboxEl, lbCtrl);
    var closeBtn = lightboxEl.querySelector('.ig-lightbox-close');
    if (closeBtn) closeBtn.addEventListener('click', function () { closeLightbox(lightboxEl); });
  });

  document.querySelectorAll('.ig-grid-item').forEach(function (item) {
    var lb = document.querySelector('.ig-lightbox[data-post="' + item.dataset.lightbox + '"]');
    if (!lb) return;
    // Il link resta un <a href> vero (funziona senza JS / per i motori di ricerca) — con JS
    // attivo, il click apre invece la foto a tutto schermo senza lasciare la pagina.
    item.addEventListener('click', function (e) {
      e.preventDefault();
      openLightbox(lb, parseInt(item.dataset.index, 10) || 0);
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.ig-lightbox.open').forEach(closeLightbox);
      return;
    }
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
    var openLb = document.querySelector('.ig-lightbox.open');
    if (!openLb) return;
    var ctrl = lightboxControllers.get(openLb);
    if (!ctrl) return;
    ctrl.goTo(ctrl.currentIndex() + (e.key === 'ArrowRight' ? 1 : -1));
  });
})();
