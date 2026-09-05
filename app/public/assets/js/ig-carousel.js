/*!
 * Pilota i caroselli foto stile Instagram generati da renderPhotoCarousel() (functions.php) —
 * usato dalle pagine di dettaglio condivisibili che possono avere più di una foto (post
 * Timeline, viaggi...). Sulla stessa pagina possono comparirne più di uno (l'elemento
 * principale + quelli "della stessa giornata" mostrati sotto), quindi niente id fissi: ogni
 * .ig-carousel/.ig-lightbox si accoppia tramite l'attributo data-post condiviso.
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

  // Un solo carosello (nell'anteprima o nella vista a tutto schermo) è sempre lo stesso
  // meccanismo: scroll-snap orizzontale + frecce/puntini che leggono/impostano la posizione —
  // condiviso qui invece di duplicarlo.
  function wireCarousel(track, dots, prevBtn, nextBtn) {
    if (!track || !dots.length) return null;
    var count = dots.length;
    function goTo(idx) {
      idx = Math.max(0, Math.min(count - 1, idx));
      track.scrollTo({ left: idx * track.clientWidth, behavior: 'smooth' });
    }
    function currentIndex() {
      return Math.round(track.scrollLeft / track.clientWidth);
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
        var idx = currentIndex();
        dots.forEach(function (dot, i) { dot.classList.toggle('active', i === idx); });
        ticking = false;
      });
    });
    return { goTo: goTo, currentIndex: currentIndex };
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
    var lightboxTrack = lightboxEl.querySelector('.ig-lightbox-track');
    wireCarousel(
      lightboxTrack,
      lightboxEl.querySelectorAll('.ig-dot'),
      lightboxEl.querySelector('.ig-arrow-prev'),
      lightboxEl.querySelector('.ig-arrow-next')
    );

    var expandBtn = carousel.querySelector('.ig-expand-btn');
    if (expandBtn) {
      expandBtn.addEventListener('click', function () {
        lightboxEl.classList.add('open');
        document.body.style.overflow = 'hidden';
        // Apre sulla stessa foto che si stava già guardando nell'anteprima, senza scatto.
        var startIdx = main ? main.currentIndex() : 0;
        lightboxTrack.scrollTo({ left: startIdx * lightboxTrack.clientWidth, behavior: 'auto' });
      });
    }
    var closeBtn = lightboxEl.querySelector('.ig-lightbox-close');
    if (closeBtn) closeBtn.addEventListener('click', function () { closeLightbox(lightboxEl); });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.ig-lightbox.open').forEach(closeLightbox);
  });
})();
