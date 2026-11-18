<?php
// Temi grafici: CSS, sfondi animati, colori.
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


// Genera lo snippet standard di Google Analytics (gtag.js) a partire dal solo Measurement ID
// (es. G-XXXXXXXXXX), così l'admin non deve incollare script complessi a mano.
/**
 * Ottiene il tema grafico corrente
 */
function getCurrentTheme(): array {
    try {
        $pdo = getDB();
        
        // Fetch current theme ID from settings
        $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'current_theme_id'");
        $stmt->execute();
        $theme_id = $stmt->fetchColumn() ?: 1;
        
        // Fetch theme data
        $stmt = $pdo->prepare("SELECT * FROM themes WHERE id = ?");
        $stmt->execute([$theme_id]);
        $theme = $stmt->fetch();
        
        return $theme ?: [
            'primary_color' => '#ff6b6b',
            'deep_color' => '#cc5555',
            'light_color' => '#ffe8e8',
            'accent_color' => '#ff8e8e',
            'text_primary' => '#1A1A1A',
            'text_secondary' => '#757575',
            'success_color' => '#4CAF50',
            'error_color' => '#F44336'
        ];
    } catch (Exception $e) {
        return [
            'primary_color' => '#ff6b6b',
            'deep_color' => '#cc5555',
            'light_color' => '#ffe8e8',
            'accent_color' => '#ff8e8e',
            'text_primary' => '#1A1A1A',
            'text_secondary' => '#757575',
            'success_color' => '#4CAF50',
            'error_color' => '#F44336'
        ];
    }
}


/**
 * Genera CSS dinamico basato sul tema corrente
 */
function embedThemeCSS(): string {
    $theme = getCurrentTheme();
    return '<style>
        :root {
            --primary-color: ' . $theme['primary_color'] . ';
            --deep-color: ' . $theme['deep_color'] . ';
            --light-color: ' . $theme['light_color'] . ';
            --accent-color: ' . $theme['accent_color'] . ';
            --text-primary: ' . $theme['text_primary'] . ';
            --text-secondary: ' . $theme['text_secondary'] . ';
            --success-color: ' . $theme['success_color'] . ';
            --error-color: ' . $theme['error_color'] . ';
        }
        
        /* Button Styles */
        .btn-primary, .button-primary { background: var(--primary-color); color: white; }
        .btn-primary:hover, .button-primary:hover { opacity: 0.9; }
        
        .btn-secondary, .button-secondary { color: var(--primary-color); border-color: var(--primary-color); }
        .btn-secondary:hover, .button-secondary:hover { background: var(--primary-color); color: white; }
        
        /* Links */
        a { color: var(--primary-color); }
        a:hover { color: var(--deep-color); }
        
        /* Forms */
        input:focus, textarea:focus, select:focus {
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 3px rgba(0, 119, 221, 0.1) !important;
        }
        
        /* Hero */
        .hero h1 span { color: var(--primary-color); }
        
        /* Alerts */
        .alert.success { background: rgba(76, 175, 80, 0.1); border-color: var(--success-color); color: var(--success-color); }
        .alert.error { background: rgba(244, 67, 54, 0.1); border-color: var(--error-color); color: var(--error-color); }
    </style>';
}


// Sfondo animato Three.js per i temi "Wave" — canvas fisso dietro al contenuto, caricato solo
// se il profilo ha scelto uno di questi temi. Fallisce in silenzio se il browser non supporta
// WebGL. I parametri di forma/dimensione/colore variano in base al tema scelto.
function renderWaveBackground(string $accentColor, string $themeKey = 'wave'): string {
    $p = WAVE_THEME_PARAMS[$themeKey] ?? WAVE_THEME_PARAMS['wave'];
    return '<canvas id="wave-bg-canvas"'
        . ' data-accent="' . e($accentColor) . '"'
        . ' data-base="' . e($p['base']) . '"'
        . ' data-shape="' . e($p['shape']) . '"'
        . ' data-grid-size="' . (int) $p['gridSize'] . '"'
        . ' data-cube-size="' . e($p['cubeSize']) . '"'
        . ' data-gap="' . e($p['gap']) . '"'
        . ' data-cube-height="' . e($p['cubeHeight']) . '"'
        . ' data-ambient-intensity="' . e($p['ambientIntensity']) . '"'
        . ' data-light-intensity="' . e($p['lightIntensity']) . '"'
        . '></canvas>
    <script src="https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js"></script>
    <script src="' . assetUrl('/assets/js/wave-bg.js') . '"></script>';
}


