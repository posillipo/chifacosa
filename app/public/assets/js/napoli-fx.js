/*!
 * Effetti animati per il tema pubblico "Forza Napoli": coriandoli azzurro/bianco/oro che
 * salgono dal basso della pagina in continuo, generati e riciclati uno alla volta — stesso
 * spirito "sfondo passivo dietro al contenuto" di wave-bg.js/circuit-bg.js, ma qui bastano
 * semplici elementi CSS animati (nessun canvas/WebGL). Il resto del tema (cielo, Vesuvio, sole
 * dietro l'avatar, stelle) è puro CSS, sempre presente anche se questo script non gira.
 * Rispetta prefers-reduced-motion: se richiesto, non genera alcuna particella.
 */
(function () {
    var container = document.getElementById('napoli-bg');
    if (!container) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var colors = ['#eaf6ff', '#ffd447', '#5fc6f7']; // bianco, oro, azzurro chiaro
    var maxParticles = 24;

    function spawnParticle() {
        var el = document.createElement('span');
        el.className = 'napoli-confetti';
        var size = 6 + Math.random() * 7;
        el.style.width = size + 'px';
        el.style.height = size + 'px';
        el.style.left = Math.random() * 100 + '%';
        el.style.background = colors[Math.floor(Math.random() * colors.length)];
        el.style.borderRadius = Math.random() < 0.5 ? '50%' : '2px';
        var duration = 9 + Math.random() * 8;
        el.style.animationDuration = duration + 's';
        el.style.opacity = String(0.55 + Math.random() * 0.4);
        container.appendChild(el);
        setTimeout(function () {
            if (el.parentNode) el.parentNode.removeChild(el);
        }, duration * 1000 + 200);
    }

    for (var i = 0; i < maxParticles; i++) {
        setTimeout(spawnParticle, i * 260);
    }
    setInterval(spawnParticle, 700);
})();
