/*!
 * Sfondo "Circuit" per il tema pubblico CHI FA COSA — griglia di tessere in 3D che ruotano
 * lentamente, a formare un disegno stile "tubi"/circuito stampato (tecnica Truchet tiles,
 * puro CSS + JS vanilla, nessuna libreria esterna). Adattato da un pen CodePen fornito
 * dall'utente (nessuna licenza vincolante dichiarata nel sorgente originale), reso non
 * interattivo (niente click/hover) per l'uso come sfondo passivo dietro al contenuto reale.
 */
(function () {
    var container = document.getElementById('circuit-bg');
    if (!container) return;

    var accent = container.dataset.accent || '#6C5CE7';
    container.style.setProperty('--circuit-line-color', accent);

    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var elementScale = 64;
    var intervals = [];

    function getRanInt(min, max) {
        return Math.floor(Math.random() * (max - min + 1)) + min;
    }

    function rotateTile(tile) {
        var current = parseInt(tile.getAttribute('data-rotation') || '0', 10);
        var direction = Math.random() < 0.5 ? 1 : -1;
        var next = current + 90 * direction;
        tile.style.transform = 'rotate(' + next + 'deg)';
        tile.setAttribute('data-rotation', next);
    }

    function clearIntervals() {
        intervals.forEach(function (id) { clearInterval(id); });
        intervals = [];
    }

    function buildGrid() {
        clearIntervals();
        var old = container.querySelector('.cirContainer');
        if (old) old.remove();

        var rect = container.getBoundingClientRect();
        var elementsPerRow = Math.floor(rect.width / (elementScale * 0.5)) + 1;
        var rows = Math.floor(rect.height / (elementScale * 0.8)) + 1;

        var cirContainer = document.createElement('div');
        cirContainer.className = 'cirContainer';
        cirContainer.style.setProperty('--size', elementScale + 'px');

        for (var i = 0; i < rows; i++) {
            var row = document.createElement('div');
            row.className = 'cirRow';
            for (var j = 0; j < elementsPerRow; j++) {
                var tile = document.createElement('div');
                tile.className = 'cir';
                if ((j % 2 === 0 && i % 2 === 0) || (j % 2 === 1 && i % 2 === 1)) {
                    tile.style.transform = 'rotate(90deg)';
                    tile.setAttribute('data-rotation', '90');
                }
                row.appendChild(tile);

                if (!reducedMotion) {
                    intervals.push(setInterval(function (t) {
                        return function () { rotateTile(t); };
                    }(tile), getRanInt(4000, 42000)));
                }
            }
            cirContainer.appendChild(row);
        }
        container.appendChild(cirContainer);
    }

    buildGrid();

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(buildGrid, 250);
    });
})();
