// Tema "Giardino Anomalo" — bolle di vetro colorate che fluttuano libere sullo schermo,
// una per ogni voce del menu del profilo. Evoluzione dell'esperimento creativo open-source
// "Garden Anomaly": non più una singola sfera-guscio con gocce dentro, ma tante piccole
// sfere di vetro indipendenti (materiale fisico reale, trasmissione + iridescenza), ognuna
// con un colore/tinta diversa, che salgono lentamente e ondeggiano, si respingono a vicenda
// senza mai sovrapporsi del tutto, e si possono trascinare (tornano in posizione da sole) o
// toccare per andare alla vera pagina collegata.
import * as THREE from 'three';
import { pass } from 'three/tsl';
import { bloom } from 'three/addons/tsl/display/BloomNode.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';

const DATA = window.__GA_DATA__ || { navItems: [], assetBase: '/assets/themes/garden-anomaly', accent: '#1f7cff' };
const navItems = DATA.navItems || [];
const ASSET = DATA.assetBase;

const loaderEl = document.getElementById('ga-loader');
const fallbackEl = document.getElementById('ga-fallback');
const hoverLabelEl = document.getElementById('ga-hover-label');
const soundBtn = document.getElementById('ga-sound-toggle');

function showFallback() {
  if (loaderEl) loaderEl.classList.add('hidden');
  if (fallbackEl) fallbackEl.classList.add('show');
}

if (!navigator.gpu) {
  showFallback();
} else {
  init().catch(() => showFallback());
}

