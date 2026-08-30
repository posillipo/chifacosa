// Tema "Scorrimento Infinito" — pannelli a schermo intero con scorrimento fluido continuo
// (Lenis) e un leggero effetto di profondità sulle immagini mentre si scorre (GSAP +
// ScrollTrigger). Adattamento del tutorial open-source "Infinite Scroll with Parallax"
// (Codrops): stessa logica di scrollerProxy/parallax/marquee, applicata ai pannelli generati
// da renderParallaxHeroScene() (profilo + voci del menu del profilo).
(function () {
  const DATA = window.__IP_DATA__ || { loopable: false };

  if (typeof gsap === 'undefined' || typeof Lenis === 'undefined') return; // CDN non raggiunta: niente scroll fluido, ma la pagina resta comunque scrollabile normalmente
  gsap.registerPlugin(ScrollTrigger);

  const wrapper = document.getElementById('ip-wrapper');
  const content = document.getElementById('ip-content');

  const lenis = new Lenis({
    infinite: !!DATA.loopable,
    wrapper: wrapper,
    content: content,
    syncTouch: true,
  });

  if (typeof Snap !== 'undefined') {
    const snap = new Snap(lenis, {
      type: 'mandatory',
      debounce: 500,
      duration: 0.9,
      easing: (t) => 1 - Math.pow(1 - t, 4),
    });
    snap.addElements(document.querySelectorAll('.ip-hero'), { align: 'start' });
  }

  ScrollTrigger.scrollerProxy(wrapper, {
    scrollTop(value) {
      if (arguments.length) {
        lenis.scrollTo(value, { immediate: true });
      } else {
        return lenis.scroll;
      }
    },
    getBoundingClientRect() {
      return { top: 0, left: 0, width: wrapper.clientWidth, height: wrapper.clientHeight };
    },
    pinType: 'transform',
  });

  lenis.on('scroll', ScrollTrigger.update);
  gsap.ticker.add((time) => { lenis.raf(time * 1000); });
  gsap.ticker.lagSmoothing(0);

  document.querySelectorAll('.ip-hero').forEach((hero) => {
    const bg = hero.querySelector('.ip-hero-bg');
    const marquee = hero.querySelector('.ip-hero-marquee');
    const shared = {
      ease: 'none',
      scrollTrigger: {
        scroller: wrapper,
        trigger: hero,
        start: 'top bottom',
        end: 'bottom top',
        scrub: true,
        fastScrollEnd: true,
      },
    };
    if (bg) {
      gsap.fromTo(bg, { yPercent: -10 }, { yPercent: 10, ...shared });
    }
    if (marquee) {
      gsap.fromTo(marquee, { scale: 1.35 }, { scale: 0.75, ...shared });
    }
  });

  // ── menu: elenco delle voci reali già nella pagina (i pannelli cliccabili) ──
  const menuOverlay = document.getElementById('ip-menu-overlay');
  const menuBtn = document.getElementById('ip-menu-btn');
  const menuClose = document.getElementById('ip-menu-close');
  document.querySelectorAll('.ip-hero[href]').forEach((hero) => {
    const label = hero.querySelector('.ip-hero-label');
    if (!label) return;
    const a = document.createElement('a');
    a.href = hero.getAttribute('href');
    a.textContent = label.textContent;
    menuOverlay.appendChild(a);
  });
  if (menuBtn) menuBtn.addEventListener('click', () => menuOverlay.classList.add('show'));
  if (menuClose) menuClose.addEventListener('click', () => menuOverlay.classList.remove('show'));
})();
