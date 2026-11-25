<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/turnstile.php';

// functions.php è ora solo il punto d'ingresso: le funzioni stanno in file per argomento (fn_*.php),
// caricati tutti qui. Tutte le pagine continuano a includere solo questo file.
require_once __DIR__ . '/fn_constants.php';
require_once __DIR__ . '/fn_core.php';
require_once __DIR__ . '/fn_auth.php';
require_once __DIR__ . '/fn_notifications.php';
require_once __DIR__ . '/fn_themes.php';
require_once __DIR__ . '/fn_navigation.php';
require_once __DIR__ . '/fn_adminlte_layout.php';
require_once __DIR__ . '/fn_adminlte_pages.php';
require_once __DIR__ . '/fn_academic_theme.php';
require_once __DIR__ . '/fn_social.php';
require_once __DIR__ . '/fn_media.php';
require_once __DIR__ . '/fn_dates.php';
require_once __DIR__ . '/fn_authorship.php';
require_once __DIR__ . '/fn_feed.php';
require_once __DIR__ . '/fn_pinned.php';
require_once __DIR__ . '/fn_events_cinema.php';
require_once __DIR__ . '/fn_account.php';
require_once __DIR__ . '/fn_terms.php';
require_once __DIR__ . '/fn_blog.php';
require_once __DIR__ . '/fn_help.php';
require_once __DIR__ . '/fn_privacy.php';
require_once __DIR__ . '/fn_classic_gate.php';
require_once __DIR__ . '/fn_geo_profile.php';



// Ripristina in automatico la sessione dal cookie "ricordami" su OGNI pagina: molte pagine
// pubbliche (profilo, home, "Segui") leggono $_SESSION['user_id'] direttamente invece di passare
// da currentUser(), quindi senza questa chiamata risultavano "non loggato" anche con il cookie valido.
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['user_id']) && !empty($_COOKIE['remember_me'])) {
    attemptRememberLogin();
}

// Profilo privato: chiude la pagina pubblica a chi non è autorizzato (vedi fn_privacy.php).
// Dopo il ripristino della sessione, così chi è loggato viene riconosciuto.
enforcePrivateProfileGate();

// Dashboard classica: solo per il proprietario (vedi fn_classic_gate.php).
enforceClassicDashGate();

// Città e provincia obbligatorie per entrare nella dashboard (vedi fn_geo_profile.php).
enforceProfileLocationGate();
