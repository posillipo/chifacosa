/*!
 * Tema "Studio Medico" — barre di vetro smerigliato in stile monitor da parete, adattamento
 * libero e semplificato dell'esperimento creativo open-source "Xylophone" di Sujenphea
 * (https://github.com/Sujenphea/xylophone, MIT): stesso principio di base — oggetti di vetro
 * instanziati, con un effetto sonoro al passaggio — reinterpretato qui in chiave calma/clinica
 * (niente simulazione fluida, niente audio campionato: solo un respiro idle e un tocco sonoro
 * sintetizzato) e scritto come script classico via CDN (nessun build tool), sullo stesso
 * modello di wave-bg.js/circuit-bg.js già usati per gli altri temi di questo sito.
 *
 * Le barre sono le voci del menu di navigazione del profilo: toccarle porta alla vera pagina.
 */
(function () {
  var DATA = window.__MG_DATA__ || { navItems: [], accent: '#2f8f9d' };
  var navItems = DATA.navItems || [];
  var accent = DATA.accent || '#2f8f9d';

  var loaderEl = document.getElementById('mg-loader');
  var fallbackEl = document.getElementById('mg-fallback');
  var hoverLabelEl = document.getElementById('mg-hover-label');
  var soundBtn = document.getElementById('mg-sound-toggle');

  function showFallback() {
    if (loaderEl) loaderEl.classList.add('hidden');
    if (fallbackEl) fallbackEl.classList.add('show');
  }

  if (typeof THREE === 'undefined') {
    showFallback();
    return;
  }
  var testCanvas = document.createElement('canvas');
  var gl;
  try {
    gl = testCanvas.getContext('webgl') || testCanvas.getContext('experimental-webgl');
  } catch (e) { gl = null; }
  if (!gl) {
    showFallback();
    return;
  }

  try {
    init();
  } catch (e) {
    showFallback();
  }

  function init() {
    var N = Math.max(navItems.length, 1);

    var renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.setSize(window.innerWidth, window.innerHeight);
    renderer.setClearColor(0x000000, 0);
    renderer.domElement.id = 'mg-canvas';
    document.body.appendChild(renderer.domElement);

    var scene = new THREE.Scene();
    var camera = new THREE.PerspectiveCamera(42, window.innerWidth / window.innerHeight, 0.1, 100);
    var camBase = { x: 0, y: 0.9, z: 8.4 };
    camera.position.set(camBase.x, camBase.y, camBase.z);
    camera.lookAt(0, 0.5, 0);

    scene.add(new THREE.AmbientLight(0xffffff, 0.95));
    var key = new THREE.DirectionalLight(0xfff6ea, 1.5);
    key.position.set(-6, 8, 6);
    scene.add(key);
    var rim = new THREE.DirectionalLight(0xbfe4ee, 1.3);
    rim.position.set(6, 3, -6);
    scene.add(rim);

    // ── bolle sfocate sullo sfondo: forniscono "qualcosa da rifrangere" alle barre di vetro
    // in transmission (il motore cattura automaticamente ciò che sta dietro l'oggetto), dando
    // l'illusione del vetro smerigliato senza scrivere uno shader dedicato. ──
    var blobColors = ['#cdeef0', '#bfe0ea', '#e8f6f2', '#a9d8e6', '#d8f0e6'];
    var blobs = [];
    var blobGeo = new THREE.IcosahedronGeometry(1, 1);
    for (var bi = 0; bi < 6; bi++) {
      var bm = new THREE.MeshBasicMaterial({ color: new THREE.Color(blobColors[bi % blobColors.length]), transparent: true, opacity: 0.4 });
      var bmesh = new THREE.Mesh(blobGeo, bm);
      var scale = 1.1 + Math.random() * 1.6;
      bmesh.scale.setScalar(scale);
      var bx = (bi / 6 - 0.5) * 10 + (Math.random() - 0.5) * 2;
      var by = Math.random() * 3 - 0.5;
      var bz = -3.5 - Math.random() * 4;
      bmesh.position.set(bx, by, bz);
      scene.add(bmesh);
      blobs.push({ mesh: bmesh, baseX: bx, baseY: by, speed: 0.15 + Math.random() * 0.2, phase: Math.random() * Math.PI * 2 });
    }

    // ── pavimento sottile, solo per dare un punto d'appoggio visivo alle barre ──
    var floorGeo = new THREE.PlaneGeometry(16, 8);
    var floorMat = new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.14 });
    var floor = new THREE.Mesh(floorGeo, floorMat);
    floor.rotation.x = -Math.PI / 2;
    floor.position.y = -1.32;
    scene.add(floor);

    // ── barre di vetro: una per voce di menu, allineate come un tracciato a monitor ──
    var spacing = Math.min(1.35, 8.6 / N);
    var totalWidth = (N - 1) * spacing;
    var bars = [];
    var barGeo = new THREE.BoxGeometry(0.5, 1, 0.28);
    for (var i = 0; i < N; i++) {
      var item = navItems[i] || { label: '', url: '#', color: accent };
      var baseColor = new THREE.Color(item.color || accent).lerp(new THREE.Color('#ffffff'), 0.2);
      var mat = new THREE.MeshPhysicalMaterial({
        color: baseColor,
        transparent: true,
        opacity: 0.6,
        roughness: 0.16,
        metalness: 0,
        clearcoat: 1,
        clearcoatRoughness: 0.12,
        transmission: 0.72,
        thickness: 0.6,
        ior: 1.42,
        side: THREE.DoubleSide,
      });
      var mesh = new THREE.Mesh(barGeo, mat);
      var h = 1.5 + Math.sin(i * 1.7) * 0.55 + Math.cos(i * 0.9) * 0.3;
      if (h < 0.9) h = 0.9;
      var x = i * spacing - totalWidth / 2;
      mesh.scale.set(1, h, 1);
      mesh.position.set(x, h / 2 - 1.32, 0);
      scene.add(mesh);
      bars.push({ mesh: mesh, baseHeight: h, x: x, index: i, item: item, hoverScale: 1, phase: i * 0.7 });
    }

    if (loaderEl) requestAnimationFrame(function () { loaderEl.classList.add('hidden'); });

    // ── audio procedurale, spento di default (come negli altri temi del sito) ──
    var SND = { ctx: null, master: null, on: false };
    function ensureAudio() {
      if (SND.ctx) { if (SND.ctx.state === 'suspended') SND.ctx.resume(); return; }
      var C = window.AudioContext || window.webkitAudioContext;
      if (!C) return;
      SND.ctx = new C();
      SND.master = SND.ctx.createGain();
      SND.master.gain.value = 0.14;
      SND.master.connect(SND.ctx.destination);
    }
    var SCALE = [392.0, 440.0, 523.25, 587.33, 659.25]; // pentatonica calma, un tono per barra
    function chime(idx, x) {
      if (!SND.on || !SND.ctx || SND.ctx.state !== 'running') return;
      var t = SND.ctx.currentTime;
      var f = SCALE[idx % SCALE.length];
      var pan = SND.ctx.createStereoPanner ? SND.ctx.createStereoPanner() : null;
      var out = SND.master;
      if (pan) { pan.pan.value = Math.max(-1, Math.min(1, x / 4)); pan.connect(SND.master); out = pan; }
      var o = SND.ctx.createOscillator();
      var g = SND.ctx.createGain();
      o.type = 'sine';
      o.frequency.value = f;
      g.gain.setValueAtTime(0.0001, t);
      g.gain.exponentialRampToValueAtTime(0.6, t + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, t + 0.9);
      o.connect(g); g.connect(out);
      o.start(t); o.stop(t + 0.95);
    }
    if (soundBtn) {
      soundBtn.addEventListener('click', function () {
        SND.on = !SND.on;
        ensureAudio();
        soundBtn.setAttribute('aria-pressed', String(SND.on));
        soundBtn.textContent = SND.on ? '🔊' : '🔈';
      });
    }

    // ── interazione: raycasting reale contro le barre (hover desktop + tap mobile) ──
    var ray = new THREE.Raycaster();
    var ndc = new THREE.Vector2();
    var downNdc = new THREE.Vector2();
    var barMeshes = bars.map(function (b) { return b.mesh; });
    var hoveredIdx = -1;
    var lastSoundIdx = -1;
    var downIdx = -1;
    var downTime = 0;
    var pointerNx = 0, pointerNy = 0, havePointer = false;

    function toNdc(e, out) {
      out.set((e.clientX / window.innerWidth) * 2 - 1, -(e.clientY / window.innerHeight) * 2 + 1);
    }
    function hitTest(pointerNdc) {
      ray.setFromCamera(pointerNdc, camera);
      var hits = ray.intersectObjects(barMeshes, false);
      if (!hits.length) return -1;
      var obj = hits[0].object;
      for (var i = 0; i < bars.length; i++) if (bars[i].mesh === obj) return i;
      return -1;
    }

    renderer.domElement.addEventListener('pointermove', function (e) {
      toNdc(e, ndc);
      pointerNx = ndc.x; pointerNy = ndc.y; havePointer = true;
      hoveredIdx = hitTest(ndc);
      if (hoveredIdx >= 0 && bars[hoveredIdx]) {
        var b = bars[hoveredIdx];
        hoverLabelEl.textContent = b.item.label;
        hoverLabelEl.style.left = e.clientX + 'px';
        hoverLabelEl.style.top = (e.clientY - 30) + 'px';
        hoverLabelEl.style.display = 'block';
        renderer.domElement.style.cursor = 'pointer';
        if (hoveredIdx !== lastSoundIdx) {
          ensureAudio();
          chime(hoveredIdx, b.x);
          lastSoundIdx = hoveredIdx;
        }
      } else {
        hoverLabelEl.style.display = 'none';
        renderer.domElement.style.cursor = 'default';
        lastSoundIdx = -1;
      }
    });
    renderer.domElement.addEventListener('pointerleave', function () {
      hoveredIdx = -1;
      lastSoundIdx = -1;
      havePointer = false;
      hoverLabelEl.style.display = 'none';
    });
    renderer.domElement.addEventListener('pointerdown', function (e) {
      ensureAudio();
      toNdc(e, ndc);
      downNdc.copy(ndc);
      downTime = performance.now();
      downIdx = hitTest(ndc);
    });
    renderer.domElement.addEventListener('pointerup', function (e) {
      if (downIdx < 0) return;
      toNdc(e, ndc);
      var moved = ndc.distanceTo(downNdc);
      var dt = performance.now() - downTime;
      var upIdx = hitTest(ndc);
      if (dt < 400 && moved < 0.03 && upIdx === downIdx && bars[downIdx]) {
        window.location.href = bars[downIdx].item.url;
      }
      downIdx = -1;
    });

    window.addEventListener('resize', function () {
      camera.aspect = window.innerWidth / window.innerHeight;
      camera.updateProjectionMatrix();
      renderer.setSize(window.innerWidth, window.innerHeight);
    });

    var clock = new THREE.Clock();
    function animate() {
      requestAnimationFrame(animate);
      var t = clock.getElapsedTime();

      for (var bi2 = 0; bi2 < blobs.length; bi2++) {
        var bl = blobs[bi2];
        bl.mesh.position.x = bl.baseX + Math.sin(t * bl.speed + bl.phase) * 0.6;
        bl.mesh.position.y = bl.baseY + Math.cos(t * bl.speed * 0.8 + bl.phase) * 0.4;
      }

      for (var i2 = 0; i2 < bars.length; i2++) {
        var b2 = bars[i2];
        var breathe = 1 + Math.sin(t * 0.6 + b2.phase) * 0.035;
        var targetHoverScale = (i2 === hoveredIdx) ? 1.16 : 1;
        b2.hoverScale += (targetHoverScale - b2.hoverScale) * 0.15;
        var h = b2.baseHeight * breathe;
        b2.mesh.scale.set(b2.hoverScale, h, b2.hoverScale);
        b2.mesh.position.y = h / 2 - 1.32;
      }

      if (havePointer) {
        camera.position.x += (camBase.x + pointerNx * 0.4 - camera.position.x) * 0.04;
        camera.position.y += (camBase.y + pointerNy * 0.25 - camera.position.y) * 0.04;
      } else {
        camera.position.x += (camBase.x - camera.position.x) * 0.04;
        camera.position.y += (camBase.y - camera.position.y) * 0.04;
      }
      camera.lookAt(0, 0.5, 0);

      renderer.render(scene, camera);
    }
    animate();
  }
})();
