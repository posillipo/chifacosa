// Tema "Giardino Anomalo" — sfera di vetro WebGPU/TSL con gocce fisiche che rappresentano
// le voci del menu del profilo. Adattamento dell'esperimento creativo open-source
// "Garden Anomaly": stessa fisica/shader del vetro, senza il pannello di debug (Tweakpane),
// con gocce meno numerose ma più grandi e distinte per colore/etichetta, cliccabili per
// portare alla vera pagina (Timeline, Blog, ecc.) invece di generare contenuto nella scena.
import * as THREE from 'three';
import {
  uv, vec3, vec4, smoothstep, pow, uniform, pass, uniformArray,
  positionGeometry, normalize, max, float, exp, transformNormalToView,
  texture, vec2, cross,
} from 'three/tsl';
import { bloom } from 'three/addons/tsl/display/BloomNode.js';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
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
  const prm = {
    simSpeed: 0.37, gravity: 1.65, throwPow: 1.04, friction: 3.91, damp: 0.9,
    ior: 1.30, thickness: 1.42, roughness: 0.36, nrmScale: 1.0, irid: 0.90, iridIor: 1.55,
    envInt: 2.12, bulgeAmt: 0.15, bulgeSharp: 43.30, bulgeRel: 2.77,
    attColor: '#0033ff', attDist: 8.40, dentAO: 3.20, dentNrm: 1.96,
    scanInt: 0.45, scanSpeed: 0.18, rotY: 0.10, rotX: 0.04,
    blobRough: 0.30, blobCoat: 0.35,
    blowPow: 5.22, blowDelay: 0.6,
    sndOn: false, sndVol: 0.12,
    bloomStrength: 0.02, bloomRadius: 0.0, bloomThreshold: 0.76, exposure: 0.85,
  };

  const SHELL_R = 1.3;
  const N_BLOBS = Math.max(navItems.length, 1);

  const renderer = new THREE.WebGPURenderer({ antialias: true });
  renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
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

  const camera = new THREE.PerspectiveCamera(38, innerWidth / innerHeight, 0.1, 60);
  camera.position.set(0, 0.08, 7.6);

  const controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  controls.dampingFactor = 0.06;
  controls.target.set(0, 0, 0);

  const pmrem = new THREE.PMREMGenerator(renderer);
  const roomEnv = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
  scene.environment = scene.environment || roomEnv;
  scene.environmentIntensity = prm.envInt;

  const key = new THREE.DirectionalLight(0xffffff, 1.2);
  key.position.set(2.5, 4, 3);
  scene.add(key);
  scene.add(new THREE.AmbientLight(0xffffff, 0.55));

  // ── guscio di vetro ──
  const N_BULGE = 8;
  const bulgeVecs = Array.from({ length: N_BULGE }, () => new THREE.Vector4(0, 1, 0, 0));
  const uBulges = uniformArray(bulgeVecs);
  const uBulgeSharp = uniform(prm.bulgeSharp);

  const shellMat = new THREE.MeshPhysicalNodeMaterial({
    transmission: 1.0, thickness: prm.thickness, ior: prm.ior, roughness: prm.roughness,
    metalness: 0, iridescence: prm.irid, iridescenceIOR: prm.iridIor,
    iridescenceThicknessRange: [140, 380], clearcoat: 1.0, clearcoatRoughness: 0.10,
    specularIntensity: 1.0, envMapIntensity: prm.envInt,
    attenuationColor: new THREE.Color(prm.attColor), attenuationDistance: prm.attDist,
  });

  const uDentAO = uniform(prm.dentAO);
  const uDentNrm = uniform(prm.dentNrm);
  const uScan = uniform(0);

  const buildBump = () => {
    const nrm = normalize(positionGeometry);
    let bump = float(0);
    let grad = vec3(0, 0, 0);
    for (let k = 0; k < N_BULGE; k++) {
      const b = uBulges.element(k);
      const u = max(nrm.dot(b.xyz), 0.0);
      bump = bump.add(b.w.mul(pow(u, uBulgeSharp)));
      grad = grad.add(b.xyz.sub(nrm.mul(u)).mul(b.w.mul(uBulgeSharp).mul(pow(u, uBulgeSharp.sub(1)))));
    }
    return { nrm, bump, grad: grad.div(SHELL_R) };
  };

  const v = buildBump();
  shellMat.positionNode = positionGeometry.add(v.nrm.mul(v.bump));

  const f = buildBump();
  const normalTex = await new THREE.TextureLoader().loadAsync(ASSET + '/normal.webp');
  normalTex.wrapS = normalTex.wrapT = THREE.RepeatWrapping;
  const tN = texture(normalTex, uv().mul(2)).xyz.mul(2).sub(1);
  const T = normalize(cross(vec3(0, 1, 0), f.nrm).add(vec3(1e-4, 0, 0)));
  const B = cross(f.nrm, T);
  shellMat.normalNode = transformNormalToView(normalize(
    f.nrm.mul(tN.z).add(T.mul(tN.x)).add(B.mul(tN.y)).sub(f.grad.mul(uDentNrm))
  ));
  const ao = exp(f.bump.mul(uDentAO).negate());
  shellMat.colorNode = vec3(ao);
  shellMat.aoNode = ao;

  const scanCanvas = document.createElement('canvas');
  scanCanvas.width = 4; scanCanvas.height = 1024;
  const sg = scanCanvas.getContext('2d');
  sg.fillStyle = '#000'; sg.fillRect(0, 0, 4, 1024);
  for (let i = 0; i < 22; i++) {
    const y = Math.random() * 1024, h = 1 + Math.random() * 3, a = 0.35 + Math.random() * 0.65;
    sg.fillStyle = `rgba(255,255,255,${a})`;
    sg.fillRect(0, y, 4, h); sg.fillRect(0, y - 1024, 4, h); sg.fillRect(0, y + 1024, 4, h);
  }
  const scanTex = new THREE.CanvasTexture(scanCanvas);
  scanTex.wrapS = scanTex.wrapT = THREE.RepeatWrapping;
  scanTex.colorSpace = THREE.SRGBColorSpace;
  shellMat.emissiveNode = vec3(texture(scanTex, uv().add(vec2(0, uScan))).r).mul(prm.scanInt);

  const roughTex = await new THREE.TextureLoader().loadAsync(ASSET + '/rough.jpg');
  roughTex.wrapS = roughTex.wrapT = THREE.RepeatWrapping;
  shellMat.roughnessNode = texture(roughTex, uv()).r.mul(prm.roughness).mul(2);

  const shell = new THREE.Mesh(new THREE.SphereGeometry(SHELL_R, 96, 96), shellMat);
  scene.add(shell);

  // ── gocce (una per voce di menu) ──
  const blobMat = new THREE.MeshPhysicalMaterial({
    roughness: prm.blobRough, metalness: 0, clearcoat: prm.blobCoat,
    clearcoatRoughness: 0.25, envMapIntensity: 0.55,
  });
  const blobGeo = new THREE.SphereGeometry(1, 32, 32);
  const blobs = new THREE.InstancedMesh(blobGeo, blobMat, N_BLOBS);
  blobs.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
  scene.add(blobs);

  const P = new Float32Array(N_BLOBS * 3);
  const Pp = new Float32Array(N_BLOBS * 3);
  const V = new Float32Array(N_BLOBS * 3);
  const R = new Float32Array(N_BLOBS);
  const touch = new Uint8Array(N_BLOBS);
  const wallPrev = new Uint8Array(N_BLOBS);
  const rushCd = new Float32Array(N_BLOBS);
  const rushPow = new Float32Array(N_BLOBS);
  const restT = new Float32Array(N_BLOBS);

  const bulgeA = new Float32Array(N_BULGE);
  const bulgeAge = new Float32Array(N_BULGE);
  const _bq = new THREE.Quaternion();
  const _bv = new THREE.Vector3();
  function spawnBulge(nx, ny, nz, amp) {
    _bv.set(nx, ny, nz).applyQuaternion(_bq.copy(shell.quaternion).invert());
    nx = _bv.x; ny = _bv.y; nz = _bv.z;
    let k = 0, best = Infinity;
    for (let s = 0; s < N_BULGE; s++) {
      const cur = bulgeA[s] * Math.exp(-bulgeAge[s] * prm.bulgeRel);
      if (cur < best) { best = cur; k = s; }
    }
    bulgeVecs[k].set(nx, ny, nz, 0);
    bulgeA[k] = amp; bulgeAge[k] = 0;
  }
  function updateBulges(dt) {
    for (let k = 0; k < N_BULGE; k++) {
      bulgeAge[k] += dt;
      bulgeVecs[k].w = bulgeA[k] * (1 - Math.exp(-bulgeAge[k] * 18)) * Math.exp(-bulgeAge[k] * prm.bulgeRel);
    }
  }

  const _c = new THREE.Color();
  const _m = new THREE.Matrix4();
  const labelSprites = [];

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

  function seed() {
    for (let i = 0; i < N_BLOBS; i++) {
      R[i] = N_BLOBS <= 1 ? 0.32 : 0.16 + Math.random() * 0.13;
      const a = Math.random() * Math.PI * 2;
      const rr = Math.sqrt(Math.random()) * 0.55;
      P[i * 3 + 0] = Math.cos(a) * rr;
      P[i * 3 + 1] = 0.2 + Math.random() * 0.85;
      P[i * 3 + 2] = Math.sin(a) * rr;
      V[i * 3] = V[i * 3 + 1] = V[i * 3 + 2] = 0;
      restT[i] = 0;
      const item = navItems[i];
      _c.set(item ? item.color : '#1f7cff');
      blobs.setColorAt(i, _c);
      _m.makeScale(R[i], R[i], R[i]);
      _m.setPosition(P[i * 3], P[i * 3 + 1], P[i * 3 + 2]);
      blobs.setMatrixAt(i, _m);
      if (item && !labelSprites[i]) {
        const spr = makeLabelSprite(item.label, item.color);
        scene.add(spr);
        labelSprites[i] = spr;
      }
    }
    blobs.instanceMatrix.needsUpdate = true;
    if (blobs.instanceColor) blobs.instanceColor.needsUpdate = true;
  }
  seed();

  function step(dt) {
    const damp = Math.exp(-prm.damp * dt);
    const invDt = 1 / dt;
    for (let i = 0; i < N_BLOBS; i++) {
      const ix = i * 3;
      Pp[ix] = P[ix]; Pp[ix + 1] = P[ix + 1]; Pp[ix + 2] = P[ix + 2];
      V[ix + 1] -= prm.gravity * dt;
      V[ix] *= damp; V[ix + 1] *= damp; V[ix + 2] *= damp;
      P[ix] += V[ix] * dt; P[ix + 1] += V[ix + 1] * dt; P[ix + 2] += V[ix + 2] * dt;
      touch[i] = 0;
      if (rushCd[i] > 0) rushCd[i] -= dt;
    }
    for (let i = 0; i < N_BLOBS; i++) {
      const ix = i * 3;
      const pr = Math.hypot(P[ix], P[ix + 1], P[ix + 2]);
      const maxR = SHELL_R - 0.04 - R[i];
      const atWall = pr > maxR ? 1 : 0;
      if (atWall && !wallPrev[i] && pr > 1e-5) {
        const nx = P[ix] / pr, ny = P[ix + 1] / pr, nz = P[ix + 2] / pr;
        const vn = V[ix] * nx + V[ix + 1] * ny + V[ix + 2] * nz;
        if (vn > 0.45) {
          const amp = Math.min(vn, 3.5) * prm.bulgeAmt * (0.4 + R[i] * 2.5);
          spawnBulge(nx, ny, nz, amp);
          playHit(i, vn, P[ix]);
        }
      }
      wallPrev[i] = atWall;
    }
    for (let it = 0; it < 3; it++) {
      for (let i = 0; i < N_BLOBS; i++) {
        const ix = i * 3;
        for (let j = i + 1; j < N_BLOBS; j++) {
          const jx = j * 3;
          const dx = P[ix] - P[jx], dy = P[ix + 1] - P[jx + 1], dz = P[ix + 2] - P[jx + 2];
          const d = Math.hypot(dx, dy, dz);
          const minD = R[i] + R[j];
          if (d < minD && d > 1e-5) {
            touch[i] = touch[j] = 1;
            const mi = R[i] ** 3, mj = R[j] ** 3;
            const wi = mj / (mi + mj), wj = mi / (mi + mj);
            const ov = (minD - d) / d;
            P[ix] += dx * ov * wi; P[ix + 1] += dy * ov * wi; P[ix + 2] += dz * ov * wi;
            P[jx] -= dx * ov * wj; P[jx + 1] -= dy * ov * wj; P[jx + 2] -= dz * ov * wj;
            if (it === 0) {
              const vn = ((V[ix] - V[jx]) * dx + (V[ix + 1] - V[jx + 1]) * dy + (V[ix + 2] - V[jx + 2]) * dz) / d;
              if (vn < -1.1) {
                const pw = Math.min(-vn, 4);
                if (rushCd[i] <= 0) { rushPow[i] = Math.max(rushPow[i], pw); rushCd[i] = 0.18; }
                if (rushCd[j] <= 0) { rushPow[j] = Math.max(rushPow[j], pw); rushCd[j] = 0.18; }
              }
            }
          }
        }
      }
      for (let i = 0; i < N_BLOBS; i++) {
        const ix = i * 3;
        const pr = Math.hypot(P[ix], P[ix + 1], P[ix + 2]);
        const maxR = SHELL_R - 0.04 - R[i];
        if (pr > maxR) { touch[i] = 1; const f2 = maxR / pr; P[ix] *= f2; P[ix + 1] *= f2; P[ix + 2] *= f2; }
      }
    }
    const cd = Math.exp(-prm.friction * dt);
    for (let i = 0; i < N_BLOBS; i++) {
      const ix = i * 3;
      V[ix] = (P[ix] - Pp[ix]) * invDt;
      V[ix + 1] = (P[ix + 1] - Pp[ix + 1]) * invDt;
      V[ix + 2] = (P[ix + 2] - Pp[ix + 2]) * invDt;
      if (touch[i]) { V[ix] *= cd; V[ix + 1] *= cd; V[ix + 2] *= cd; }
      if (rushPow[i] > 0) {
        const own = Math.hypot(V[ix], V[ix + 1], V[ix + 2]);
        const spd = Math.min(Math.max(own * 0.6 + rushPow[i] * 0.55, 0.6), 4.5);
        const ra = Math.random() * Math.PI * 2, ry = Math.random() * 1.3 - 0.3;
        const rh = Math.sqrt(Math.max(1 - ry * ry, 0.05));
        V[ix] = Math.cos(ra) * rh * spd; V[ix + 1] = ry * spd; V[ix + 2] = Math.sin(ra) * rh * spd;
        rushPow[i] = 0;
      }
      const spd = Math.hypot(V[ix], V[ix + 1], V[ix + 2]);
      if (spd < 0.15) restT[i] += dt; else restT[i] = 0;
      if (restT[i] > prm.blowDelay) {
        restT[i] = 0;
        const bp = prm.blowPow * (0.7 + Math.random() * 0.8);
        const ba = Math.random() * Math.PI * 2, bh = 0.2 + Math.random() * 0.55;
        V[ix] += Math.cos(ba) * bh * bp;
        V[ix + 1] += bp * (0.8 + Math.random() * 0.5);
        V[ix + 2] += Math.sin(ba) * bh * bp;
      }
      _m.makeScale(R[i], R[i], R[i]);
      _m.setPosition(P[ix], P[ix + 1], P[ix + 2]);
      blobs.setMatrixAt(i, _m);
      const spr = labelSprites[i];
      if (spr) spr.position.set(P[ix], P[ix + 1] + R[i] + 0.22, P[ix + 2]);
    }
    blobs.instanceMatrix.needsUpdate = true;
  }

  // ── audio procedurale (spento di default, come le altre ambientazioni) ──
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
    if (t - SND.last < 0.05 || SND.voices > 8) return;
    SND.last = t; SND.voices++;
    const f = PENTA[idx % PENTA.length];
    const vol = Math.min(vel / 3, 1) * 0.7;
    const p = SND.ctx.createStereoPanner();
    p.pan.value = Math.max(-1, Math.min(1, x / 1.3)) * 0.8;
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

  // ── interazione: hover/trascina per far rimbalzare, tocco breve per navigare ──
  const ray = new THREE.Raycaster();
  const ndc = new THREE.Vector2();
  const ndcPrev = new THREE.Vector2();
  const downNdc = new THREE.Vector2();
  const camRight = new THREE.Vector3();
  const camUp = new THREE.Vector3();
  const _w = new THREE.Vector3();
  let havePrev = false;
  let flingDrag = false;
  let downTime = 0;
  let downIdx = -1;

  function toNdc(e, out) {
    out.set((e.clientX / innerWidth) * 2 - 1, -(e.clientY / innerHeight) * 2 + 1);
  }
  function rayDist(i, o, dir) {
    const ix = i * 3;
    const px = P[ix] - o.x, py = P[ix + 1] - o.y, pz = P[ix + 2] - o.z;
    const t = px * dir.x + py * dir.y + pz * dir.z;
    const cx = px - dir.x * t, cy = py - dir.y * t, cz = pz - dir.z * t;
    return Math.hypot(cx, cy, cz);
  }
  function hitTest(pointerNdc) {
    ray.setFromCamera(pointerNdc, camera);
    const o = ray.ray.origin, dir = ray.ray.direction;
    let best = -1, bestD = Infinity;
    for (let i = 0; i < N_BLOBS; i++) {
      const d = rayDist(i, o, dir);
      if (d < R[i] + 0.14 && d < bestD) { bestD = d; best = i; }
    }
    return best;
  }
  function fling(dxN, dyN) {
    const speed = Math.hypot(dxN, dyN);
    if (speed < 0.0005) return;
    ray.setFromCamera(ndc, camera);
    camera.matrixWorld.extractBasis(camRight, camUp, _w);
    const o = ray.ray.origin, dir = ray.ray.direction;
    for (let i = 0; i < N_BLOBS; i++) {
      if (rayDist(i, o, dir) < R[i] + 0.14) {
        const ix = i * 3;
        const k = prm.throwPow * Math.min(speed * 30, 3) / Math.max(R[i] / 0.18, 0.6);
        const ra = Math.random() * Math.PI * 2;
        const rmag = k * (3 + Math.random() * 10);
        V[ix] += Math.cos(ra) * rmag + (camRight.x * dxN + camUp.x * dyN) * k * 4;
        V[ix + 1] += k * (0.8 + Math.random() * 2.7);
        V[ix + 2] += Math.sin(ra) * rmag + (camRight.z * dxN + camUp.z * dyN) * k * 4;
      }
    }
  }

  renderer.domElement.addEventListener('pointerdown', (e) => {
    ensureAudio();
    toNdc(e, ndc);
    ndcPrev.copy(ndc); downNdc.copy(ndc);
    havePrev = true;
    downTime = performance.now();
    downIdx = hitTest(ndc);
    flingDrag = downIdx >= 0;
    controls.enabled = !flingDrag;
  });

  renderer.domElement.addEventListener('pointermove', (e) => {
    toNdc(e, ndc);
    if (!havePrev) { ndcPrev.copy(ndc); havePrev = true; }
    const dxN = ndc.x - ndcPrev.x, dyN = ndc.y - ndcPrev.y;
    ndcPrev.copy(ndc);
    if (e.buttons === 0 || flingDrag) fling(dxN, dyN);

    const hover = hitTest(ndc);
    if (hover >= 0 && navItems[hover]) {
      hoverLabelEl.textContent = navItems[hover].label;
      hoverLabelEl.style.left = e.clientX + 'px';
      hoverLabelEl.style.top = (e.clientY - 30) + 'px';
      hoverLabelEl.style.display = 'block';
      renderer.domElement.style.cursor = 'pointer';
    } else {
      hoverLabelEl.style.display = 'none';
      renderer.domElement.style.cursor = flingDrag ? 'grabbing' : 'grab';
    }
  });

  function endGesture(e) {
    if (downIdx >= 0 && navItems[downIdx]) {
      const dt = performance.now() - downTime;
      toNdc(e, ndc);
      const moved = ndc.distanceTo(downNdc);
      if (dt < 350 && moved < 0.02) {
        window.location.href = navItems[downIdx].url;
        return;
      }
    }
    flingDrag = false;
    controls.enabled = true;
    downIdx = -1;
  }
  renderer.domElement.addEventListener('pointerup', endGesture);
  renderer.domElement.addEventListener('pointercancel', () => { flingDrag = false; controls.enabled = true; downIdx = -1; });

  // ── post-processing ──
  const pipeline = new THREE.RenderPipeline(renderer);
  const scenePass = pass(scene, camera);
  const sceneColor = scenePass.getTextureNode();
  const bloomPass = bloom(sceneColor, prm.bloomStrength, prm.bloomRadius, prm.bloomThreshold);
  pipeline.outputNode = sceneColor.add(bloomPass);

  addEventListener('resize', () => {
    camera.aspect = innerWidth / innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(innerWidth, innerHeight);
  });

  const timer = new THREE.Timer();
  let firstFrame = true;
  renderer.setAnimationLoop(() => {
    timer.update();
    const rdt = Math.min(timer.getDelta(), 1 / 30);
    const dt = rdt * prm.simSpeed;
    if (dt > 0) { step(dt); updateBulges(dt); }
    shell.rotation.y += prm.rotY * rdt;
    shell.rotation.x += prm.rotX * rdt;
    uScan.value = (uScan.value + prm.scanSpeed * dt) % 1;
    controls.update();
    pipeline.render();
    if (firstFrame) { firstFrame = false; if (loaderEl) loaderEl.classList.add('hidden'); }
  });
}
