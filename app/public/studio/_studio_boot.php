<?php
// Strato "Studio": la copia della dashboard (cartella /studio/) usa le stesse funzioni, lo stesso login e gli stessi dati
// dell'originale. Questo file, incluso all'inizio di ogni pagina copiata:
//  - tiene la navigazione dentro /studio/ (riscrive i collegamenti /dashboard_*.php e i reindirizzamenti),
//  - aggiunge il foglio di stile nuovo (assets/studio.css) e la classe "studio-mode" al body,
//  - esclude le pagine dai motori di ricerca.
// Le pagine di questa cartella sono GENERATE da scripts/make-studio.py a partire da app/public/dashboard_*.php: non
// modificarle a mano, rigenerale. Le personalizzazioni vanno qui, in assets/studio.css e nei file in studio/_overrides/.
if (!defined('STUDIO_BOOT')) {
    define('STUDIO_BOOT', true);
    header('X-Robots-Tag: noindex, nofollow');

    // Collegamenti "/dashboard_x.php" e "/dashboard.php" (anche con slash scritto \/ nel JSON o con dominio) → "/studio/...".
    function studioRewrite(string $s): string {
        if (strpos($s, 'dashboard') === false) {
            return $s;
        }
        $s = preg_replace_callback('~(https?:(?:\\\\?/){2}[^/"\'\s\\\\<>]+)?(?<!studio)(\\\\?/)dashboard((?:_[a-z0-9_]+)?\.php)~', function ($m) {
            // Solo se il collegamento è alla radice del sito: "/dashboard…" preceduto da virgolette, parentesi, =, spazio o dal dominio.
            $slash = $m[2];
            $esc = $slash === '\\/' ? '\\/' : '/';
            return $m[1] . $esc . 'studio' . $esc . 'dashboard' . $m[3];
        }, $s);
        return $s;
    }

    function studioFilter(string $html): string {
        $ct = '';
        foreach (headers_list() as $h) {
            if (stripos($h, 'Content-Type:') === 0) { $ct = strtolower($h); }
        }
        $html = studioRewrite($html);
        if (($ct === '' || strpos($ct, 'text/html') !== false) && stripos($html, '</head>') !== false) {
            $css = '/studio/assets/studio.css';
            $v = @filemtime(__DIR__ . '/assets/studio.css');
            $html = preg_replace('~</head>~i', '<link rel="stylesheet" href="' . $css . ($v ? '?v=' . $v : '') . '"></head>', $html, 1);
            $html = preg_replace('~<body(\s+class=")?~i', '<body class="studio-mode ', $html, 1);
            $html = str_replace('<body class="studio-mode  ', '<body class="studio-mode ', $html);
        }
        return $html;
    }

    header_register_callback(function () {
        foreach (headers_list() as $h) {
            if (stripos($h, 'Location:') === 0) {
                $v = trim(substr($h, 9));
                $n = studioRewrite($v);
                if ($n !== $v) {
                    header('Location: ' . $n, true);
                }
            }
        }
    });
    ob_start('studioFilter');
}
