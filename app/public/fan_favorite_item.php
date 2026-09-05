<?php
session_start();
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/spotify.php';
require_once __DIR__ . '/../src/tmdb.php';
require_once __DIR__ . '/../src/googlebooks.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Pagina di dettaglio condivisa dai tre moduli "che amo" (Band/Attori/Film) — un solo file
// invece di tre pressoché identici, dato che aumenteranno: la configurazione per tipo (tabella,
// nomi colonna, link esterno, dettagli live dall'API) è tutta qui sotto; aggiungere un quarto
// modulo in futuro significa solo aggiungere una voce a questo array.
const FAN_FAVORITE_KINDS = [
    'band' => [
        'table' => 'fan_favorite_bands',
        'external_id_col' => 'spotify_artist_id',
        'name_col' => 'spotify_artist_name',
        'image_col' => 'artist_image',
        'label' => 'Band che amo',
        'nav_key' => 'bandcheamo',
        'list_url_segment' => 'band-che-amo',
        'external_label' => 'Vedi su Spotify',
        'external_url' => 'https://open.spotify.com/artist/',
    ],
    'actor' => [
        'table' => 'fan_favorite_actors',
        'external_id_col' => 'tmdb_person_id',
        'name_col' => 'actor_name',
        'image_col' => 'actor_image',
        'label' => 'Attori che amo',
        'nav_key' => 'attorichamo',
        'list_url_segment' => 'attori-che-amo',
        'external_label' => 'Vedi su TMDb',
        'external_url' => 'https://www.themoviedb.org/person/',
    ],
    'movie' => [
        'table' => 'fan_favorite_movies',
        'external_id_col' => 'tmdb_movie_id',
        'name_col' => 'movie_title',
        'image_col' => 'movie_image',
        'label' => 'Film che amo',
        'nav_key' => 'filmcheamo',
        'list_url_segment' => 'film-che-amo',
        'external_label' => 'Vedi su TMDb',
        'external_url' => 'https://www.themoviedb.org/movie/',
    ],
    'book' => [
        'table' => 'fan_favorite_books',
        'external_id_col' => 'google_books_id',
        'name_col' => 'book_title',
        'image_col' => 'book_image',
        'label' => 'Libri che amo',
        'nav_key' => 'libricheamo',
        'list_url_segment' => 'libri-che-amo',
        'external_label' => 'Vedi su Google Books',
        'external_url' => 'https://books.google.com/books?id=',
        'image_shape' => 'book', // copertina rettangolare invece del cerchio, più adatta a un libro
    ],
    'playlist' => [
        'table' => 'fan_favorite_playlists',
        'external_id_col' => 'spotify_playlist_id',
        'name_col' => 'playlist_name',
        'image_col' => 'playlist_image',
        'label' => 'Playlist che amo',
        'nav_key' => 'playlistcheamo',
        'list_url_segment' => 'playlist-che-amo',
        'external_label' => 'Ascolta su Spotify',
        'external_url' => 'https://open.spotify.com/playlist/',
        'image_shape' => 'square', // copertina quadrata, come su Spotify
    ],
    'album' => [
        'table' => 'fan_favorite_albums',
        'external_id_col' => 'spotify_album_id',
        'name_col' => 'album_name',
        'image_col' => 'album_image',
        'label' => 'Album che amo',
        'nav_key' => 'albumcheamo',
        'list_url_segment' => 'album-che-amo',
        'external_label' => 'Ascolta su Spotify',
        'external_url' => 'https://open.spotify.com/album/',
        'image_shape' => 'square', // copertina quadrata, come su Spotify
    ],
];

$slug = $_GET['slug'] ?? '';
$kind = $_GET['kind'] ?? '';
$itemId = (int) ($_GET['id'] ?? 0);

if (!isset(FAN_FAVORITE_KINDS[$kind])) {
    http_response_code(404);
    exit('Pagina non trovata.');
}
$cfg = FAN_FAVORITE_KINDS[$kind];

