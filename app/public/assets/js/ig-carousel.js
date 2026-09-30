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
 *
 * Deep-link: navigando lo slideshow la URL si aggiorna con #foto-N (1-based) — condividendo
 * quel link la pagina si apre direttamente sulla foto indicata.
 */
(function () {
  document.querySelectorAll('.ig-lightbox').forEach(function (lb) {
    document.body.appendChild(lb);
  });

  var lightboxControllers = new Map();
  var activeLightbox = null;

  function updateHash(idx) {
    history.replaceState(null, '', location.pathname + location.search + '#foto-' + (idx + 1));
  }

  function clearHash() {
    history.replaceState(null, '', location.pathname + location.search);
  }

  function wireCarousel(track, dots, prevBtn, nextBtn, counterEl, onIndexChange) {
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
      if (onIndexChange) onIndexChange(idx);
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
    activeLightbox = lb;
    var track = lb.querySelector('.ig-lightbox-track');
    if (track) track.scrollTo({ left: (startIdx || 0) * track.clientWidth, behavior: 'auto' });
    updateHash(startIdx || 0);
  }

  function closeLightbox(lb) {
    lb.classList.remove('open');
    document.body.style.overflow = '';
    if (activeLightbox === lb) {
      activeLightbox = null;
      clearHash();
    }
  }

  function hashOnSlide(idx) {
    if (activeLightbox) updateHash(idx);
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
      lightboxEl.querySelector('.ig-arrow-next'),
      null,
      hashOnSlide
    );
    lightboxControllers.set(lightboxEl, lbCtrl);

    var expandBtn = carousel.querySelector('.ig-expand-btn');
    if (expandBtn) {
      expandBtn.addEventListener('click', function () { openLightbox(lightboxEl, main ? main.currentIndex() : 0); });
    }
    var closeBtn = lightboxEl.querySelector('.ig-lightbox-close');
    if (closeBtn) closeBtn.addEventListener('click', function () { closeLightbox(lightboxEl); });
  });

  // Lightbox standalone (griglia foto senza carosello di anteprima)
  var standaloneLightboxes = [];
  document.querySelectorAll('.ig-lightbox').forEach(function (lightboxEl) {
    if (lightboxControllers.has(lightboxEl)) return;
    var lbCtrl = wireCarousel(
      lightboxEl.querySelector('.ig-lightbox-track'),
      lightboxEl.querySelectorAll('.ig-dot'),
      lightboxEl.querySelector('.ig-arrow-prev'),
      lightboxEl.querySelector('.ig-arrow-next'),
      lightboxEl.querySelector('.ig-lightbox-counter'),
      hashOnSlide
    );
    lightboxControllers.set(lightboxEl, lbCtrl);
    standaloneLightboxes.push({ el: lightboxEl, ctrl: lbCtrl });
    var closeBtn = lightboxEl.querySelector('.ig-lightbox-close');
    if (closeBtn) closeBtn.addEventListener('click', function () { closeLightbox(lightboxEl); });
  });

  document.querySelectorAll('.ig-grid-item').forEach(function (item) {
    var lb = document.querySelector('.ig-lightbox[data-post="' + item.dataset.lightbox + '"]');
    if (!lb) return;
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

  // Deep-link: #foto-N apre la lightbox alla foto N (1-based)
  function openFromHash() {
    var m = location.hash.match(/^#foto-(\d+)$/);
    if (!m) return;
    var idx = parseInt(m[1], 10) - 1;
    if (idx < 0) return;
    // Cerca la prima lightbox standalone (griglia foto/album), altrimenti la prima con carosello
    var target = standaloneLightboxes[0] || null;
    if (!target) {
      var first = lightboxControllers.keys().next().value;
      if (first) target = { el: first, ctrl: lightboxControllers.get(first) };
    }
    if (target) openLightbox(target.el, idx);
  }
  openFromHash();

  window.addEventListener('hashchange', openFromHash);
})();
