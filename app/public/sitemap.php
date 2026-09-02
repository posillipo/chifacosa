<?php
require_once __DIR__ . '/../src/functions.php';

header('Content-Type: application/xml; charset=UTF-8');

// Sitemap XML dinamica per Google Search Console — copre tutte le sezioni pubbliche e indicizzabili
// del sito: pagine statiche, ogni profilo attivo con le sue sotto-pagine (solo quelle
// effettivamente raggiungibili: Eventi/Spotify/Podcast/Video solo se applicabili, Menù solo se
// ha piatti attivi, ecc. — stessa logica usata da publicNav() per decidere cosa mostrare), e
// ogni singolo contenuto pubblicato (post blog, aggiornamenti timeline pubblici, eventi, brani).
// Raggiungibile su /sitemap.xml (regola dedicata in .htaccess).

function sitemapUrl(string $loc, ?string $lastmod = null, string $changefreq = 'weekly', string $priority = '0.5'): void {
    echo "<url>\n<loc>" . e($loc) . "</loc>\n";
    if ($lastmod) {
        echo '<lastmod>' . e(date('Y-m-d', strtotime($lastmod))) . "</lastmod>\n";
    }
    echo "<changefreq>{$changefreq}</changefreq>\n<priority>{$priority}</priority>\n</url>\n";
}

$db = getDB();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php
// Pagine statiche di primo livello
sitemapUrl(siteUrl('/'), null, 'daily', '1.0');
sitemapUrl(siteUrl('/register.php'), null, 'monthly', '0.3');
sitemapUrl(siteUrl('/credits.php'), null, 'yearly', '0.1');

// Profili con almeno un piatto attivo nel menù — unico modo per sapere se /slug/menu è
// davvero raggiungibile (stessa condizione di menuHasItems(), qui in blocco per tutti i profili
// invece di una query per profilo).
$menuOwners = [];
foreach ($db->query('SELECT DISTINCT user_id FROM menu_items WHERE is_active = 1')->fetchAll() as $r) {
    $menuOwners[(int) $r['user_id']] = true;
}

$stmt = $db->query("SELECT u.id, u.slug, u.account_type, p.spotify_artist_id, p.spotify_show_id, p.youtube_channel_id, p.updated_at
    FROM users u JOIN profiles p ON p.user_id = u.id
    WHERE u.is_active = 1");
foreach ($stmt->fetchAll() as $p) {
    $slug = $p['slug'];
    $isBandOrLabel = in_array($p['account_type'], ['band', 'label'], true);

    sitemapUrl(siteUrl('/' . $slug), $p['updated_at'], 'weekly', '0.8');
    sitemapUrl(siteUrl('/' . $slug . '/timeline'), null, 'daily', '0.7');
    sitemapUrl(siteUrl('/' . $slug . '/blog'), null, 'weekly', '0.6');
    sitemapUrl(siteUrl('/' . $slug . '/brani'), null, 'weekly', '0.5');
    sitemapUrl(siteUrl('/' . $slug . '/contatti'), null, 'yearly', '0.3');

    if ($isBandOrLabel) {
        sitemapUrl(siteUrl('/' . $slug . '/eventi'), null, 'weekly', '0.6');
    }
    if (!empty($menuOwners[(int) $p['id']])) {
        sitemapUrl(siteUrl('/' . $slug . '/menu'), null, 'weekly', '0.6');
    }
    if ($isBandOrLabel && !empty($p['spotify_artist_id'])) {
        sitemapUrl(siteUrl('/' . $slug . '/spotify'), null, 'weekly', '0.5');
    }
    if ($isBandOrLabel && !empty($p['spotify_show_id'])) {
        sitemapUrl(siteUrl('/' . $slug . '/podcast'), null, 'weekly', '0.5');
    }
    if ($isBandOrLabel && !empty($p['youtube_channel_id'])) {
        sitemapUrl(siteUrl('/' . $slug . '/video'), null, 'weekly', '0.5');
    }
    if ($p['account_type'] === 'fan') {
        sitemapUrl(siteUrl('/' . $slug . '/band-che-amo'), null, 'monthly', '0.4');
    }
}

// Singoli post del blog (permalink SEO con data)
$stmt = $db->query("SELECT b.slug, b.published_at, u.slug AS user_slug
    FROM blog_posts b JOIN users u ON u.id = b.user_id
    WHERE u.is_active = 1");
foreach ($stmt->fetchAll() as $b) {
    sitemapUrl(siteUrl(blogPostUrl($b['user_slug'], $b)), $b['published_at'], 'monthly', '0.6');
}

// Singoli eventi
$stmt = $db->query("SELECT e.id, e.created_at, u.slug AS user_slug
    FROM events e JOIN users u ON u.id = e.user_id
    WHERE u.is_active = 1");
foreach ($stmt->fetchAll() as $ev) {
    sitemapUrl(siteUrl('/' . $ev['user_slug'] . '/eventi/' . $ev['id']), $ev['created_at'], 'monthly', '0.5');
}

// Singoli aggiornamenti in timeline — solo quelli davvero pubblici e già pubblicati (stessa
// condizione usata da getTimelineFeedForUsers()/timeline_post.php per decidere la visibilità).
$stmt = $db->query("SELECT tp.id, tp.created_at, u.slug AS user_slug
    FROM timeline_posts tp JOIN users u ON u.id = tp.user_id
    WHERE u.is_active = 1 AND tp.visibility = 'public' AND (tp.publish_at IS NULL OR tp.publish_at <= NOW())");
foreach ($stmt->fetchAll() as $tp) {
    sitemapUrl(siteUrl('/' . $tp['user_slug'] . '/timeline/' . $tp['id']), $tp['created_at'], 'monthly', '0.5');
}

// Singoli brani (pagina dedicata, condivisibile)
$stmt = $db->query("SELECT f.id, f.created_at, u.slug AS user_slug
    FROM favorite_tracks f JOIN users u ON u.id = f.user_id
    WHERE u.is_active = 1");
foreach ($stmt->fetchAll() as $t) {
    sitemapUrl(siteUrl('/' . $t['user_slug'] . '/brani/' . $t['id']), $t['created_at'], 'monthly', '0.4');
}
?>
</urlset>