$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.theme_color, p.page_theme, p.spotify_artist_id, p.spotify_show_id, p.youtube_channel_id, p.privacy_tracking_settings, p.genere, p.custom_feed_guid, p.custom_feed_guid_since
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist) {
    http_response_code(404);
    exit('Pagina non trovata.');
}

$stmt = getDB()->prepare("SELECT * FROM {$cfg['table']} WHERE id = ? AND user_id = ?");
$stmt->execute([$itemId, $artist['id']]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);
    exit('Elemento non trovato.');
}

$name = $item[$cfg['name_col']];
$image = $item['image_path'] ?: ($item[$cfg['image_col']] ?? null);
$imageUrl = $image ? (str_starts_with($image, 'http') ? $image : siteUrl($image)) : null;
$note = trim($item['note'] ?? '');
$externalUrl = $cfg['external_url'] . $item[$cfg['external_id_col']];

// Altri elementi dello stesso modulo pubblicati lo stesso giorno di questo — così chi arriva
// da un link personale a un solo elemento (es. una foto condivisa) vede subito anche gli altri
// della stessa giornata, senza dover andare a sfogliare la Timeline.
$sameDayItems = getSameDayFavorites($cfg['table'], $artist['id'], $item['publish_at'], $item['created_at'], $itemId);

// Info aggiuntive recuperate in tempo reale dall'API (biografia, generi, ecc.) — non salvate nel
// nostro database: restano sempre aggiornate, e se l'API non risponde la pagina funziona
// comunque con solo nome/immagine/nota già salvati.
$apiDetails = null;
if ($kind === 'band') {
    $apiDetails = spotifyGetArtist($item[$cfg['external_id_col']]);
} elseif ($kind === 'actor') {
    $apiDetails = tmdbGetPersonDetails($item[$cfg['external_id_col']]);
} elseif ($kind === 'movie') {
    $apiDetails = tmdbGetMovieDetails($item[$cfg['external_id_col']]);
} elseif ($kind === 'book') {
    $apiDetails = googleBooksGetVolumeDetails($item[$cfg['external_id_col']]);
} elseif ($kind === 'playlist') {
    $apiDetails = spotifyGetPlaylist($item[$cfg['external_id_col']]);
} elseif ($kind === 'album') {
    $apiDetails = spotifyGetAlbum($item[$cfg['external_id_col']]);
}

$pageUrl = siteUrl('/' . $slug . '/' . $cfg['list_url_segment'] . '/' . $itemId);
$ogDescription = $note !== '' ? $note : ($apiDetails['biography'] ?? $apiDetails['overview'] ?? ($artist['display_name'] . ' ama ' . $name . ' — scoprilo su ' . siteName()));
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php emitCustomFeedLinkRedirect($artist['custom_feed_guid'], $artist['custom_feed_guid_since'], $item['created_at']); ?>
<title><?= e($name) ?> — <?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?> — <?= e(siteName()) ?></title>
<meta name="description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">

<!-- Open Graph / condivisione social -->
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($name) ?> — <?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?>">
<meta property="og:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<meta property="og:url" content="<?= e($pageUrl) ?>">
<meta property="og:site_name" content="<?= e(siteName()) ?>">
<?php if ($imageUrl): ?>
<meta property="og:image" content="<?= e($imageUrl) ?>">
<?php endif; ?>

<meta name="twitter:card" content="<?= $imageUrl ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title" content="<?= e($name) ?> — <?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?>">
<meta name="twitter:description" content="<?= e(textExcerpt($ogDescription, 200)) ?>">
<?php if ($imageUrl): ?><meta name="twitter:image" content="<?= e($imageUrl) ?>"><?php endif; ?>

