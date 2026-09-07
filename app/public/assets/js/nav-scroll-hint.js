/*!
 * Indicatore statico "c'è altro da scorrere" per il menu di navigazione pubblico: quando il menu
 * eccede la larghezza dello schermo (diventa scorrevole via CSS, vedi .colorful-nav in
 * style.css), mostra una freccina + leggera ombra sul bordo destro (stile Hetzner Cloud
 * Console), nascosta quando si arriva in fondo. Solo rilevamento/toggle della classe
 * "has-overflow": nessuno scorrimento automatico del menu, l'utente scorre di sua iniziativa.
 */
(function () {
    var nav = document.querySelector('.colorful-nav');
    if (!nav) return;
    var wrap = nav.closest('.colorful-nav-wrap');
    if (!wrap) return;

    function updateArrow() {
        if (nav.scrollWidth <= nav.clientWidth + 4) {
            wrap.classList.remove('has-overflow');
            return;
        }
        var atEnd = nav.scrollLeft + nav.clientWidth >= nav.scrollWidth - 4;
        wrap.classList.toggle('has-overflow', !atEnd);
    }
    updateArrow();
    nav.addEventListener('scroll', updateArrow, { passive: true });
    window.addEventListener('resize', updateArrow);
    window.addEventListener('load', updateArrow);
})();
