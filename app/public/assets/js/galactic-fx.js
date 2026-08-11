/*!
 * Effetti animati per il tema pubblico "Console Galattica": campo stellare su canvas che simula
 * un viaggio nell'iperspazio (proiezione prospettica, stelle che si muovono verso l'osservatore
 * e diventano naturalmente strisce di luce più sono vicine — nessuna libreria esterna, solo
 * Canvas 2D), un breve "salto" (accelerazione temporanea) al passaggio del mouse sui pulsanti
 * principali, un segnale luminoso che deriva raramente sullo schermo come piccolo easter egg, e
 * un accenno sonoro sintetizzato via Web Audio API (nessun file audio, nessun suono campionato
 * da film — spento di default, l'utente lo accende dal pulsante dedicato).
 *
 * Rispetta prefers-reduced-motion: niente loop di animazione (solo un campo stellare statico
 * disegnato una volta), nessun easter egg, nessun "salto" al passaggio del mouse.
 */
(function () {
    var container = document.getElementById('galactic-bg');
    if (!container) return;
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ----- Campo stellare / iperspazio -----
    var canvas = document.createElement('canvas');
    canvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;display:block;';
    container.appendChild(canvas);
    var ctx = canvas.getContext('2d');

    var W, H, cx, cy;
    function resize() {
        W = canvas.width = window.innerWidth;
        H = canvas.height = window.innerHeight;
        cx = W / 2; cy = H / 2;
    }
    resize();
    window.addEventListener('resize', resize);

    var STAR_COUNT = 220;
    var stars = [];
    function resetStar(s) {
        s.x = (Math.random() - 0.5) * W;
        s.y = (Math.random() - 0.5) * H;
        s.z = W;
        s.pz = s.z;
    }
    for (var i = 0; i < STAR_COUNT; i++) {
        var s = { x: 0, y: 0, z: 0, pz: 0 };
        resetStar(s);
        s.z = Math.random() * W; // distribuite subito, non tutte appena nate al centro
        s.pz = s.z;
        stars.push(s);
    }

    var baseSpeed = 2.2;
    var boostUntil = 0;

    // "Salto nell'iperspazio": passando sopra un pulsante principale o il tasto Segui, il campo
    // stellare accelera per un attimo, le stelle si allungano in strisce di luce.
    document.addEventListener('mouseenter', function (e) {
        var t = e.target && e.target.closest && e.target.closest('.color-link-btn, .segui-pill');
        if (t) { boostUntil = Date.now() + 750; }
    }, true);

    function drawFrame() {
        var now = Date.now();
        var speed = now < boostUntil ? 26 : baseSpeed;
        ctx.fillStyle = 'rgba(5,4,15,0.4)';
        ctx.fillRect(0, 0, W, H);
        for (var i = 0; i < stars.length; i++) {
            var s = stars[i];
            s.pz = s.z;
            s.z -= speed;
            if (s.z <= 1) { resetStar(s); continue; }
            var sx = (s.x / s.z) * W + cx;
            var sy = (s.y / s.z) * H + cy;
            var px = (s.x / s.pz) * W + cx;
            var py = (s.y / s.pz) * H + cy;
            var depth = 1 - s.z / W;
            ctx.strokeStyle = 'rgba(191,232,255,' + Math.min(1, depth * 1.4).toFixed(2) + ')';
            ctx.lineWidth = Math.max(0.6, depth * 2.4);
            ctx.beginPath();
            ctx.moveTo(px, py);
            ctx.lineTo(sx, sy);
            ctx.stroke();
        }
    }

    if (reducedMotion) {
        // Campo stellare statico, disegnato una sola volta: niente loop, niente "salto".
        ctx.fillStyle = '#05040f';
        ctx.fillRect(0, 0, W, H);
        ctx.fillStyle = '#bfe8ff';
        for (var d = 0; d < 140; d++) {
            var rx = Math.random() * W, ry = Math.random() * H, r = Math.random() * 1.4;
            ctx.beginPath(); ctx.arc(rx, ry, r, 0, Math.PI * 2); ctx.fill();
        }
    } else {
        (function loop() { drawFrame(); requestAnimationFrame(loop); })();

        // Piccolo easter egg: un segnale luminoso che attraversa lo schermo raramente, non le
        // sembianze di alcun personaggio specifico — solo un dettaglio da scoprire con calma.
        function spawnEasterOrb() {
            var orb = document.createElement('div');
            orb.className = 'galactic-easter-orb';
            container.appendChild(orb);
            setTimeout(function () {
                if (orb.parentNode) orb.parentNode.removeChild(orb);
            }, 9200);
            setTimeout(spawnEasterOrb, 30000 + Math.random() * 25000);
        }
        setTimeout(spawnEasterOrb, 20000 + Math.random() * 15000);
    }

    // ----- Audio sintetizzato (nessun file, nessun suono campionato) -----
    var audioEnabled = localStorage.getItem('galacticSound') === '1';
    var audioCtx = null;
    function ensureCtx() {
        if (!audioCtx) {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (AC) { try { audioCtx = new AC(); } catch (err) { audioCtx = null; } }
        }
        return audioCtx;
    }
    function blip(freqStart, freqEnd, duration, vol) {
        if (!audioEnabled) return;
        var c = ensureCtx();
        if (!c) return;
        var osc = c.createOscillator();
        var gain = c.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(freqStart, c.currentTime);
        osc.frequency.exponentialRampToValueAtTime(Math.max(freqEnd, 1), c.currentTime + duration);
        gain.gain.setValueAtTime(vol, c.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, c.currentTime + duration);
        osc.connect(gain).connect(c.destination);
        osc.start();
        osc.stop(c.currentTime + duration);
    }
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest && e.target.closest('.color-link-btn, .segui-pill');
        if (t) blip(880, 220, 0.18, 0.05);
    }, true);

    var toggleBtn = document.getElementById('galactic-sound-toggle');
    if (toggleBtn) {
        function refreshToggle() {
            toggleBtn.innerHTML = audioEnabled
                ? '<i class="fa-solid fa-volume-high"></i>'
                : '<i class="fa-solid fa-volume-xmark"></i>';
            toggleBtn.setAttribute('aria-pressed', audioEnabled ? 'true' : 'false');
            toggleBtn.title = audioEnabled ? 'Disattiva i suoni' : 'Attiva i suoni';
        }
        refreshToggle();
        toggleBtn.addEventListener('click', function () {
            audioEnabled = !audioEnabled;
            localStorage.setItem('galacticSound', audioEnabled ? '1' : '0');
            refreshToggle();
            if (audioEnabled) blip(500, 760, 0.15, 0.05);
        });
    }
})();