// Sfondo animato per il tema "Circuit" — griglia CSS/JS (nessuna dipendenza esterna) di
// tessere che ruotano lentamente, a formare un disegno stile circuito stampato. Container
// fisso dietro al contenuto, caricato solo se il profilo ha scelto questo tema.
function renderCircuitBackground(string $accentColor): string {
    return '<div id="circuit-bg" data-accent="' . e($accentColor) . '"></div>
    <script src="' . assetUrl('/assets/js/circuit-bg.js') . '"></script>';
}


// Sfondo animato per il tema "Forza Napoli" — silhouette del Vesuvio fissa in basso (puro CSS,
// vedi style.css) e coriandoli azzurro/bianco/oro generati da napoli-fx.js che salgono dal
// basso in continuo. Container fisso dietro al contenuto, caricato solo se il profilo ha scelto
// questo tema, stesso schema di renderCircuitBackground()/renderWaveBackground().
function renderNapoliBackground(): string {
    return '<div id="napoli-bg"><div class="napoli-vesuvio"></div></div>
    <script src="' . assetUrl('/assets/js/napoli-fx.js') . '"></script>';
}


// Sfondo animato per il tema "Cinema Pop" — le due bande da pellicola cinematografica (fori
// bianchi su nero) in alto e in fondo sono puro CSS, i popcorn dorati che salgono dal basso in
// continuo sono generati da cinemapop-fx.js, stesso schema di napoli-fx.js (elementi CSS
// animati riciclati uno alla volta, nessun canvas/WebGL).
function renderCinemaPopBackground(): string {
    return '<div id="cinemapop-bg"><div class="cinemapop-filmstrip top"></div><div class="cinemapop-filmstrip bottom"></div></div>
    <script src="' . assetUrl('/assets/js/cinemapop-fx.js') . '"></script>';
}


// Sfondo animato per il tema "Frontiera Stellare" (ispirato a Star Trek) — campo stellare che
// scintilla e strisce di "salto nel warp" generate da startrek-fx.js. Container fisso dietro al
// contenuto, caricato solo se il profilo ha scelto questo tema, stesso schema delle altre
// funzioni renderXBackground().
function renderStarTrekBackground(): string {
    return '<div id="startrek-bg"></div>
    <script src="' . assetUrl('/assets/js/startrek-fx.js') . '"></script>';
}


// Sfondo animato per il tema "Console Galattica" — campo stellare su canvas che simula un
// viaggio nell'iperspazio (galactic-fx.js), più un pulsante per attivare/disattivare i suoni
// sintetizzati (spenti di default, l'utente li accende esplicitamente). Container fisso dietro
// al contenuto, caricato solo se il profilo ha scelto questo tema, stesso schema delle altre
// funzioni renderXBackground().
function renderGalacticBackground(): string {
    return '<div id="galactic-bg"></div>
    <button type="button" id="galactic-sound-toggle" class="floating-btn" style="bottom:130px;" title="Attiva i suoni" aria-pressed="false"></button>
    <script src="' . assetUrl('/assets/js/galactic-fx.js') . '"></script>';
}


// Rende un colore CSS (esadecimale "#rrggbb" o "hsl(h,s%,l%)", le due forme usate nei temi)
// semi-trasparente in modo sicuro — non si può semplicemente accodare un suffisso alpha
// esadecimale a un hsl(), non è CSS valido: va convertito nella variante rgba()/hsla() giusta.
function cssColorWithAlpha(string $color, float $alpha): string {
    $color = trim($color);
    if (str_starts_with($color, '#')) {
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) === 6 && ctype_xdigit($hex)) {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $alpha . ')';
        }
    } elseif (preg_match('/^hsl\(([^)]+)\)$/i', $color, $m)) {
        return 'hsla(' . $m[1] . ',' . $alpha . ')';
    }
    return $color; // formato non riconosciuto: meglio lasciarlo invariato che generare CSS rotto
}


// Calcola se il testo sopra un colore di sfondo debba essere bianco o scuro, in base alla
// luminosità percepita — usato per il pulsante attivo del menu e "Segui", che altrimenti
// sarebbero sempre bianchi anche quando il colore scelto dal profilo è già chiaro (es. un
// turchese acceso), risultando illeggibili.
function getContrastTextColor(?string $hexColor): string {
    $hex = ltrim($hexColor ?: '#6C5CE7', '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return '#fff';
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    // Luminosità percepita (formula standard W3C, approssimata)
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $luminance > 0.6 ? '#22223b' : '#fff';
}
