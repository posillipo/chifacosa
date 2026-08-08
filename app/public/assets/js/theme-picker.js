/*!
 * Selezionatore tema a schermo intero per dashboard_theme.php — animazione adattata da
 * "Sketch 023: Background Picker" di Codrops (Manoela Ilic, tympanus.net/Sketches/023-theme-picker,
 * codice sorgente: github.com/codrops/codrops-sketches, licenza MIT).
 * Stessa tecnica (celle che crescono con stagger casuale via GSAP, poi le linee della griglia
 * si disegnano), applicata ai temi reali della pagina pubblica invece che a sfumature generiche:
 * al click su una cella viene selezionato quel tema e il form viene inviato.
 */
(function () {
    var openBtn = document.getElementById('open-theme-picker');
    var picker = document.getElementById('theme-picker');
    if (!openBtn || !picker) return;

    // Se GSAP non si carica (CDN irraggiungibile, blocco rete, ecc.) il selezionatore resta
    // comunque utilizzabile: si apre/chiude a scatto, senza l'animazione di rivelazione.
    var hasGsap = typeof gsap !== 'undefined';

    var closeBtn = picker.querySelector('.theme-picker__close');
    var items = picker.querySelectorAll('.theme-picker__item');
    var tl;

    function init() {
        if (!hasGsap) return;
        var lines = {
            vertical: picker.querySelectorAll('.theme-picker__item:nth-child(-n+3) i'),
            horizontal: picker.querySelectorAll('.theme-picker__item:nth-child(3n+4) i')
        };
        var cells = picker.querySelectorAll('.theme-picker__item-inner');

        gsap.set(lines.horizontal, { scaleX: 0, transformOrigin: '0% 50%' });
        gsap.set(lines.vertical, { scaleY: 0, transformOrigin: '50% 0%' });
        gsap.set(cells, { scale: 0 });

        tl = gsap.timeline({
            paused: true,
            onReverseComplete: function () { picker.classList.add('hidden'); },
            defaults: { duration: 1.5, ease: 'power2.inOut' }
        })
        .addLabel('start', 'start')
        .to(cells, {
            duration: 1.6,
            ease: 'power4',
            scale: 1,
            stagger: { each: 0.06, grid: 'auto', from: 'random' }
        }, 'start')
        .addLabel('lines', 'start+=0.15')
        .to(lines.horizontal, {
            scaleX: 1,
            stagger: { each: 0.02, grid: 'auto', from: 'random' }
        }, 'lines')
        .to(lines.vertical, {
            scaleY: 1,
            stagger: { each: 0.02, grid: 'auto', from: 'random' }
        }, 'lines');
    }

    function openPicker() {
        picker.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        if (hasGsap) tl.timeScale(1).play();
    }

    function closePicker() {
        document.body.style.overflow = '';
        if (hasGsap) {
            tl.timeScale(2.2).reverse();
        } else {
            picker.classList.add('hidden');
        }
    }

    function selectTheme(item) {
        var key = item.getAttribute('data-theme-key');
        var radio = document.getElementById('theme-radio-' + key);
        if (!radio) return;
        radio.checked = true;
        radio.dispatchEvent(new Event('change'));
    }

    init();

    openBtn.addEventListener('click', openPicker);
    if (closeBtn) closeBtn.addEventListener('click', closePicker);

    items.forEach(function (item) {
        item.addEventListener('click', function () { selectTheme(item); });
    });

    document.addEventListener('keydown', function (evt) {
        var isEscape = evt.key === 'Escape' || evt.key === 'Esc' || evt.keyCode === 27;
        if (isEscape && !picker.classList.contains('hidden')) {
            closePicker();
        }
    });
})();
