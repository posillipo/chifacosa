/*!
 * Effetti animati per il tema pubblico "Cinema Pop": popcorn dorati che salgono dal basso della
 * pagina in continuo, generati e riciclati uno alla volta — stesso schema di napoli-fx.js
 * (elementi CSS animati, nessun canvas/WebGL). Ogni popcorn ha 4 angoli arrotondati in modo
 * diverso e casuale così sembra "bitorzoluto" invece di un cerchio perfetto. Il resto del tema
 * (bagliore da faretto, pellicola con fori) è puro CSS, sempre presente anche se questo script
 * non gira. Rispetta prefers-reduced-motion: se richiesto, non genera alcuna particella.
 */
(function () {
    var container = document.getElementById('cinemapop-bg');
    if (!container) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var colors = ['#fff4d6', '#ffe17d', '#ffc738', '#fff8ea'];
    var maxParticles = 22;

    function randomRadius() {
        // quattro angoli scelti a caso tra due raggi, per un contorno irregolare "da popcorn"
        var a = function () { return (40 + Math.random() * 40) + '%'; };
        return a() + ' ' + a() + ' ' + a() + ' ' + a() + ' / ' + a() + ' ' + a() + ' ' + a() + ' ' + a();
    }

    function spawnParticle() {
        var el = document.createElement('span');
        el.className = 'cinemapop-kernel';
        var size = 9 + Math.random() * 9;
        el.style.width = size + 'px';
        el.style.height = size + 'px';
        el.style.left = Math.random() * 100 + '%';
        el.style.background = colors[Math.floor(Math.random() * colors.length)];
        el.style.borderRadius = randomRadius();
        var duration = 10 + Math.random() * 9;
        el.style.animationDuration = duration + 's';
        el.style.opacity = String(0.6 + Math.random() * 0.35);
        container.appendChild(el);
        setTimeout(function () {
            if (el.parentNode) el.parentNode.removeChild(el);
        }, duration * 1000 + 200);
    }

    for (var i = 0; i < maxParticles; i++) {
        setTimeout(spawnParticle, i * 280);
    }
    setInterval(spawnParticle, 760);
})();