async function init() {
  // Il vetro con trasmissione reale + iridescenza + bloom è pesante per le GPU dei telefoni
  // (WebGPU su mobile è ancora giovane): su mobile usiamo una versione "leggera" — vetro
  // semi-trasparente semplice invece della trasmissione fisica, niente bloom, meno
  // dettaglio geometrico — per evitare che la scena si blocchi dopo il primo fotogramma.
  const isMobile = /Android|iPhone|iPad|iPod|Mobi/i.test(navigator.userAgent)
    || (navigator.maxTouchPoints > 1 && /Mac/i.test(navigator.platform || navigator.userAgent));

  const prm = {
    riseSpeed: 0.14,       // salita costante, lenta (unità/s)
    swayAmp: 0.55, swayFreq: 0.35,       // ondeggiamento orizzontale
    bobAmp: 0.22, bobFreq: 0.5,          // ondeggiamento in profondità
    springK: 5.5, springDamp: 4.2,       // molla che riporta una bolla al suo percorso dopo un trascinamento
    hoverPush: 0.9,        // spinta di disturbo al passaggio del mouse (senza premere)
    dragPush: 2.4,         // spinta quando si trascina una bolla
    repelDist: 0.18,       // margine minimo extra tra bolle vicine
    roughness: 0.16, ior: 1.24, thickness: 0.55, iridescence: 0.75, iridescenceIOR: 1.3,
    attenuationDistance: 0.9, envInt: 1.5,
    sndOn: false, sndVol: 0.14,
    bloomStrength: 0.045, bloomRadius: 0.15, bloomThreshold: 0.7, exposure: 0.95,
  };

  const N = Math.max(navItems.length, 1);
  const SPHERE_SEGS = isMobile ? 22 : 48;

  const renderer = new THREE.WebGPURenderer({ antialias: !isMobile });
  renderer.setPixelRatio(Math.min(devicePixelRatio, isMobile ? 1.5 : 2));
  renderer.setSize(innerWidth, innerHeight);
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = prm.exposure;
  renderer.domElement.id = 'ga-canvas';
  document.body.appendChild(renderer.domElement);
  await renderer.init();

  const scene = new THREE.Scene();
  scene.background = new THREE.Color('#c9d2de');

  try {
    const t = await new THREE.TextureLoader().loadAsync(ASSET + '/equiRectGarden.jpg');
    t.mapping = THREE.EquirectangularReflectionMapping;
    t.colorSpace = THREE.SRGBColorSpace;
    scene.background = t;
    scene.environment = t;
  } catch (e) { /* resta il colore di sfondo */ }

  const CAM_Z = 8;
  const camera = new THREE.PerspectiveCamera(45, innerWidth / innerHeight, 0.1, 60);
  camera.position.set(0, 0, CAM_Z);
  camera.lookAt(0, 0, 0);

  const pmrem = new THREE.PMREMGenerator(renderer);
  const roomEnv = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
  scene.environment = scene.environment || roomEnv;
  scene.environmentIntensity = prm.envInt;

  const key = new THREE.DirectionalLight(0xffffff, 1.2);
  key.position.set(2.5, 4, 3);
  scene.add(key);
  scene.add(new THREE.AmbientLight(0xffffff, 0.6));

  // area visibile a profondità CAM_Z, per distribuire e far "rinascere" le bolle sempre
  // dentro (o appena fuori) dallo schermo, qualunque sia la finestra
  function visibleSize(depth) {
    const vFov = (camera.fov * Math.PI) / 180;
    const h = 2 * Math.tan(vFov / 2) * depth;
    return { w: h * camera.aspect, h };
  }
  let { w: viewW, h: viewH } = visibleSize(CAM_Z);
  let boundX = viewW * 0.42;
  let boundY = viewH * 0.42;
  let riseSpan = viewH + 5; // il "giro" avviene sempre fuori dallo schermo, mai visto

  let normalTex = null, roughTex = null;
  if (!isMobile) {
    try {
      normalTex = await new THREE.TextureLoader().loadAsync(ASSET + '/normal.webp');
      normalTex.wrapS = normalTex.wrapT = THREE.RepeatWrapping;
      roughTex = await new THREE.TextureLoader().loadAsync(ASSET + '/rough.jpg');
      roughTex.wrapS = roughTex.wrapT = THREE.RepeatWrapping;
    } catch (e) { /* bolle senza dettaglio superficiale, va bene lo stesso */ }
  }

  function makeLabelSprite(text, colorCss) {
    const c = document.createElement('canvas');
    c.width = 256; c.height = 64;
    const g = c.getContext('2d');
    g.font = '600 30px "Space Grotesk", sans-serif';
    const w = Math.min(g.measureText(text).width + 40, 250);
    g.clearRect(0, 0, 256, 64);
    g.fillStyle = 'rgba(20,20,28,0.82)';
    g.beginPath();
    g.roundRect((256 - w) / 2, 12, w, 40, 20);
    g.fill();
    g.fillStyle = colorCss;
    g.beginPath();
    g.arc((256 - w) / 2 + 20, 32, 6, 0, Math.PI * 2);
    g.fill();
    g.fillStyle = '#fff';
    g.textBaseline = 'middle';
    g.fillText(text, (256 - w) / 2 + 34, 33);
    const tex = new THREE.CanvasTexture(c);
    tex.colorSpace = THREE.SRGBColorSpace;
    const mat = new THREE.SpriteMaterial({ map: tex, transparent: true, depthTest: true });
    const spr = new THREE.Sprite(mat);
    spr.scale.set(0.9, 0.9 * (64 / 256), 1);
    return spr;
  }

  // ── crea una bolla di vetro reale (non istanziata: sono poche, ognuna può avere una
  // propria tinta di vetro — attenuationColor — che è il modo fisicamente corretto di
  // colorare del vetro trasparente senza renderlo opaco) ──
  const bubbles = [];
  for (let i = 0; i < N; i++) {
    const item = navItems[i];
    const radius = 0.42 + ((i * 37) % 5) * 0.045; // piccola varietà di dimensione, deterministica
    const geo = new THREE.SphereGeometry(radius, SPHERE_SEGS, SPHERE_SEGS);
    const bubbleColor = new THREE.Color(item ? item.color : '#1f7cff');
    // Su mobile: vetro colorato semplice (opacità + clearcoat), niente trasmissione reale né
    // iridescenza — sono le due funzioni più pesanti per il driver WebGPU del telefono.
    const mat = isMobile
      ? new THREE.MeshPhysicalMaterial({
          color: bubbleColor,
          transparent: true,
          opacity: 0.62,
          roughness: 0.28,
          metalness: 0,
          clearcoat: 0.6,
          clearcoatRoughness: 0.2,
          envMapIntensity: prm.envInt,
        })
      : new THREE.MeshPhysicalMaterial({
          transmission: 1.0,
          thickness: prm.thickness,
          ior: prm.ior,
          roughness: prm.roughness,
          metalness: 0,
          iridescence: prm.iridescence,
          iridescenceIOR: prm.iridescenceIOR,
          iridescenceThicknessRange: [100, 320],
          clearcoat: 1.0,
          clearcoatRoughness: 0.12,
          envMapIntensity: prm.envInt,
          attenuationColor: bubbleColor,
          attenuationDistance: prm.attenuationDistance,
        });
    if (!isMobile) {
      if (roughTex) { mat.roughnessMap = roughTex; }
      if (normalTex) { mat.normalMap = normalTex; mat.normalScale = new THREE.Vector2(0.35, 0.35); }
    }
    const mesh = new THREE.Mesh(geo, mat);
    mesh.userData.idx = i;
    scene.add(mesh);

    const label = item ? makeLabelSprite(item.label, item.color) : null;
    if (label) scene.add(label);

    const spreadX = N > 1 ? (i / (N - 1) - 0.5) * boundX * 1.7 : 0;
    bubbles.push({
      mesh, label, radius,
      homeX: spreadX + (Math.random() - 0.5) * boundX * 0.25,
      homeZ: (Math.random() - 0.5) * 1.8,
      riseSeed: Math.random() * riseSpan,
      riseSpeed: prm.riseSpeed * (0.75 + Math.random() * 0.5),
      swayPhase: Math.random() * Math.PI * 2,
      bobPhase: Math.random() * Math.PI * 2,
      swayFreq: prm.swayFreq * (0.8 + Math.random() * 0.4),
      drift: new THREE.Vector3(),
      driftVel: new THREE.Vector3(),
      pos: new THREE.Vector3(),
    });
  }

  // ── audio procedurale (spento di default) ──
  const SND = { ctx: null, master: null, last: 0, voices: 0 };
  function ensureAudio() {
    if (SND.ctx) { if (SND.ctx.state === 'suspended') SND.ctx.resume(); return; }
    const C = window.AudioContext || window.webkitAudioContext;
    if (!C) return;
    SND.ctx = new C();
    const ctx = SND.ctx;
    const comp = ctx.createDynamicsCompressor();
    comp.threshold.value = -18; comp.ratio.value = 6;
    comp.connect(ctx.destination);
    SND.master = ctx.createGain();
    SND.master.gain.value = prm.sndVol;
    const dry = ctx.createGain(); dry.gain.value = 0.75;
    const wet = ctx.createGain(); wet.gain.value = 0.55;
    const dl = ctx.createDelay(0.03); dl.delayTime.value = 0.0045;
    const fb = ctx.createGain(); fb.gain.value = 0.35;
    const lfo = ctx.createOscillator(); lfo.frequency.value = 0.45;
    const lfoAmt = ctx.createGain(); lfoAmt.gain.value = 0.0018;
    lfo.connect(lfoAmt); lfoAmt.connect(dl.delayTime); lfo.start();
    SND.master.connect(dry); dry.connect(comp);
    SND.master.connect(dl); dl.connect(fb); fb.connect(dl);
    dl.connect(wet); wet.connect(comp);
  }
  const PENTA = [261.63, 293.66, 329.63, 392.00, 440.00, 523.25, 587.33, 659.25, 784.00, 880.00];
  function playHit(idx, vel, x) {
    if (!prm.sndOn || !SND.ctx || SND.ctx.state !== 'running') return;
    const t = SND.ctx.currentTime;
    if (t - SND.last < 0.08 || SND.voices > 8) return;
    SND.last = t; SND.voices++;
    const f = PENTA[idx % PENTA.length];
    const vol = Math.min(vel / 3, 1) * 0.7;
    const p = SND.ctx.createStereoPanner();
    p.pan.value = Math.max(-1, Math.min(1, x / boundX));
    p.connect(SND.master);
    const partials = [[1.0, 1.0, 0.60], [1.5, 0.32, 0.45], [2.0, 0.28, 0.40], [3.0, 0.10, 0.25]];
    let ended = 0;
    for (const [ratio, amp, dur] of partials) {
      const o = SND.ctx.createOscillator();
      const g = SND.ctx.createGain();
      o.type = 'sine';
      if (ratio === 1.0) {
        o.frequency.setValueAtTime(f * 1.05, t);
        o.frequency.exponentialRampToValueAtTime(f, t + 0.10);
      } else o.frequency.value = f * ratio;
      g.gain.setValueAtTime(0.0001, t);
      g.gain.exponentialRampToValueAtTime(Math.max(vol * amp, 0.001), t + 0.012);
      g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
      o.connect(g); g.connect(p);
      o.start(t); o.stop(t + dur + 0.05);
      o.onended = () => { if (++ended === partials.length) SND.voices--; };
    }
  }
  if (soundBtn) {
    soundBtn.addEventListener('click', () => {
      prm.sndOn = !prm.sndOn;
      ensureAudio();
      soundBtn.setAttribute('aria-pressed', String(prm.sndOn));
      soundBtn.textContent = prm.sndOn ? '🔊' : '🔈';
    });
  }

  // ── posizione "base" di ogni bolla nel tempo: salita continua che si "riavvolge" sempre
  // fuori dallo schermo (mai un salto visibile), più un dolce ondeggiare laterale/in
  // profondità — funzione pura del tempo, quindi stabile per costruzione (niente deriva) ──
  function basePos(b, t, out) {
    const y = (((b.riseSeed + t * b.riseSpeed) % riseSpan) + riseSpan) % riseSpan - riseSpan / 2;
    const x = b.homeX + Math.sin(t * b.swayFreq + b.swayPhase) * prm.swayAmp;
    const z = b.homeZ + Math.sin(t * prm.bobFreq + b.bobPhase) * prm.bobAmp;
    out.set(x, y, z);
    return out;
  }

  const _base = new THREE.Vector3();
  function step(dt, t) {
    // molla che riporta lo scostamento da trascinamento/disturbo a zero
    for (const b of bubbles) {
      b.driftVel.addScaledVector(b.drift, -prm.springK * dt);
      b.driftVel.multiplyScalar(Math.max(0, 1 - prm.springDamp * dt));
      b.drift.addScaledVector(b.driftVel, dt);
    }
    // posizione grezza
    for (const b of bubbles) {
      basePos(b, t, _base);
      b.pos.copy(_base).add(b.drift);
    }
    // repulsione morbida: se due bolle si sovrappongono troppo, le allontana un poco
    for (let i = 0; i < bubbles.length; i++) {
      for (let j = i + 1; j < bubbles.length; j++) {
        const a = bubbles[i], c = bubbles[j];
        const dx = a.pos.x - c.pos.x, dy = a.pos.y - c.pos.y, dz = a.pos.z - c.pos.z;
        const d = Math.hypot(dx, dy, dz);
        const minD = a.radius + c.radius + prm.repelDist;
        if (d > 1e-4 && d < minD) {
          const push = (minD - d) / d * 0.5;
          a.pos.x += dx * push; a.pos.y += dy * push; a.pos.z += dz * push;
          c.pos.x -= dx * push; c.pos.y -= dy * push; c.pos.z -= dz * push;
        }
      }
    }
    for (const b of bubbles) {
      const isHover = b.mesh.userData.idx === hoveredIdx;
      const s = isHover ? b.radius * 1.15 : b.radius;
      b.mesh.position.copy(b.pos);
      b.mesh.scale.setScalar(s / b.radius);
      if (b.label) b.label.position.set(b.pos.x, b.pos.y + b.radius + 0.24, b.pos.z);
    }
  }

  // ── interazione ──
  const ray = new THREE.Raycaster();
  const ndc = new THREE.Vector2();
  const ndcPrev = new THREE.Vector2();
  const downNdc = new THREE.Vector2();
  const camRight = new THREE.Vector3();
  const camUp = new THREE.Vector3();
  const _w = new THREE.Vector3();
  const meshList = bubbles.map((b) => b.mesh);
  let downTime = 0;
  let downIdx = -1;
  let hoveredIdx = -1;
  let dragging = false;

  function toNdc(e, out) {
    out.set((e.clientX / innerWidth) * 2 - 1, -(e.clientY / innerHeight) * 2 + 1);
  }
  // Raycasting preciso contro le vere mesh delle bolle: Three.js le ordina per distanza
  // reale dalla telecamera, quindi quella "davanti" vince sempre — niente scambi.
  function preciseHit(pointerNdc) {
    ray.setFromCamera(pointerNdc, camera);
    const hits = ray.intersectObjects(meshList, false);
    return hits.length ? hits[0].object.userData.idx : -1;
  }

  renderer.domElement.addEventListener('pointerdown', (e) => {
    ensureAudio();
    toNdc(e, ndc);
    ndcPrev.copy(ndc); downNdc.copy(ndc);
    downTime = performance.now();
    downIdx = preciseHit(ndc);
    hoveredIdx = downIdx;
    dragging = downIdx >= 0;
  });

  renderer.domElement.addEventListener('pointermove', (e) => {
    toNdc(e, ndc);
    const dxN = ndc.x - ndcPrev.x, dyN = ndc.y - ndcPrev.y;
    ndcPrev.copy(ndc);

    camera.matrixWorld.extractBasis(camRight, camUp, _w);
    const worldDx = camRight.clone().multiplyScalar(dxN * boundX * 2);
    const worldDy = camUp.clone().multiplyScalar(dyN * boundY * 2);

    if (dragging && downIdx >= 0) {
      // trascinare una bolla la spinge direttamente: torna in posizione da sola grazie alla molla
      const b = bubbles[downIdx];
      b.driftVel.addScaledVector(worldDx.add(worldDy), prm.dragPush);
    } else if (e.buttons === 0) {
      // passare il mouse vicino a una bolla (senza premere) la disturba appena, come "farla tintinnare"
      const speed = Math.hypot(dxN, dyN);
      if (speed > 0.0006) {
        ray.setFromCamera(ndc, camera);
        const o = ray.ray.origin, dir = ray.ray.direction;
        for (const b of bubbles) {
          const px = b.pos.x - o.x, py = b.pos.y - o.y, pz = b.pos.z - o.z;
          const tt = px * dir.x + py * dir.y + pz * dir.z;
          const cx = px - dir.x * tt, cy = py - dir.y * tt, cz = pz - dir.z * tt;
          const d = Math.hypot(cx, cy, cz);
          if (d < b.radius + 0.16) {
            b.driftVel.addScaledVector(worldDx.clone().add(worldDy), prm.hoverPush);
            playHit(b.mesh.userData.idx, speed * 20, b.pos.x);
          }
        }
      }
    }

    hoveredIdx = preciseHit(ndc);
    if (hoveredIdx >= 0 && navItems[hoveredIdx]) {
      hoverLabelEl.textContent = navItems[hoveredIdx].label;
      hoverLabelEl.style.left = e.clientX + 'px';
      hoverLabelEl.style.top = (e.clientY - 30) + 'px';
      hoverLabelEl.style.display = 'block';
      renderer.domElement.style.cursor = 'pointer';
    } else {
      hoverLabelEl.style.display = 'none';
      renderer.domElement.style.cursor = dragging ? 'grabbing' : 'grab';
    }
  });

  function endGesture(e) {
    if (downIdx >= 0 && navItems[downIdx]) {
      const dt = performance.now() - downTime;
      toNdc(e, ndc);
      const moved = ndc.distanceTo(downNdc);
      const upIdx = preciseHit(ndc);
      // naviga solo se il tocco è breve, quasi fermo, e la bolla sotto al rilascio è
      // esattamente la stessa premuta all'inizio
      if (dt < 350 && moved < 0.02 && upIdx === downIdx) {
        window.location.href = navItems[downIdx].url;
        return;
      }
    }
    dragging = false;
    downIdx = -1;
  }
  renderer.domElement.addEventListener('pointerup', endGesture);
  renderer.domElement.addEventListener('pointercancel', () => { dragging = false; downIdx = -1; });
  renderer.domElement.addEventListener('pointerleave', () => {
    if (!dragging) { hoveredIdx = -1; hoverLabelEl.style.display = 'none'; }
  });

  // ── post-processing: il bloom via TSL è un ulteriore costo per il driver, saltato su
  // mobile — su desktop resta l'aspetto lucido/luminoso già collaudato ──
  let renderFrame;
  if (isMobile) {
    renderFrame = () => renderer.render(scene, camera);
  } else {
    const pipeline = new THREE.RenderPipeline(renderer);
    const scenePass = pass(scene, camera);
    const sceneColor = scenePass.getTextureNode();
    const bloomPass = bloom(sceneColor, prm.bloomStrength, prm.bloomRadius, prm.bloomThreshold);
    pipeline.outputNode = sceneColor.add(bloomPass);
    renderFrame = () => pipeline.render();
  }

  addEventListener('resize', () => {
    camera.aspect = innerWidth / innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(innerWidth, innerHeight);
    const size = visibleSize(CAM_Z);
    viewW = size.w; viewH = size.h;
    boundX = viewW * 0.42; boundY = viewH * 0.42;
    riseSpan = viewH + 5;
  });

  const timer = new THREE.Timer();
  let firstFrame = true;
  // Rete di sicurezza "a battito": se il ciclo di rendering non produce un fotogramma da
  // qualche secondo — sia perché non parte mai, sia perché si blocca a metà (il caso più
  // insidioso: un errore lì non viene mai intercettato da fuori) — mostriamo l'elenco di
  // link invece di lasciare la scena congelata senza spiegazione.
  let lastAlive = Date.now();
  const watchdog = setInterval(() => {
    if (Date.now() - lastAlive > 5000) {
      clearInterval(watchdog);
      renderer.setAnimationLoop(null);
      showFallback();
    }
  }, 1500);
  renderer.setAnimationLoop(() => {
    try {
      timer.update();
      const dt = Math.min(timer.getDelta(), 1 / 30);
      step(dt, timer.elapsed);
      renderFrame();
      lastAlive = Date.now();
      if (firstFrame) {
        firstFrame = false;
        if (loaderEl) loaderEl.classList.add('hidden');
      }
    } catch (err) {
      clearInterval(watchdog);
      renderer.setAnimationLoop(null);
      showFallback();
    }
  });
}