<link rel="canonical" href="<?= e($pageUrl) ?>">
<link rel="stylesheet" href="<?= assetUrl('/assets/css/style.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
<style>:root { --accent: <?= e($artist['theme_color'] ?: '#6C5CE7') ?>; --accent-text: <?= e(getContrastTextColor($artist['theme_color'])) ?>; }</style>
<?= embedPrivacyScript($artist) ?>
<?= embedTrackingHead($artist) ?>
<?= embedGoogleAnalytics($artist) ?>
</head>
<body class="<?= e(getPageThemeClass($artist['page_theme'] ?? 'colorful')) ?>">
<?php if (str_starts_with($artist['page_theme'] ?? 'colorful', 'wave')): ?><?= renderWaveBackground($artist['theme_color'] ?? '#6C5CE7', $artist['page_theme']) ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'circuit'): ?><?= renderCircuitBackground($artist['theme_color'] ?? '#6C5CE7') ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'napoli'): ?><?= renderNapoliBackground() ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'cinemapop'): ?><?= renderCinemaPopBackground() ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'startrek'): ?><?= renderStarTrekBackground() ?><?php endif; ?>
<?php if (($artist['page_theme'] ?? 'colorful') === 'galactic'): ?><?= renderGalacticBackground() ?><?php endif; ?>
<?= embedTrackingBodyStart($artist) ?>
<div class="container">
  <?= publicProfileHeader($artist, $cfg['nav_key']) ?>

  <div class="card" style="text-align:center;">
    <?php if ($imageUrl): ?>
      <?php if (($cfg['image_shape'] ?? 'circle') === 'book'): ?>
        <img src="<?= e($imageUrl) ?>" alt="<?= e($name) ?>"
             style="width:140px;height:190px;border-radius:8px;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.18);margin-bottom:16px;">
      <?php elseif (($cfg['image_shape'] ?? 'circle') === 'square'): ?>
        <img src="<?= e($imageUrl) ?>" alt="<?= e($name) ?>"
             style="width:170px;height:170px;border-radius:14px;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.18);margin-bottom:16px;">
      <?php else: ?>
        <img src="<?= e($imageUrl) ?>" alt="<?= e($name) ?>"
             style="width:160px;height:160px;border-radius:50%;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.18);margin-bottom:16px;">
      <?php endif; ?>
    <?php endif; ?>
    <h1 style="font-size:22px;margin:0 0 4px;"><?= e($name) ?></h1>
    <p style="opacity:0.75;margin-top:0;">
      <?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?>
      <?php if (!empty($apiDetails['authors'])): ?> · <?= e($apiDetails['authors']) ?><?php endif; ?>
      <?php if ($kind === 'album' && !empty($item['album_artist_name'])): ?> · <?= e($item['album_artist_name']) ?><?php endif; ?>
      <?php if ($kind === 'playlist' && !empty($apiDetails['owner'])): ?> · di <?= e($apiDetails['owner']) ?> su Spotify<?php endif; ?>
      <?php if (!empty($apiDetails['tracks_total'])): ?> · <?= (int) $apiDetails['tracks_total'] ?> brani<?php endif; ?>
      <?php if (!empty($apiDetails['release_date'])): ?> · <?= e(substr($apiDetails['release_date'], 0, 4)) ?><?php endif; ?>
      <?php if (!empty($apiDetails['known_for_department'])): ?> · <?= e($apiDetails['known_for_department']) ?><?php endif; ?>
    </p>
    <?php if ($kind === 'album' && !empty($apiDetails['genres'])): ?>
      <p style="margin-top:2px;opacity:0.85;"><em><?= e(implode(', ', $apiDetails['genres'])) ?></em></p>
    <?php endif; ?>
    <?php if ($kind === 'playlist' && !empty($apiDetails['description'])): ?>
      <p style="text-align:left;margin-top:10px;opacity:0.9;"><?= nl2br(e(strip_tags($apiDetails['description']))) ?></p>
    <?php endif; ?>

    <?php if ($note !== ''): ?>
      <div class="card" style="text-align:left;margin-top:14px;">
        <strong>Perché <?= e($artist['display_name']) ?> lo ama</strong>
        <p style="margin:6px 0 0;"><?= nl2br(e($note)) ?></p>
      </div>
    <?php endif; ?>

    <?php if (!empty($apiDetails['genres'])): ?>
      <p style="margin-top:14px;opacity:0.85;"><em><?= e(implode(', ', $apiDetails['genres'])) ?></em></p>
    <?php endif; ?>
    <?php if (!empty($apiDetails['overview'])): ?>
      <p style="text-align:left;margin-top:10px;opacity:0.9;"><?= nl2br(e($apiDetails['overview'])) ?></p>
    <?php elseif (!empty($apiDetails['biography'])): ?>
      <p style="text-align:left;margin-top:10px;opacity:0.9;"><?= nl2br(e(textExcerpt($apiDetails['biography'], 500))) ?></p>
    <?php endif; ?>

    <p style="margin-top:16px;"><a href="<?= e($externalUrl) ?>" target="_blank" rel="noopener" class="btn small"><?= e($cfg['external_label']) ?></a></p>
  </div>

  <?php if ($sameDayItems): ?>
    <div class="section-title" style="text-align:center;color:rgba(var(--text-rgb),0.6);margin:22px 0 10px;">
      Altri di questa giornata (<?= count($sameDayItems) ?>)
    </div>
    <?php foreach ($sameDayItems as $s): ?>
      <?php
        // Stessa grafica del post principale sopra (foto, titolo, nota) — niente però le info
        // live dall'API esterna (generi, biografia...) per ciascun elemento della giornata: qui
        // richiederebbe una chiamata API in più per ogni elemento a ogni visita della pagina,
        // rischioso per i limiti di quota (vedi Spotify) per un dettaglio secondario.
        $sName = $s[$cfg['name_col']];
        $sImage = $s['image_path'] ?: ($s[$cfg['image_col']] ?? null);
        $sImageUrl = $sImage ? (str_starts_with($sImage, 'http') ? $sImage : siteUrl($sImage)) : null;
        $sNote = trim($s['note'] ?? '');
        $sExternalUrl = $cfg['external_url'] . $s[$cfg['external_id_col']];
      ?>
      <div class="card" style="text-align:center;">
        <a href="/<?= e($slug) ?>/<?= e($cfg['list_url_segment']) ?>/<?= (int) $s['id'] ?>" style="text-decoration:none;color:inherit;">
          <?php if ($sImageUrl): ?>
            <?php if (($cfg['image_shape'] ?? 'circle') === 'book'): ?>
              <img src="<?= e($sImageUrl) ?>" alt="<?= e($sName) ?>"
                   style="width:140px;height:190px;border-radius:8px;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.18);margin-bottom:16px;">
            <?php elseif (($cfg['image_shape'] ?? 'circle') === 'square'): ?>
              <img src="<?= e($sImageUrl) ?>" alt="<?= e($sName) ?>"
                   style="width:170px;height:170px;border-radius:14px;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.18);margin-bottom:16px;">
            <?php else: ?>
              <img src="<?= e($sImageUrl) ?>" alt="<?= e($sName) ?>"
                   style="width:160px;height:160px;border-radius:50%;object-fit:cover;box-shadow:0 8px 24px rgba(0,0,0,0.18);margin-bottom:16px;">
            <?php endif; ?>
          <?php endif; ?>
          <h2 style="font-size:20px;margin:0 0 4px;"><?= e($sName) ?></h2>
          <p style="opacity:0.75;margin-top:0;">
            <?= e($cfg['label']) ?> di <?= e($artist['display_name']) ?>
            <?php if ($kind === 'album' && !empty($s['album_artist_name'])): ?> · <?= e($s['album_artist_name']) ?><?php endif; ?>
          </p>
        </a>
        <?php if ($sNote !== ''): ?>
          <div class="card" style="text-align:left;margin-top:14px;">
            <strong>Perché <?= e($artist['display_name']) ?> lo ama</strong>
            <p style="margin:6px 0 0;"><?= nl2br(e($sNote)) ?></p>
          </div>
        <?php endif; ?>
        <p style="margin-top:16px;"><a href="<?= e($sExternalUrl) ?>" target="_blank" rel="noopener" class="btn small"><?= e($cfg['external_label']) ?></a></p>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <p><a href="/<?= e($slug) ?>/<?= e($cfg['list_url_segment']) ?>">← Tutti gli elementi di <?= e(strtolower($cfg['label'])) ?> di <?= e($artist['display_name']) ?></a></p>
</div>
<?= renderFloatingButtons() ?>
<?= renderSiteFooterBar($artist) ?>
</body>
</html>
