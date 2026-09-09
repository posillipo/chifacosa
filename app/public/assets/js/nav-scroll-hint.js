/*!
 * Indicatore statico "c'è altro da scorrere" per il menu di navigazione pubblico: quando il menu
 * eccede la larghezza dello schermo (diventa scorrevole via CSS, vedi .colorful-nav in
 * style.css), mostra una freccina + leggera ombra sul bordo destro (stile Hetzner Cloud
 * Console), nascosta quando si arriva in fondo.
 *
 * Su touch e trackpad il menu scorre già in modo nativo (overflow-x: auto). Su desktop con un
 * mouse semplice (senza gesture orizzontali) non c'era invece alcun modo di raggiungere le voci
 * che non entrano nello schermo — qui sotto si aggiungono trascinamento col mouse e rotellina
 * verticale tradotta in scorrimento orizzontale, oltre al solo rilevamento/toggle della classe
 * "has-overflow".
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

    // Rotellina del mouse (solo verticale, su desktop) tradotta in scorrimento orizzontale.
    nav.addEventListener('wheel', function (e) {
        if (nav.scrollWidth <= nav.clientWidth + 4) return;
        if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
        e.preventDefault();
        nav.scrollLeft += e.deltaY;
    }, { passive: false });

    // Trascinamento col mouse (click e sposta) — niente effetto su touch, dove il dito scorre
    // già nativamente e questi eventi "pointer" per tipo touch non arrivano qui sotto.
    // Importante: la classe "is-dragging" si applica SOLO dopo un movimento reale superiore alla
    // soglia, non già al pointerdown — altrimenti un semplice click (down+up senza spostamento)
    // disattiverebbe se stesso, perché il browser ricalcola su quale elemento è avvenuto il
    // rilascio usando lo stile corrente al momento del mouseup/click. "is-dragging" viene invece
    // rimossa SUBITO al pointerup (prima del click che il browser genera comunque al rilascio),
    // quindi da sola non basta a bloccare la navigazione dopo un trascinamento vero: ci pensa
    // "dragMoved" qui sotto, azzerato solo dopo aver bloccato un click.
    var isPointerDown = false;
    var isDragging = false;
    var dragMoved = false;
    var dragStartX = 0;
    var dragStartScrollLeft = 0;

    nav.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'touch') return;
        isPointerDown = true;
        dragStartX = e.clientX;
        dragStartScrollLeft = nav.scrollLeft;
    });
    nav.addEventListener('pointermove', function (e) {
        if (!isPointerDown) return;
        var delta = e.clientX - dragStartX;
        if (!isDragging && Math.abs(delta) > 3) {
            isDragging = true;
            dragMoved = true;
            nav.classList.add('is-dragging');
        }
        if (isDragging) {
            nav.scrollLeft = dragStartScrollLeft - delta;
        }
    });
    function endDrag() {
        isPointerDown = false;
        if (!isDragging) return;
        isDragging = false;
        nav.classList.remove('is-dragging');
    }
    nav.addEventListener('pointerup', endDrag);
    nav.addEventListener('pointercancel', endDrag);
    nav.addEventListener('pointerleave', endDrag);
    // Ignora il click che il browser genera comunque al rilascio del mouse dopo un trascinamento
    // vero e proprio: altrimenti scorrere il menu finirebbe anche per attivare la voce sotto al
    // puntatore. Un click semplice (dragMoved rimasto false) non è mai toccato da questo handler.
    nav.addEventListener('click', function (e) {
        if (dragMoved) {
            e.preventDefault();
            dragMoved = false;
        }
    });
})();
