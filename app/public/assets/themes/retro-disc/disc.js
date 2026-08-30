// Tema "Disco Retrò" — nessuna libreria 3D: solo DOM/CSS/Canvas per il suono, così è leggero
// e affidabile su qualunque dispositivo (lezione imparata dal tema Giardino Anomalo, che con
// WebGPU andava in crisi su alcuni telefoni). Schermata di avvio "Inserisci il disco" → il
// nome del profilo appare lettera per lettera → vista principale con le voci del menu
// scorrevoli come un mangianastri, si tocca il disco per andare alla vera pagina.
(function () {
  const DATA = window.__RD_DATA__ || { navItems: [], wordmark: 'CFC' };
  const navItems = DATA.navItems || [];

  const bootEl = document.getElementById('rd-boot');
  const logoEl = document.getElementById('rd-logo');
  const mainEl = document.getElementById('rd-main');
  const tickerCol = document.getElementById('rd-ticker-col');
  const tagEl = document.getElementById('rd-tag');
  const dotsEl = document.getElementById('rd-nav-dots');
  const stageEl = document.getElementById('rd-disc-stage');
  const shapeA = document.getElementById('rd-shape-a');
  const shapeB = document.getElementById('rd-shape-b');
  const prevBtn = document.getElementById('rd-arrow-prev');
  const nextBtn = document.getElementById('rd-arrow-next');
  const menuBtn = document.getElementById('rd-menu-btn');
  const menuOverlay = document.getElementById('rd-menu-overlay');
  const menuClose = document.getElementById('rd-menu-close');
  const soundBtn = document.getElementById('rd-sound-toggle');
  const playHint = document.getElementById('rd-play-hint');

  let idx = 0;
  const N = navItems.length;

  // ── suono: qualche bip procedurale semplice, spento di default ──
  const SND = { ctx: null, on: false };
  function ensureAudio() {
    if (SND.ctx) return;
    const C = window.AudioContext || window.webkitAudioContext;
    if (!C) return;
    SND.ctx = new C();
  }
  function blip(freq) {
    if (!SND.on || !SND.ctx) return;
    const t = SND.ctx.currentTime;
    const o = SND.ctx.createOscillator();
    const g = SND.ctx.createGain();
    o.type = 'square';
    o.frequency.setValueAtTime(freq, t);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(0.05, t + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, t + 0.12);
    o.connect(g); g.connect(SND.ctx.destination);
    o.start(t); o.stop(t + 0.13);
  }
  if (soundBtn) {
    soundBtn.addEventListener('click', () => {
      ensureAudio();
      if (SND.ctx && SND.ctx.state === 'suspended') SND.ctx.resume();
      SND.on = !SND.on;
      soundBtn.setAttribute('aria-pressed', String(SND.on));
      soundBtn.textContent = SND.on ? '🔊' : '🔈';
      blip(440);
    });
  }

  // ── vista principale: popola ticker/tag/pallini/colori per l'indice corrente ──
  function render(direction) {
    const item = navItems[idx] || { label: DATA.wordmark, color: '#ffffff' };

    const rows = 5;
    tickerCol.innerHTML = '';
    for (let i = 0; i < rows; i++) {
      const span = document.createElement('span');
      span.textContent = item.label;
      if (i === Math.floor(rows / 2)) span.className = 'rd-current';
      tickerCol.appendChild(span);
    }
    const rowH = tickerCol.firstChild.getBoundingClientRect().height || 40;
    const centerOffset = -Math.floor(rows / 2) * rowH;
    if (direction) {
      tickerCol.style.transition = 'none';
      tickerCol.style.transform = 'translateY(' + (centerOffset + (direction > 0 ? rowH * 2 : -rowH * 2)) + 'px)';
      // forza il reflow prima di riattivare la transizione, così l'animazione parte da lì
      void tickerCol.offsetHeight;
      tickerCol.style.transition = '';
    }
    tickerCol.style.transform = 'translateY(' + centerOffset + 'px)';

    tagEl.textContent = item.label;
    shapeA.style.background = item.color || '#ffffff';
    shapeB.style.background = item.color || '#ffffff';
    shapeB.style.borderRadius = '4px';

    dotsEl.innerHTML = '';
    for (let i = 0; i < N; i++) {
      const d = document.createElement('span');
      d.className = 'rd-dot' + (i === idx ? ' active' : '');
      dotsEl.appendChild(d);
    }
    if (N <= 1) {
      prevBtn.style.display = 'none';
      nextBtn.style.display = 'none';
    }
    playHint.textContent = N > 0 ? 'Tocca per entrare' : '';
  }

  function go(delta) {
    if (N === 0) return;
    idx = (idx + delta + N) % N;
    blip(delta > 0 ? 523 : 392);
    render(delta);
  }

  prevBtn.addEventListener('click', () => go(-1));
  nextBtn.addEventListener('click', () => go(1));
  document.addEventListener('keydown', (e) => {
    if (!mainEl.classList.contains('show')) return;
    if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') go(-1);
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') go(1);
    if (e.key === 'Enter') enterCurrent();
  });

  // swipe touch (orizzontale) sul carosello
  let touchStartX = 0, touchStartY = 0, touchMoved = false;
  const carousel = document.getElementById('rd-carousel');
  carousel.addEventListener('touchstart', (e) => {
    touchStartX = e.touches[0].clientX;
    touchStartY = e.touches[0].clientY;
    touchMoved = false;
  }, { passive: true });
  carousel.addEventListener('touchmove', () => { touchMoved = true; }, { passive: true });
  carousel.addEventListener('touchend', (e) => {
    const dx = e.changedTouches[0].clientX - touchStartX;
    const dy = e.changedTouches[0].clientY - touchStartY;
    if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
      go(dx < 0 ? 1 : -1);
    } else if (!touchMoved) {
      enterCurrent();
    }
  });

  // rotellina del mouse su desktop
  let wheelLock = false;
  carousel.addEventListener('wheel', (e) => {
    if (wheelLock) return;
    if (Math.abs(e.deltaY) < 12 && Math.abs(e.deltaX) < 12) return;
    wheelLock = true;
    go((e.deltaY + e.deltaX) > 0 ? 1 : -1);
    setTimeout(() => { wheelLock = false; }, 420);
  }, { passive: true });

  function enterCurrent() {
    if (N === 0) return;
    window.location.href = navItems[idx].url;
  }
  stageEl.addEventListener('click', enterCurrent);

  // ── menu completo ──
  function buildMenu() {
    navItems.forEach((item) => {
      const a = document.createElement('a');
      a.href = item.url;
      a.textContent = item.label;
      menuOverlay.appendChild(a);
    });
  }
  buildMenu();
  menuBtn.addEventListener('click', () => menuOverlay.classList.add('show'));
  menuClose.addEventListener('click', () => menuOverlay.classList.remove('show'));

  // ── sequenza di avvio ──
  function playLogoReveal() {
    logoEl.innerHTML = '';
    const text = DATA.wordmark || 'CFC';
    let delay = 0;
    for (const ch of text) {
      const span = document.createElement('span');
      span.textContent = ch;
      span.style.animationDelay = delay + 's';
      logoEl.appendChild(span);
      delay += 0.09;
    }
    logoEl.classList.add('show');
    setTimeout(() => {
      logoEl.classList.remove('show');
      mainEl.classList.add('show');
      render(0);
    }, delay * 1000 + 550);
  }

  function startBoot() {
    ensureAudio();
    blip(660);
    bootEl.classList.add('hidden');
    setTimeout(playLogoReveal, 350);
  }
  bootEl.addEventListener('click', startBoot);

  // Nessuna voce di menu visibile (tutto nascosto dal profilo): salta comunque all'ingresso
  // principale invece di restare bloccati su un carosello vuoto.
  if (N === 0) {
    render(0);
  }
})();
