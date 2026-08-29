<?php
require_once __DIR__ . '/../src/functions.php';

header('Content-Type: application/rss+xml; charset=UTF-8');

$slug = $_GET['slug'] ?? '';
$stmt = getDB()->prepare('SELECT u.id, u.slug, u.account_type, p.display_name, p.bio, p.custom_feed_guid, p.custom_feed_guid_since
                          FROM users u JOIN profiles p ON p.user_id = u.id
                          WHERE u.slug = ? AND u.is_active = 1');
$stmt->execute([$slug]);
$artist = $stmt->fetch();

if (!$artist) {
    http_response_code(404);
    exit;
}

// Link personalizzato per il <link> dell'item, impostato in Dashboard → Feed RSS: si applica
// solo ai post pubblicati da quando il campo è stato impostato/modificato in poi (vedi
// MIGRAZIONI.md #28) — il <guid> resta invece sempre il permalink interno stabile, così i
// feed reader continuano a riconoscere correttamente ogni post come lo stesso item nel tempo.
$customLink = $artist['custom_feed_guid'] ?: null;
$customLinkSince = $artist['custom_feed_guid_since'] ? strtotime($artist['custom_feed_guid_since']) : null;

// Stesso feed della Timeline, ma senza i Brani (link a Spotify, non a un contenuto editoriale
// con la propria immagine di anteprima — vedi analisi di fattibilità).
$feed = getTimelineFeedForUsers([$artist['id']], 30);
$feed = array_values(array_filter($feed, fn($item) => $item['tipo'] !== 'brano'));

$channelUrl = siteUrl('/' . $slug);
$feedUrl = siteUrl('/' . $slug . '/feed');
$channelTitle = htmlspecialchars($artist['display_name'] . ' — ' . siteName(), ENT_XML1, 'UTF-8');
$channelDesc = htmlspecialchars($artist['bio'] ? textExcerpt($artist['bio'], 200) : ('Ultimi aggiornamenti di ' . $artist['display_name']), ENT_XML1, 'UTF-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
<channel>
<title><?= $channelTitle ?></title>
<link><?= e($channelUrl) ?></link>
<atom:link href="<?= e($feedUrl) ?>" rel="self" type="application/rss+xml" />
<description><?= $channelDesc ?></description>
<language>it-it</language>
<lastBuildDate><?= date(DATE_RSS) ?></lastBuildDate>
<?php foreach ($feed as $item): ?>
<?php
    $itemUrl = siteUrl($item['url']);
    $useCustomLink = $customLink && $customLinkSince && strtotime($item['data']) >= $customLinkSince;
    $linkUrl = $useCustomLink ? $customLink : $itemUrl;
?>
<item>
<title><?= htmlspecialchars($item['titolo'], ENT_XML1, 'UTF-8') ?></title>
<link><?= e($linkUrl) ?></link>
<guid isPermaLink="true"><?= e($itemUrl) ?></guid>
<pubDate><?= date(DATE_RSS, strtotime($item['data'])) ?></pubDate>
<description><?= htmlspecialchars($item['titolo'], ENT_XML1, 'UTF-8') ?></description>
<?php if ($item['cover']): ?>
<?php
    $coverUrl = str_starts_with($item['cover'], 'http') ? $item['cover'] : siteUrl($item['cover']);
    $coverExt = strtolower(pathinfo(parse_url($coverUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    $coverMime = ['png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'][$coverExt] ?? 'image/jpeg';
?>
<enclosure url="<?= e($coverUrl) ?>" type="<?= e($coverMime) ?>" />
<media:content url="<?= e($coverUrl) ?>" type="<?= e($coverMime) ?>" medium="image" />
<media:thumbnail url="<?= e($coverUrl) ?>" />
<?php endif; ?>
</item>
<?php endforeach; ?>
</channel>
</rss>
