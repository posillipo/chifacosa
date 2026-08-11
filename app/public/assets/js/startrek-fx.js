/*!
 * Effetti animati per il tema pubblico ispirato a Star Trek: campo stellare che scintilla sullo
 * sfondo e, ogni tanto, una striscia di luce che attraversa lo schermo in diagonale, come un
 * salto nel "warp". Stesso spirito "sfondo passivo dietro al contenuto" di wave-bg.js/
 * circuit-bg.js/napoli-fx.js, generato in JS vanilla senza librerie esterne. Rispetta
 * prefers-reduced-motion: se richiesto, il campo stellare resta fisso (niente scintillio) e
 * nessuna striscia di warp viene generata.
 */
(function () {
    var container = document.getElementById('startrek-bg');
    if (!container) return;

    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var starCount = 70;
    for (var i = 0; i < starCount; i++) {
        var star = document.createElement('span');
        star.className = 'startrek-star';
        var size = Math.random() < 0.85 ? (1 + Math.random() * 1.5) : (2 + Math.random() * 1.5);
        star.style.width = size + 'px';
        star.style.height = size + 'px';
        star.style.top = Math.random() * 100 + '%';
        star.style.left = Math.random() * 100 + '%';
        if (!reducedMotion) {
            star.style.animationDuration = (2 + Math.random() * 3) + 's';
            star.style.animationDelay = (Math.random() * 4) + 's';
        } else {
            star.style.opacity = String(0.4 + Math.random() * 0.5);
        }
        container.appendChild(star);
    }

    if (reducedMotion) return;

    function spawnWarpStreak() {
        var streak = document.createElement('span');
        streak.className = 'startrek-warp';
        streak.style.top = (10 + Math.random() * 70) + '%';
        streak.style.animationDuration = (0.9 + Math.random() * 0.6) + 's';
        container.appendChild(streak);
        setTimeout(function () {
            if (streak.parentNode) streak.parentNode.removeChild(streak);
        }, 1800);
    }
    setInterval(spawnWarpStreak, 3200);
    setTimeout(spawnWarpStreak, 800);
})();
