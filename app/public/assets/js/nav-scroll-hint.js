/*!
 * Richiamo visivo periodico per il menu di navigazione pubblico su mobile: quando il menu
 * eccede la larghezza dello schermo (diventa scorrevole via CSS, vedi .colorful-nav in
 * style.css), ogni tanto lo si sposta leggermente a destra e poi indietro, per far notare che
 * ci sono altri tab oltre a quelli visibili — stesso spirito del piccolo "richiamo" già usato
 * sul pulsante di invito alla registrazione nel footer (badge-attention-shake), ma qui è un
 * vero scorrimento (scrollLeft) invece di un semplice transform, perché il menu scorre davvero.
 */
(function () {
    var nav = document.querySelector('.colorful-nav');
    if (!nav) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var userTouching = false;
    nav.addEventListener('touchstart', function () { userTouching = true; }, { passive: true });
    nav.addEventListener('touchend', function () { userTouching = false; }, { passive: true });

    function nudge(withFlash) {
        if (userTouching || document.hidden) return;
        if (nav.scrollWidth <= nav.clientWidth + 4) return; // niente da scorrere
        if (nav.scrollLeft > 4) return; // l'utente ha già scoperto che scorre, non insistere

        if (withFlash) {
            nav.classList.add('nav-hint-flash');
            setTimeout(function () { nav.classList.remove('nav-hint-flash'); }, 900);
        }

        nav.scrollTo({ left: 46, behavior: 'smooth' });
        setTimeout(function () {
            if (!userTouching) nav.scrollTo({ left: 0, behavior: 'smooth' });
        }, 550);
    }

    // Il primo sussulto scatta quasi subito all'arrivo sulla pagina (non aspetta il primo giro
    // dell'intervallo) e si accompagna al bagliore, per farsi notare subito. Le ripetizioni
    // successive restano solo il movimento, senza bagliore, per non diventare fastidiose.
    setTimeout(function () { nudge(true); }, 700);
    setInterval(function () { nudge(false); }, 5000);
})();
