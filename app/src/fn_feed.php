<?php
// Feed e Timeline: costruzione del feed, filtri della dashboard, righe e schede.
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


// Altri elementi dello stesso modulo "che amo" pubblicati/aggiunti nello stesso giorno di un
// elemento — usata nelle pagine di dettaglio condivisibili (fan_favorite_item.php,
// favorite_track_item.php, viaggio_item.php): chi arriva da un link personale a UNA foto/
// elemento specifico vede subito sotto anche tutti gli altri dello stesso giorno, senza dover
// andare a sfogliare la Timeline. Il "giorno" è quello effettivo di comparsa nel feed
// (publish_at se impostato, altrimenti created_at) — stesso criterio di visibilità già usato
// da getTimelineFeedForUsers() (pubblicato E non nascosto), per non far trapelare tramite un
// link altrui elementi che il proprietario ha scelto di non mostrare nel feed.
function getSameDayFavorites(string $table, int $userId, ?string $referencePublishAt, string $referenceCreatedAt, int $excludeId): array {
    $refDate = substr($referencePublishAt ?: $referenceCreatedAt, 0, 10);
    $stmt = getDB()->prepare("SELECT * FROM {$table}
        WHERE user_id = ? AND id != ? AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())
          AND DATE(COALESCE(publish_at, created_at)) = ?
        ORDER BY COALESCE(publish_at, created_at) ASC, id ASC");
    $stmt->execute([$userId, $excludeId, $refDate]);
    return $stmt->fetchAll();
}


// Come getSameDayFavorites(), ma per i post "Timeline"/Aggiornamenti (timeline_posts) — usata in
// timeline_post.php: chi apre un post specifico vede subito sotto, come una sequenza di post
// uguali, anche tutti gli altri pubblicati la stessa giornata. Schema diverso da quello dei
// moduli "che amo" (visibility ENUM invece di is_public), da qui una funzione a parte invece
// di riusare quella.
function getSameDayTimelinePosts(int $userId, ?string $referencePublishAt, string $referenceCreatedAt, int $excludeId): array {
    $refDate = substr($referencePublishAt ?: $referenceCreatedAt, 0, 10);
    $stmt = getDB()->prepare('SELECT * FROM timeline_posts
        WHERE user_id = ? AND id != ? AND visibility = "public" AND (publish_at IS NULL OR publish_at <= NOW())
          AND DATE(COALESCE(publish_at, created_at)) = ?
        ORDER BY COALESCE(publish_at, created_at) ASC, id ASC');
    $stmt->execute([$userId, $excludeId, $refDate]);
    return $stmt->fetchAll();
}


// Feed aggregato "Timeline": unisce blog, brani, eventi e aggiornamenti brevi pubblicati dai
// profili indicati, ordinati dal più recente. Query separate per tipo di contenuto invece di
// una UNION, più semplice da leggere e mantenere con colonne diverse per ciascuna.
function getTimelineFeedForUsers(array $userIds, int $limit = 50, int $offset = 0, int $ownerId = 0): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $db = getDB();
    $items = [];
    // Ogni sotto-query qui sotto è limitata di suo (prima di unire e ordinare tutto in PHP più in
    // basso): un profilo/insieme di profili con più di 200 elementi PUBBLICATI di un solo tipo
    // (es. oltre 200 "brani che amo") perderebbe silenziosamente quelli più vecchi di quel tipo,
    // mai recuperati dal DB e quindi mai raggiungibili nemmeno scorrendo altre pagine. Il limite
    // per tipo deve quindi coprire almeno $offset+$limit (fin dove questa chiamata può arrivare),
    // non un valore fisso — 200 resta comunque il minimo, per non appesantire query più frequenti
    // e già coperte dal caso comune (poche decine di elementi per tipo).
    $perTypeLimit = max(200, $offset + $limit);

    // Modalità dashboard ($ownerId > 0): gli elementi del proprietario compaiono SEMPRE nel suo Feed,
    // anche se programmati o "Solo io", con i loro attributi (la data mostrata è quella di
    // pubblicazione). Gli elementi degli altri profili seguiti restano soggetti alla regola pubblica.
    $vis = function (string $a, string $kind) use ($ownerId): string {
        $c = match ($kind) {
            'std'   => "$a.is_public = 1 AND ($a.publish_at IS NULL OR $a.publish_at <= NOW())",
            'tl'    => "$a.visibility = 'public' AND ($a.publish_at IS NULL OR $a.publish_at <= NOW())",
            'ev'    => "($a.publish_at IS NULL OR $a.publish_at <= NOW())",
            'blog'  => "$a.published_at <= NOW()",
            'offer' => "$a.is_active = 1 AND ($a.valid_from IS NULL OR $a.valid_from <= NOW()) AND ($a.valid_until IS NULL OR $a.valid_until >= NOW())",
        };
        return $ownerId > 0 ? "($c OR $a.user_id = " . (int) $ownerId . ')' : $c;
    };

    $stmt = $db->prepare("SELECT b.id, b.title, b.cover_path, b.slug, b.published_at, b.in_feed, b.published_at AS data, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM blog_posts b JOIN users u ON u.id = b.user_id JOIN profiles p ON p.user_id = u.id
        WHERE b.user_id IN ($placeholders) AND {$vis('b', 'blog')} ORDER BY b.published_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'blog', 'titolo' => $r['title'], 'cover' => $r['cover_path'], 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => blogPostUrl($r['user_slug'], $r),
        ];
    }

    $stmt = $db->prepare("SELECT tr.id, tr.track_name, tr.track_image, tr.artist_name, tr.note, tr.image_path, tr.image_thumb_path, tr.in_feed, COALESCE(tr.publish_at, tr.created_at) AS data, tr.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM favorite_tracks tr JOIN users u ON u.id = tr.user_id JOIN profiles p ON p.user_id = u.id
        WHERE tr.user_id IN ($placeholders) AND {$vis('tr', 'std')} ORDER BY tr.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $brTitolo = $r['track_name'] . ' — ' . $r['artist_name'];
        if (trim($r['note'] ?? '') !== '') {
            $brTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'brano', 'titolo' => $brTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['track_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/brani/' . $r['id'] . '/scheda',
        ];
    }

    $stmt = $db->prepare("SELECT e.id, e.title, e.venue, e.city, e.cover_path, COALESCE(e.publish_at, e.created_at) AS data, e.event_date, e.is_perpetual, e.recurrence, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM events e JOIN users u ON u.id = e.user_id JOIN profiles p ON p.user_id = u.id
        WHERE e.user_id IN ($placeholders) AND {$vis('e', 'ev')} ORDER BY e.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'tipo' => 'evento', 'titolo' => $r['title'], 'cover' => $r['cover_path'], 'data' => $r['data'],
            'evento_quando' => $r['event_date'], 'evento_is_perpetual' => $r['is_perpetual'], 'evento_recurrence' => $r['recurrence'],
            'evento_venue' => $r['venue'], 'evento_city' => $r['city'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/eventi/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT tp.id, tp.title, tp.testo, tp.image_path, tp.image_thumb_path, tp.in_feed, tp.redirect_link, COALESCE(tp.publish_at, tp.created_at) AS data, (tp.visibility = 'public') AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM timeline_posts tp JOIN users u ON u.id = tp.user_id JOIN profiles p ON p.user_id = u.id
        WHERE tp.user_id IN ($placeholders) AND {$vis('tp', 'tl')}
        ORDER BY tp.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    $pensieroRows = $stmt->fetchAll();
    // Quanti post hanno più di una foto (carosello) — serve solo a feed.php, per sapere quali
    // devono esporre nel feed RSS/Metricool la versione con "Link Album in Descrizione" invece
    // della foto nuda (vedi getFeedShareImage()). Una sola query qui invece che una per post; il
    // calcolo VERO e proprio della versione con scritta resta rimandato a feed.php, e solo per i
    // pochi elementi davvero mostrati (raw_image_path/has_multi_photo sono solo dati, non
    // generano nulla su disco) — non ha senso rigenerarla ad ogni caricamento di Feed/Timeline
    // per elementi che nessuno vedrà.
    $pensieroPhotoCounts = [];
    $pensieroIds = array_column($pensieroRows, 'id');
    if ($pensieroIds) {
        $ph = implode(',', array_fill(0, count($pensieroIds), '?'));
        $cs = $db->prepare("SELECT post_id, COUNT(*) c FROM timeline_post_photos WHERE post_id IN ($ph) GROUP BY post_id");
        $cs->execute($pensieroIds);
        foreach ($cs->fetchAll() as $c) {
            $pensieroPhotoCounts[(int) $c['post_id']] = (int) $c['c'];
        }
    }
    foreach ($pensieroRows as $r) {
        $items[] = [
            'id' => (int) $r['id'], 'item_id' => (int) $r['id'], 'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'pensiero', 'titolo' => $r['testo'] ? textExcerpt($r['testo'], 100) : (!empty($r['title']) ? $r['title'] : ($r['image_path'] ? '📷 Foto' : '')), 'cover' => $r['image_path'],
            'cover_thumb' => $r['image_thumb_path'] ?: $r['image_path'], 'data' => $r['data'],
            'raw_image_path' => $r['image_path'], 'has_multi_photo' => !empty($pensieroPhotoCounts[(int) $r['id']]),
            'redirect_link' => $r['redirect_link'] ?: null,
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/timeline/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fb.id, fb.spotify_artist_name, fb.artist_image, fb.note, fb.image_path, fb.image_thumb_path, fb.in_feed, COALESCE(fb.publish_at, fb.created_at) AS data, fb.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_bands fb JOIN users u ON u.id = fb.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fb.user_id IN ($placeholders) AND {$vis('fb', 'std')} ORDER BY fb.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $fbTitolo = $r['spotify_artist_name'];
        if (trim($r['note'] ?? '') !== '') {
            $fbTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'band_favorita', 'titolo' => $fbTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['artist_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/band-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fa.id, fa.actor_name, fa.actor_image, fa.note, fa.image_path, fa.image_thumb_path, fa.in_feed, COALESCE(fa.publish_at, fa.created_at) AS data, fa.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_actors fa JOIN users u ON u.id = fa.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fa.user_id IN ($placeholders) AND {$vis('fa', 'std')} ORDER BY fa.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $faTitolo = $r['actor_name'];
        if (trim($r['note'] ?? '') !== '') {
            $faTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'attore_favorito', 'titolo' => $faTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['actor_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/attori-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fm.id, fm.movie_title, fm.movie_image, fm.note, fm.image_path, fm.image_thumb_path, fm.in_feed, COALESCE(fm.publish_at, fm.created_at) AS data, fm.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_movies fm JOIN users u ON u.id = fm.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fm.user_id IN ($placeholders) AND {$vis('fm', 'std')} ORDER BY fm.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $fmTitolo = $r['movie_title'];
        if (trim($r['note'] ?? '') !== '') {
            $fmTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'film_favorito', 'titolo' => $fmTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['movie_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/film-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fk.id, fk.book_title, fk.book_image, fk.note, fk.image_path, fk.image_thumb_path, fk.in_feed, COALESCE(fk.publish_at, fk.created_at) AS data, fk.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_books fk JOIN users u ON u.id = fk.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fk.user_id IN ($placeholders) AND {$vis('fk', 'std')} ORDER BY fk.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $fkTitolo = $r['book_title'];
        if (trim($r['note'] ?? '') !== '') {
            $fkTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'libro_favorito', 'titolo' => $fkTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['book_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/libri-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT ft.id, ft.place_name, ft.map_image_path, ft.note, ft.image_path, ft.image_thumb_path, ft.in_feed, COALESCE(ft.publish_at, ft.created_at) AS data, ft.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_trips ft JOIN users u ON u.id = ft.user_id JOIN profiles p ON p.user_id = u.id
        WHERE ft.user_id IN ($placeholders) AND {$vis('ft', 'std')} ORDER BY ft.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    $tripRows = $stmt->fetchAll();
    // Vedi commento sopra (blocco "pensiero"): stesso calcolo, per i viaggi con più foto.
    $tripPhotoCounts = [];
    $tripIds = array_column($tripRows, 'id');
    if ($tripIds) {
        $ph = implode(',', array_fill(0, count($tripIds), '?'));
        $cs = $db->prepare("SELECT trip_id, COUNT(*) c FROM fan_favorite_trip_photos WHERE trip_id IN ($ph) GROUP BY trip_id");
        $cs->execute($tripIds);
        foreach ($cs->fetchAll() as $c) {
            $tripPhotoCounts[(int) $c['trip_id']] = (int) $c['c'];
        }
    }
    foreach ($tripRows as $r) {
        $ftTitolo = $r['place_name'];
        if (trim($r['note'] ?? '') !== '') {
            $ftTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'viaggio_favorito', 'titolo' => $ftTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['map_image_path']), 'data' => $r['data'],
            'raw_image_path' => $r['image_path'], 'has_multi_photo' => !empty($tripPhotoCounts[(int) $r['id']]),
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/viaggi/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fp.id, fp.playlist_name, fp.playlist_image, fp.note, fp.image_path, fp.image_thumb_path, fp.in_feed, COALESCE(fp.publish_at, fp.created_at) AS data, fp.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_playlists fp JOIN users u ON u.id = fp.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fp.user_id IN ($placeholders) AND {$vis('fp', 'std')} ORDER BY fp.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $fpTitolo = $r['playlist_name'];
        if (trim($r['note'] ?? '') !== '') {
            $fpTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'playlist_favorita', 'titolo' => $fpTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['playlist_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/playlist-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fal.id, fal.album_name, fal.album_artist_name, fal.album_image, fal.note, fal.image_path, fal.image_thumb_path, fal.in_feed, COALESCE(fal.publish_at, fal.created_at) AS data, fal.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_albums fal JOIN users u ON u.id = fal.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fal.user_id IN ($placeholders) AND {$vis('fal', 'std')} ORDER BY fal.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $falTitolo = $r['album_name'] . ($r['album_artist_name'] ? ' — ' . $r['album_artist_name'] : '');
        if (trim($r['note'] ?? '') !== '') {
            $falTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'album_favorito', 'titolo' => $falTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['album_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/album-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fr.id, fr.recipe_title, fr.recipe_image, fr.note, fr.image_path, fr.image_thumb_path, fr.in_feed, COALESCE(fr.publish_at, fr.created_at) AS data, fr.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_recipes fr JOIN users u ON u.id = fr.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fr.user_id IN ($placeholders) AND {$vis('fr', 'std')} ORDER BY fr.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $frTitolo = $r['recipe_title'];
        if (trim($r['note'] ?? '') !== '') {
            $frTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'ricetta_favorita', 'titolo' => $frTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['recipe_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/ricette-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT ft.id, ft.team_name, ft.team_badge, ft.note, ft.image_path, ft.image_thumb_path, ft.in_feed, COALESCE(ft.publish_at, ft.created_at) AS data, ft.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_teams ft JOIN users u ON u.id = ft.user_id JOIN profiles p ON p.user_id = u.id
        WHERE ft.user_id IN ($placeholders) AND {$vis('ft', 'std')} ORDER BY ft.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $ftTitolo = $r['team_name'];
        if (trim($r['note'] ?? '') !== '') {
            $ftTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'squadra_favorita', 'titolo' => $ftTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['team_badge']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/squadre-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fp.id, fp.player_name, fp.player_photo, fp.note, fp.image_path, fp.image_thumb_path, fp.in_feed, COALESCE(fp.publish_at, fp.created_at) AS data, fp.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_players fp JOIN users u ON u.id = fp.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fp.user_id IN ($placeholders) AND {$vis('fp', 'std')} ORDER BY fp.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $fpTitolo2 = $r['player_name'];
        if (trim($r['note'] ?? '') !== '') {
            $fpTitolo2 .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'calciatore_favorito', 'titolo' => $fpTitolo2, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['player_photo']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/calciatori-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fm.id, fm.match_title, fm.match_image, fm.note, fm.image_path, fm.image_thumb_path, fm.in_feed, COALESCE(fm.publish_at, fm.created_at) AS data, fm.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_matches fm JOIN users u ON u.id = fm.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fm.user_id IN ($placeholders) AND {$vis('fm', 'std')} ORDER BY fm.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $fmTitolo = $r['match_title'];
        if (trim($r['note'] ?? '') !== '') {
            $fmTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'partita_favorita', 'titolo' => $fmTitolo, 'cover' => $r['image_thumb_path'] ?: ($r['image_path'] ?: $r['match_image']), 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/partite-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT fp.id, fp.publication_title, fp.note, fp.image_path, fp.image_thumb_path, fp.in_feed, COALESCE(fp.publish_at, fp.created_at) AS data, fp.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM fan_favorite_publications fp JOIN users u ON u.id = fp.user_id JOIN profiles p ON p.user_id = u.id
        WHERE fp.user_id IN ($placeholders) AND {$vis('fp', 'std')} ORDER BY fp.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $fpTitolo = $r['publication_title'];
        if (trim($r['note'] ?? '') !== '') {
            $fpTitolo .= ': ' . textExcerpt($r['note'], 100);
        }
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'pubblicazione_favorita', 'titolo' => $fpTitolo, 'cover' => $r['image_thumb_path'] ?: $r['image_path'], 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/pubblicazioni-che-amo/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT so.id, so.title, so.price_label, so.cover_path, so.in_feed, so.created_at AS data, so.is_active AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM special_offers so JOIN users u ON u.id = so.user_id JOIN profiles p ON p.user_id = u.id
        WHERE so.user_id IN ($placeholders) AND {$vis('so', 'offer')}
        ORDER BY so.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $soTitolo = $r['title'] . ($r['price_label'] ? ' — ' . $r['price_label'] : '');
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'offerta', 'titolo' => $soTitolo, 'cover' => $r['cover_path'], 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/offerte/' . $r['id'],
        ];
    }

    $stmt = $db->prepare("SELECT pa.id, pa.title, pa.cover_path, pa.in_feed, COALESCE(pa.publish_at, pa.created_at) AS data, pa.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM photo_albums pa JOIN users u ON u.id = pa.user_id JOIN profiles p ON p.user_id = u.id
        WHERE pa.user_id IN ($placeholders) AND {$vis('pa', 'std')} ORDER BY pa.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    $albumRows = $stmt->fetchAll();
    // Vedi commento sopra (blocco "pensiero"): stesso calcolo, per gli album con più foto oltre
    // alla copertina — a differenza di Offerte/Servizi, un album è tutto foto: vale la pena
    // incuriosire chi lo vede sui social con "Link Album in Descrizione", proprio come per
    // Timeline/Viaggi.
    $albumPhotoCounts = [];
    $albumIds = array_column($albumRows, 'id');
    if ($albumIds) {
        $ph = implode(',', array_fill(0, count($albumIds), '?'));
        $cs = $db->prepare("SELECT album_id, COUNT(*) c FROM photo_album_photos WHERE album_id IN ($ph) GROUP BY album_id");
        $cs->execute($albumIds);
        foreach ($cs->fetchAll() as $c) {
            $albumPhotoCounts[(int) $c['album_id']] = (int) $c['c'];
        }
    }
    foreach ($albumRows as $r) {
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'album_foto', 'titolo' => $r['title'], 'cover' => $r['cover_path'], 'data' => $r['data'],
            'raw_image_path' => $r['cover_path'], 'has_multi_photo' => !empty($albumPhotoCounts[(int) $r['id']]),
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/album/' . $r['id'],
        ];
    }

    // Video caricati (modulo "Video"): come gli album, compaiono nella Timeline/Feed se pubblici e già
    // pubblicati. La tabella può non esistere ancora (migrazione non eseguita): in quel caso si salta.
    try {
        $stmt = $db->prepare("SELECT v.id, v.title, v.description, v.thumb_path, v.in_feed, COALESCE(v.publish_at, v.created_at) AS data, v.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
            FROM videos v JOIN users u ON u.id = v.user_id JOIN profiles p ON p.user_id = u.id
            WHERE v.user_id IN ($placeholders) AND {$vis('v', 'std')} ORDER BY v.created_at DESC LIMIT {$perTypeLimit}");
        $stmt->execute($userIds);
        foreach ($stmt->fetchAll() as $r) {
            $items[] = [
                'item_id' => (int) $r['id'],
                'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
                'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'video', 'titolo' => $r['title'], 'cover' => $r['thumb_path'], 'data' => $r['data'],
                'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
                'url' => '/' . $r['user_slug'] . '/video/' . $r['id'],
            ];
        }
    } catch (Throwable $e) {
        error_log('getTimelineFeedForUsers(video): ' . $e->getMessage());
    }

    $stmt = $db->prepare("SELECT sv.id, sv.title, sv.cover_path, sv.in_feed, COALESCE(sv.publish_at, sv.created_at) AS data, sv.is_public AS x_pub, u.slug AS user_slug, p.display_name, p.avatar_path, p.dashboard_theme
        FROM services sv JOIN users u ON u.id = sv.user_id JOIN profiles p ON p.user_id = u.id
        WHERE sv.user_id IN ($placeholders) AND {$vis('sv', 'std')} ORDER BY sv.created_at DESC LIMIT {$perTypeLimit}");
    $stmt->execute($userIds);
    foreach ($stmt->fetchAll() as $r) {
        $items[] = [
            'item_id' => (int) $r['id'],
            'x_pub' => isset($r['x_pub']) ? (int) $r['x_pub'] : 1,
            'in_feed' => (int) ($r['in_feed'] ?? 1), 'tipo' => 'servizio', 'titolo' => $r['title'], 'cover' => $r['cover_path'], 'data' => $r['data'],
            'user_slug' => $r['user_slug'], 'display_name' => $r['display_name'], 'avatar' => $r['avatar_path'], 'owner_tz' => $r['dashboard_theme'],
            'url' => '/' . $r['user_slug'] . '/servizi/' . $r['id'],
        ];
    }

    usort($items, fn($a, $b) => strtotime($b['data']) <=> strtotime($a['data']));
    $slice = array_slice($items, $offset, $limit);
    foreach ($slice as &$it) {
        $tpl = SCHEDULABLE_DASHBOARD_URLS[$it['tipo']] ?? null;
        $it['edit_url'] = $tpl ? sprintf($tpl, $it['item_id'] ?? 0) : $it['url'];
    }
    unset($it);
    return $slice;
}


// "Calendario pubblicazioni" (dashboard_schedule.php): tutti gli elementi con una data di
// programmazione futura, di QUALUNQUE tipo (Timeline, Blog, tutti i moduli Che Amo, Offerte,
// Album, Servizi), in un unico elenco — stesso principio/stessa fonte di PINNABLE_CONTENT_TYPES
// già usata per "Primo Piano" e per il Feed aggregato, ma filtrato al contrario (solo il futuro,
// non il già pubblicato). Gli eventi restano esclusi: non hanno un concetto di programmazione
// proprio (vedi pinnableScheduleColumn()).
function getScheduledContentForUser(int $userId, ?string $slug = null): array {
    $items = [];
    foreach (PINNABLE_CONTENT_TYPES as $type => $cfg) {
        $col = pinnableScheduleColumn($cfg['visibility']);
        if ($col === null) {
            continue;
        }
        $stmt = getDB()->prepare("SELECT * FROM {$cfg['table']} WHERE user_id = ? AND {$col} > NOW()");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $title = trim((string) ($row[$cfg['title_col']] ?? ''));
            if ($title === '') {
                $title = !empty($row['title']) ? $row['title'] : (!empty($row['image_path']) ? '📷 Foto' : '(senza titolo)');
            }
            $cover = null;
            foreach ($cfg['cover_cols'] as $coverCol) {
                if (!empty($row[$coverCol])) {
                    $cover = $row[$coverCol];
                    break;
                }
            }
            // Link di "anteprima riservata": la stessa pagina pubblica, con un token che ne
            // bypassa il blocco 404 finché resta programmata — vedi previewToken() e il gate in
            // ciascuna pagina di dettaglio (timeline_post.php, fan_favorite_item.php, ecc.). Il
            // Blog non ha un url_tpl fisso (l'URL include la data), quindi usa blogPostUrl().
            $previewUrl = null;
            if ($slug !== null) {
                $publicPath = ($type === 'blog') ? blogPostUrl($slug, $row) : sprintf($cfg['url_tpl'], $slug, (int) $row['id']);
                $previewUrl = withPreviewToken(siteUrl($publicPath), $type, (int) $row['id']);
            }
            $items[] = [
                'type' => $type,
                'label' => $cfg['label'],
                'title' => $title,
                'cover' => $cover,
                'scheduled_for' => $row[$col],
                'edit_url' => sprintf(SCHEDULABLE_DASHBOARD_URLS[$type] ?? '#', (int) $row['id']),
                'preview_url' => $previewUrl,
            ];
        }
    }
    usort($items, fn ($a, $b) => strtotime($a['scheduled_for']) <=> strtotime($b['scheduled_for']));
    return $items;
}


// Come getScheduledContentForUser(), ma raccoglie gli elementi di TUTTI gli account — riservata
// all'admin. Ogni riga include user_id, slug e display_name del proprietario per il filtro
// account-per-account e per mostrare a chi appartiene ciascun contenuto.
function getScheduledContentForAllUsers(): array {
    $db = getDB();
    $items = [];
    foreach (PINNABLE_CONTENT_TYPES as $type => $cfg) {
        $col = pinnableScheduleColumn($cfg['visibility']);
        if ($col === null) {
            continue;
        }
        try {
            $stmt = $db->query(
                "SELECT t.*, u.slug AS user_slug, p.display_name AS user_display_name
                 FROM {$cfg['table']} t
                 JOIN users u ON u.id = t.user_id
                 LEFT JOIN profiles p ON p.user_id = u.id
                 WHERE t.{$col} > NOW()
                 ORDER BY t.{$col} ASC"
            );
        } catch (\Throwable $e) {
            continue;
        }
        foreach ($stmt->fetchAll() as $row) {
            $title = trim((string) ($row[$cfg['title_col']] ?? ''));
            if ($title === '') {
                $title = !empty($row['title']) ? $row['title'] : (!empty($row['image_path']) ? '📷 Foto' : '(senza titolo)');
            }
            $cover = null;
            foreach ($cfg['cover_cols'] as $coverCol) {
                if (!empty($row[$coverCol])) {
                    $cover = $row[$coverCol];
                    break;
                }
            }
            $slug = $row['user_slug'];
            $previewUrl = null;
            if ($slug) {
                $publicPath = ($type === 'blog') ? blogPostUrl($slug, $row) : sprintf($cfg['url_tpl'], $slug, (int) $row['id']);
                $previewUrl = withPreviewToken(siteUrl($publicPath), $type, (int) $row['id']);
            }
            $baseEditUrl = sprintf(SCHEDULABLE_DASHBOARD_URLS[$type] ?? '#', (int) $row['id']);
            $sep = str_contains($baseEditUrl, '?') ? '&' : '?';
            $editUrl = $baseEditUrl . $sep . 'acting_as=' . (int) $row['user_id'];
            $items[] = [
                'type' => $type,
                'label' => $cfg['label'],
                'title' => $title,
                'cover' => $cover,
                'scheduled_for' => $row[$col],
                'edit_url' => $editUrl,
                'preview_url' => $previewUrl,
                'user_id' => (int) $row['user_id'],
                'user_slug' => $slug,
                'user_display_name' => $row['user_display_name'] ?: $slug,
            ];
        }
    }
    usort($items, fn ($a, $b) => strtotime($a['scheduled_for']) <=> strtotime($b['scheduled_for']));
    return $items;
}


// Token di "anteprima riservata": permette di aprire un contenuto non ancora pubblico (privato o
// programmato nel futuro) senza essere autenticati — serve ad esempio al Tool di Meta per le
// Anteprime (Sharing Debugger), che non ha una sessione, per poter leggere gli og:tag prima che il
// contenuto diventi davvero pubblico. Stateless (HMAC su tipo+id, nessuna colonna DB da aggiungere
// a tabelle diverse): resta valido finché il contenuto resta privato/programmato, senza bisogno di
// una scadenza esplicita o di revoca — chi lo conosce può sempre vedere quello specifico elemento,
// stesso principio di un link "non in elenco" pubblico.
function previewTokenSecret(): string {
    static $secret = null;
    if ($secret === null) {
        $secret = 'chifacosa-preview:' . (getenv('DB_PASS') ?: 'chifacosa-fallback-secret');
    }
    return $secret;
}


function previewToken(string $type, int $id): string {
    return substr(hash_hmac('sha256', $type . ':' . $id, previewTokenSecret()), 0, 24);
}


function previewTokenValid(string $type, int $id, ?string $token): bool {
    return $token !== null && $token !== '' && hash_equals(previewToken($type, $id), $token);
}


// Aggiunge (o completa) il parametro ?preview=... a un URL pubblico già pronto.
function withPreviewToken(string $url, string $type, int $id): string {
    $sep = str_contains($url, '?') ? '&' : '?';
    return $url . $sep . 'preview=' . previewToken($type, $id);
}


// Frase finale del banner "non ancora pubblico" mostrato sulle pagine di dettaglio: il motivo
// (privato/programmato/disattivato) resta specifico per tipo e scritto in ciascuna pagina, cambia
// solo il "chi lo sta vedendo" a seconda che sia il proprietario o chi ha un link di anteprima.
function previewNoticeSuffix(bool $isOwner): string {
    return $isOwner
        ? 'lo vedi solo tu, come proprietario del profilo.'
        : "stai vedendo un'anteprima riservata tramite link — non è ancora visibile al pubblico.";
}


// Chiave usata da PINNABLE_CONTENT_TYPES per un "kind" dei moduli Che Amo (fan_favorite_item.php)
// — serve al token di anteprima, che è per-tipo, per restare coerente col link generato da
// dashboard_schedule.php indipendentemente da quale delle due pagine (colorful o AdminLTE) lo usa.
function fanFavoritePreviewType(string $kind): string {
    return match ($kind) {
        'band' => 'band_favorita',
        'actor' => 'attore_favorito',
        'movie' => 'film_favorito',
        'book' => 'libro_favorito',
        'playlist' => 'playlist_favorita',
        'album' => 'album_favorito',
        'recipe' => 'ricetta_favorita',
        'team' => 'squadra_favorita',
        'footballer' => 'calciatore_favorito',
        'match' => 'partita_favorita',
        'publication' => 'pubblicazione_favorita',
        default => $kind,
    };
}


// Badge degli attributi di un elemento nel Feed della dashboard (solo per i propri elementi):
// programmato (data di uscita futura), "Solo io" (non pubblico), non nel feed RSS.
function dashboardFeedBadgesHtml(array $item): string {
    $b = '';
    $st = 'font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;color:#fff;white-space:nowrap;';
    if (!empty($item['data']) && strtotime($item['data']) > time()) {
        $b .= '<span style="background:#f0ad4e;' . $st . '">⏰ Programmato per il ' . e(formatLocalDateTime($item['data'], ['dashboard_theme' => $item['owner_tz'] ?? null])) . '</span> ';
    }
    if (isset($item['x_pub']) && (int) $item['x_pub'] === 0) {
        $b .= '<span style="background:#6c757d;' . $st . '">🔒 Solo io</span> ';
    }
    if (isset($item['in_feed']) && (int) $item['in_feed'] === 0) {
        $b .= '<span style="background:#6c757d;' . $st . '">Non nel Feed</span> ';
    }
    return $b === '' ? '' : '<div style="display:flex;gap:6px;flex-wrap:wrap;margin:2px 0 4px;">' . $b . '</div>';
}


// Arricchisce gli elementi del Feed della dashboard con dati usati dalla tabellina delle proprietà:
// 'x_author' (firma, dove esiste la colonna created_by) e 'x_queue' (ultimo stato nella coda social).
// Solo per gli elementi del profilo attivo; qualunque errore (colonne non migrate) lascia l'elemento invariato.
function dashboardFeedEnrich(array $items, int $profileId, string $viewerSlug): array {
    $authorTables = ['blog' => 'blog_posts', 'pensiero' => 'timeline_posts', 'album_foto' => 'photo_albums', 'servizio' => 'services', 'evento' => 'events', 'video' => 'videos'];
    $queueTypes = dashboardFeedQueueTypeMap();
    $byAuthor = [];
    $byQueue = [];
    foreach ($items as $it) {
        if ($it['user_slug'] !== $viewerSlug || empty($it['item_id'])) {
            continue;
        }
        if (isset($authorTables[$it['tipo']])) {
            $byAuthor[$it['tipo']][] = (int) $it['item_id'];
        }
        if (isset($queueTypes[$it['tipo']])) {
            $byQueue[$queueTypes[$it['tipo']]][] = (int) $it['item_id'];
        }
    }
    $authors = [];
    foreach ($byAuthor as $tipo => $ids) {
        try {
            $ids = array_values(array_unique($ids));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = getDB()->prepare('SELECT id, created_by FROM `' . $authorTables[$tipo] . "` WHERE user_id = ? AND id IN ($in)");
            $st->execute(array_merge([$profileId], $ids));
            foreach ($st->fetchAll() as $r) {
                $authors[$tipo][(int) $r['id']] = $r['created_by'];
            }
        } catch (Throwable $e) {
        }
    }
    $queue = [];
    foreach ($byQueue as $qType => $ids) {
        try {
            $ids = array_values(array_unique($ids));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = getDB()->prepare("SELECT content_id, status, suspended, scheduled_for FROM social_queue WHERE user_id = ? AND content_type = ? AND content_id IN ($in) ORDER BY id ASC");
            $st->execute(array_merge([$profileId, $qType], $ids));
            foreach ($st->fetchAll() as $r) {
                $queue[$qType][(int) $r['content_id']] = $r; // l'ultima riga prevale
            }
        } catch (Throwable $e) {
        }
    }
    foreach ($items as &$it) {
        if ($it['user_slug'] !== $viewerSlug) {
            continue;
        }
        if (isset($authorTables[$it['tipo']])) {
            $it['x_author'] = $authors[$it['tipo']][(int) $it['item_id']] ?? '';
            $it['x_author_known'] = true;
        }
        if (isset($queueTypes[$it['tipo']])) {
            $it['x_queue'] = $queue[$queueTypes[$it['tipo']]][(int) $it['item_id']] ?? null;
            $it['x_queueable'] = true;
        }
    }
    unset($it);
    return $items;
}


// Tipo del feed ('tipo') -> content_type usato nella coda social.
function dashboardFeedQueueTypeMap(): array {
    return ['band_favorita' => 'fan_band', 'attore_favorito' => 'fan_actor', 'film_favorito' => 'fan_movie', 'libro_favorito' => 'fan_book', 'viaggio_favorito' => 'fan_trip', 'playlist_favorita' => 'fan_playlist', 'album_favorito' => 'fan_album', 'ricetta_favorita' => 'fan_recipe', 'squadra_favorita' => 'fan_team', 'calciatore_favorito' => 'fan_footballer', 'partita_favorita' => 'fan_match', 'pubblicazione_favorita' => 'fan_publication', 'brano' => 'fan_track', 'blog' => 'blog', 'evento' => 'event', 'pensiero' => 'timeline', 'offerta' => 'offer', 'album_foto' => 'album', 'video' => 'video', 'servizio' => 'service'];
}


// Proprietà di un elemento del Feed (le stesse che si possono filtrare in alto nella pagina Feed).
function dashboardFeedItemProps(array $item): array {
    $q = $item['x_queue'] ?? null;
    $susp = $q && !empty($q['suspended']) && in_array($q['status'], ['pending', 'processing'], true);
    return [
        'stato'      => (!empty($item['data']) && strtotime($item['data']) > time()) ? 'programmato' : 'pubblicato',
        'visibilita' => (isset($item['x_pub']) && (int) $item['x_pub'] === 0) ? 'solo_io' : 'pubblico',
        'feed'       => (isset($item['in_feed']) && (int) $item['in_feed'] === 0) ? 'fuori' : 'nel',
        'coda'       => !$q ? 'none' : ($susp ? 'suspended' : (string) $q['status']),
        'autore'     => empty($item['x_author_known']) ? null : (trim((string) ($item['x_author'] ?? '')) !== '' ? trim((string) $item['x_author']) : '__none'),
    ];
}


// Filtri del Feed dalla querystring (solo valori ammessi; l'autore è testo libero ma limitato).
function dashboardFeedFilters(array $src): array {
    $allowed = [
        'stato'      => ['pubblicato', 'programmato'],
        'visibilita' => ['pubblico', 'solo_io'],
        'feed'       => ['nel', 'fuori'],
        'coda'       => ['none', 'pending', 'processing', 'scheduled', 'done', 'failed', 'skipped', 'suspended'],
    ];
    $f = [];
    foreach ($allowed as $k => $vals) {
        $v = (string) ($src[$k] ?? '');
        $f[$k] = in_array($v, $vals, true) ? $v : '';
    }
    $f['autore'] = mb_substr(trim((string) ($src['autore'] ?? '')), 0, 160);
    return $f;
}


function dashboardFeedFiltersActive(array $f): bool {
    return count(array_filter($f, fn($v) => $v !== '')) > 0;
}


function dashboardFeedItemMatches(array $item, array $f): bool {
    $p = dashboardFeedItemProps($item);
    foreach (['stato', 'visibilita', 'feed', 'coda'] as $k) {
        if ($f[$k] !== '' && $p[$k] !== $f[$k]) {
            return false;
        }
    }
    if ($f['autore'] !== '' && $p['autore'] !== $f['autore']) {
        return false;
    }
    return true;
}


// Feed filtrato: solo gli elementi del profilo attivo (i filtri riguardano i propri contenuti),
// arricchiti con coda e firma, filtrati PRIMA di impaginare così "carica altri" resta coerente.
function dashboardFeedFiltered(int $profileId, string $slug, array $f, int $limit, int $offset): array {
    $all = dashboardFeedEnrich(getTimelineFeedForUsers([$profileId], 2000, 0, $profileId), $profileId, $slug);
    $all = array_values(array_filter($all, fn($it) => dashboardFeedItemMatches($it, $f)));
    return array_slice($all, $offset, $limit);
}


// Firme presenti nei contenuti del profilo, per il filtro "Autore".
function dashboardFeedAuthorOptions(int $profileId): array {
    $out = [];
    foreach (['blog_posts', 'timeline_posts', 'photo_albums', 'services', 'events', 'videos'] as $t) {
        try {
            $st = getDB()->prepare("SELECT DISTINCT created_by FROM `$t` WHERE user_id = ? AND created_by IS NOT NULL AND created_by <> ''");
            $st->execute([$profileId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
                $out[$v] = true;
            }
        } catch (Throwable $e) {
        }
    }
    $names = array_keys($out);
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
}


// Barra dei filtri in cima al Feed (GET, si applicano subito alla scelta).
function dashboardFeedFilterBarHtml(array $f, array $authors, bool $metricoolActive, string $viewMode): string {
    $sel = function (string $name, string $label, array $opts) use ($f): string {
        $h = '<label style="display:flex;flex-direction:column;gap:2px;font-size:11.5px;color:var(--text-muted);font-weight:600;min-width:110px;flex:1 1 110px;">' . e($label)
           . '<select name="' . e($name) . '" onchange="this.form.submit()" style="margin:0;padding:6px 8px;font-size:13px;' . ($f[$name] !== '' ? 'border-color:var(--accent);' : '') . '">';
        foreach ($opts as $v => $t) {
            $h .= '<option value="' . e((string) $v) . '"' . ((string) $f[$name] === (string) $v ? ' selected' : '') . '>' . e($t) . '</option>';
        }
        return $h . '</select></label>';
    };
    $authorOpts = ['' => 'Tutti', '__none' => 'Non registrato'];
    foreach ($authors as $a) {
        $authorOpts[$a] = $a;
    }
    if ($f['autore'] !== '' && !isset($authorOpts[$f['autore']])) {
        $authorOpts[$f['autore']] = $f['autore'];
    }
    $h = '<form method="get" style="display:flex;flex-wrap:wrap;gap:10px 12px;align-items:flex-end;">'
       . '<input type="hidden" name="view" value="' . e($viewMode) . '"><input type="hidden" name="open" value="1">'
       . $sel('stato', 'Stato', ['' => 'Tutti', 'pubblicato' => 'Pubblicato', 'programmato' => 'Programmato'])
       . $sel('visibilita', 'Visibilità', ['' => 'Tutti', 'pubblico' => 'Pubblico', 'solo_io' => 'Solo io'])
       . $sel('feed', 'Feed', ['' => 'Tutti', 'nel' => 'Nel Feed', 'fuori' => 'Non nel Feed']);
    if ($metricoolActive) {
        $h .= $sel('coda', 'Coda social', ['' => 'Tutti', 'none' => 'Non in coda', 'pending' => 'In attesa', 'processing' => 'In elaborazione', 'scheduled' => 'Programmato', 'done' => 'Completato', 'failed' => 'Errore', 'skipped' => 'Saltato', 'suspended' => 'Sospeso']);
    }
    $h .= $sel('autore', 'Autore', $authorOpts);
    if (dashboardFeedFiltersActive($f)) {
        $h .= '<a href="?view=' . e($viewMode) . '&open=1" class="btn small secondary" style="align-self:flex-end;">Azzera filtri</a>'
            . '<span style="flex-basis:100%;font-size:12px;color:var(--text-muted);">I filtri riguardano i tuoi contenuti: gli elementi dei profili che segui non sono inclusi.</span>';
    }
    return $h . '</form>';
}


// Attributo data-fp per gli elementi del Feed di ALTRI profili: il clic apre un popup di anteprima (con il tasto verso la
// pagina pubblica) invece della pagina di modifica, che esiste solo per il proprietario ("Articolo non trovato").
function dashboardFeedPreviewAttr(array $item, string $label): string {
    $cover = $item['cover_thumb'] ?? $item['cover'] ?? null;
    $data = [
        'tipo'   => (string) ($item['tipo'] ?? ''),
        'id'     => (int) ($item['item_id'] ?? 0),
        'titolo' => (string) ($item['titolo'] ?? ''),
        'label'  => $label,
        'autore' => (string) ($item['display_name'] ?? ''),
        'slug'   => (string) ($item['user_slug'] ?? ''),
        'avatar' => !empty($item['avatar']) ? '/' . ltrim((string) $item['avatar'], '/') : '',
        'cover'  => $cover ? (str_starts_with($cover, 'http') ? $cover : '/' . ltrim($cover, '/')) : '',
        'data'   => !empty($item['data']) ? formatLocalDateTime($item['data'], ['dashboard_theme' => $item['owner_tz'] ?? null]) : '',
        'url'    => (string) ($item['url'] ?? '#'),
    ];
    return ' data-fp="' . htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '"';
}


/** Tipo del feed => tipo della Coda Social (per il pulsante «Condividi sui social / Coda social»). Unica fonte per Feed classico e Studio. */
function dashboardFeedSqTypeMap(): array {
    return ['band_favorita' => 'fan_band', 'attore_favorito' => 'fan_actor', 'film_favorito' => 'fan_movie', 'libro_favorito' => 'fan_book', 'viaggio_favorito' => 'fan_trip', 'playlist_favorita' => 'fan_playlist', 'album_favorito' => 'fan_album', 'ricetta_favorita' => 'fan_recipe', 'squadra_favorita' => 'fan_team', 'calciatore_favorito' => 'fan_footballer', 'partita_favorita' => 'fan_match', 'pubblicazione_favorita' => 'fan_publication', 'brano' => 'fan_track', 'blog' => 'blog', 'evento' => 'event', 'pensiero' => 'timeline', 'offerta' => 'offer', 'album_foto' => 'album', 'video' => 'video', 'servizio' => 'service'];
}

function renderDashboardTimelineItem(array $item, ?string $viewerSlug = null, bool $metricoolActive = false): string {
    // Nel feed si mostra sempre la miniatura leggera quando disponibile (image_thumb_path):
    // l'originale a piena qualità resta comunque intatto ed è quello mostrato aprendo il link.
    $cover = $item['cover_thumb'] ?? $item['cover'];
    $coverSrc = $cover ? (str_starts_with($cover, 'http') ? $cover : '/' . $cover) : null;
    $labels = ['blog' => '📝 Articolo', 'brano' => '🎵 Brano che amo', 'evento' => '📅 Evento', 'pensiero' => '💬 Aggiornamento', 'band_favorita' => '❤️ Band che amo', 'attore_favorito' => '🎬 Attore che amo', 'film_favorito' => '🍿 Film che amo', 'libro_favorito' => '📚 Libro che amo', 'viaggio_favorito' => '✈️ Viaggio', 'playlist_favorita' => '🎧 Playlist che amo', 'album_favorito' => '💿 Album che amo', 'offerta' => '🏷️ Offerta speciale', 'album_foto' => '📸 Album fotografico', 'video' => '🎬 Video', 'servizio' => '💼 Servizio', 'ricetta_favorita' => '🍲 Ricetta che amo', 'squadra_favorita' => '🛡️ Squadra che amo', 'calciatore_favorito' => '👕 Calciatore che amo', 'partita_favorita' => '⚽ Partita che amo', 'pubblicazione_favorita' => '🔬 Pubblicazione che amo'];
    $label = $labels[$item['tipo']] ?? '';
    $eventoInfo = '';
    if ($item['tipo'] === 'evento') {
        $scheduleLabel = eventScheduleLabel($item['evento_recurrence'] ?? 'none', (bool) ($item['evento_is_perpetual'] ?? false));
        if ($scheduleLabel) {
            $eventoInfo = ' · ' . e($scheduleLabel);
        } elseif (!empty($item['evento_quando'])) {
            $eventoInfo = ' · si terrà il ' . e(formatLocalDateTime($item['evento_quando'], ['dashboard_theme' => $item['owner_tz'] ?? null]));
        }
    }
    // Sfondo grigio tenue per distinguere subito i propri contenuti dal resto del feed
    $isMine = $viewerSlug !== null && $item['user_slug'] === $viewerSlug;
    $bgStyle = $isMine ? 'background:#eef0f2;' : '';
    // Solo i propri contenuti si modificano in dashboard; quelli degli altri profili aprono un'anteprima.
    $editUrl = $isMine ? ($item['edit_url'] ?? $item['url']) : $item['url'];
    $html = '<div class="dtl-row" style="display:flex;gap:0;align-items:center;' . $bgStyle . '">';
    $html .= '<a href="' . e($editUrl) . '" class="link-item"' . ($isMine ? '' : dashboardFeedPreviewAttr($item, $label)) . ' style="display:flex;gap:12px;align-items:center;text-decoration:none;color:inherit;flex:1;min-width:0;">';
    if ($coverSrc) {
        $html .= '<img src="' . e($coverSrc) . '" style="width:56px;height:56px;border-radius:8px;object-fit:cover;flex-shrink:0;">';
    } elseif (!empty($item['avatar'])) {
        $html .= '<img src="/' . e($item['avatar']) . '" style="width:56px;height:56px;border-radius:50%;object-fit:cover;flex-shrink:0;">';
    }
    $html .= '<div style="flex:1;min-width:0;">';
    $html .= '<small style="color:var(--text-muted);text-transform:uppercase;">' . e($label) . ' · ' . e($item['display_name']) . ($isMine ? ' <span style="color:var(--accent);font-weight:700;">(tu)</span>' : '') . '</small><br>';
    $html .= '<strong>' . e($item['titolo']) . '</strong><br>';
    $isFuture = !empty($item['data']) && strtotime($item['data']) > time();
    $html .= '<small style="color:var(--text-muted)">' . ($isFuture ? 'Da pubblicare il ' : 'Pubblicato il ') . e(italianLongDateTime($item['data'], ['dashboard_theme' => $item['owner_tz'] ?? null])) . $eventoInfo . '</small>';
    $html .= '</div></a>';
    if (!empty($item['redirect_link'])) {
        $html .= '<a href="' . e($item['redirect_link']) . '" target="_blank" rel="noopener" class="dtc-redirect-btn" title="Verifica link personalizzato">🔗</a>';
    }
    if ($isMine && $metricoolActive) {
        $sqTypeMap = dashboardFeedSqTypeMap();
        $sqType = $sqTypeMap[$item['tipo']] ?? null;
        if ($sqType) {
            $cover = $item['cover_thumb'] ?? $item['cover'];
            $imgUrl = $cover ? (str_starts_with($cover, 'http') ? $cover : siteUrl('/' . ltrim($cover, '/'))) : null;
            $sq = json_encode(['suggest' => true, 'type' => $sqType, 'id' => (int) $item['item_id'], 'title' => $item['titolo'], 'img' => $imgUrl, 'url' => siteUrl($item['url'])]);
            $html .= '<button type="button" onclick=\'window.sqSuggest(' . htmlspecialchars($sq, ENT_QUOTES) . ')\' title="Condividi sui social" style="background:none;border:none;cursor:pointer;font-size:15px;padding:4px 8px;color:var(--text-muted);">📤</button>';
        }
    }
    $html .= '</div>';
    return $html;
}


function renderDashboardTimelineCard(array $item, ?string $viewerSlug = null, bool $metricoolActive = false): string {
    $cover = $item['cover'];
    $coverSrc = $cover ? (str_starts_with($cover, 'http') ? $cover : '/' . $cover) : null;
    $labels = ['blog' => '📝 Articolo', 'brano' => '🎵 Brano che amo', 'evento' => '📅 Evento', 'pensiero' => '💬 Aggiornamento', 'band_favorita' => '❤️ Band che amo', 'attore_favorito' => '🎬 Attore che amo', 'film_favorito' => '🍿 Film che amo', 'libro_favorito' => '📚 Libro che amo', 'viaggio_favorito' => '✈️ Viaggio', 'playlist_favorita' => '🎧 Playlist che amo', 'album_favorito' => '💿 Album che amo', 'offerta' => '🏷️ Offerta speciale', 'album_foto' => '📸 Album fotografico', 'video' => '🎬 Video', 'servizio' => '💼 Servizio', 'ricetta_favorita' => '🍲 Ricetta che amo', 'squadra_favorita' => '🛡️ Squadra che amo', 'calciatore_favorito' => '👕 Calciatore che amo', 'partita_favorita' => '⚽ Partita che amo', 'pubblicazione_favorita' => '🔬 Pubblicazione che amo'];
    $label = $labels[$item['tipo']] ?? '';
    $eventoInfo = '';
    if ($item['tipo'] === 'evento') {
        $scheduleLabel = eventScheduleLabel($item['evento_recurrence'] ?? 'none', (bool) ($item['evento_is_perpetual'] ?? false));
        if ($scheduleLabel) {
            $eventoInfo = ' · ' . e($scheduleLabel);
        } elseif (!empty($item['evento_quando'])) {
            $eventoInfo = ' · si terrà il ' . e(formatLocalDateTime($item['evento_quando'], ['dashboard_theme' => $item['owner_tz'] ?? null]));
        }
    }
    $isMine = $viewerSlug !== null && $item['user_slug'] === $viewerSlug;
    $mineClass = $isMine ? ' dtc-mine' : '';
    $editUrl = $isMine ? ($item['edit_url'] ?? $item['url']) : $item['url'];
    $html = '<div class="dtc-card' . $mineClass . '" style="position:relative;">';
    $html .= '<a href="' . e($editUrl) . '"' . ($isMine ? '' : dashboardFeedPreviewAttr($item, $label)) . ' style="display:block;text-decoration:none;color:inherit;">';
    $html .= '<div class="dtc-header">';
    if (!empty($item['avatar'])) {
        $html .= '<img class="dtc-avatar" src="/' . e($item['avatar']) . '">';
    }
    $html .= '<div class="dtc-author">';
    $html .= '<span class="dtc-name">' . e($item['display_name']);
    if ($isMine) {
        $html .= ' <span class="dtc-you">(tu)</span>';
    }
    $html .= '</span>';
    $html .= '<span class="dtc-date">' . e(formatLocalDateTime($item['data'], ['dashboard_theme' => $item['owner_tz'] ?? null])) . '</span>';
    $html .= '</div>';
    $html .= '<span class="dtc-badge">' . e($label) . '</span>';
    $html .= '</div>';
    if ($coverSrc) {
        $html .= '<img class="dtc-cover" src="' . e($coverSrc) . '">';
    }
    $html .= '<div class="dtc-body">';
    if ($isMine) {
        $html .= dashboardFeedBadgesHtml($item);
    }
    $html .= '<strong>' . e($item['titolo']) . '</strong>';
    if ($eventoInfo) {
        $html .= '<div class="dtc-evento-info">' . $eventoInfo . '</div>';
    }
    $html .= '</div>';
    $html .= '</a>';
    if (!empty($item['redirect_link'])) {
        $html .= '<a href="' . e($item['redirect_link']) . '" target="_blank" rel="noopener" class="dtc-redirect-btn" title="Verifica link personalizzato" style="position:absolute;top:12px;right:12px;">🔗</a>';
    }
    if ($isMine && $metricoolActive) {
        $sqTypeMapCard = dashboardFeedSqTypeMap();
        $sqType = $sqTypeMapCard[$item['tipo']] ?? null;
        if ($sqType) {
            $cover = $item['cover'];
            $imgUrl = $cover ? (str_starts_with($cover, 'http') ? $cover : siteUrl('/' . ltrim($cover, '/'))) : null;
            $sq = json_encode(['suggest' => true, 'type' => $sqType, 'id' => (int) $item['item_id'], 'title' => $item['titolo'], 'img' => $imgUrl, 'url' => siteUrl($item['url'])]);
            $html .= '<button type="button" onclick=\'window.sqSuggest(' . htmlspecialchars($sq, ENT_QUOTES) . ')\' title="Condividi sui social" style="position:absolute;bottom:10px;right:10px;background:none;border:none;cursor:pointer;font-size:15px;padding:4px 6px;color:var(--text-muted);">📤</button>';
        }
    }
    $html .= '</div>';
    return $html;
}


function renderTimelineFeedItem(array $item): string {
    // Vedi commento in renderDashboardTimelineItem(): stessa logica, miniatura leggera in lista.
    $cover = $item['cover_thumb'] ?? $item['cover'];
    $coverSrc = $cover ? (str_starts_with($cover, 'http') ? $cover : '/' . $cover) : null;
    $labels = ['blog' => '📝 Articolo', 'brano' => '🎵 Brano che amo', 'evento' => '📅 Evento', 'pensiero' => '💬 Aggiornamento', 'band_favorita' => '❤️ Band che amo', 'attore_favorito' => '🎬 Attore che amo', 'film_favorito' => '🍿 Film che amo', 'libro_favorito' => '📚 Libro che amo', 'viaggio_favorito' => '✈️ Viaggio', 'playlist_favorita' => '🎧 Playlist che amo', 'album_favorito' => '💿 Album che amo', 'offerta' => '🏷️ Offerta speciale', 'album_foto' => '📸 Album fotografico', 'video' => '🎬 Video', 'servizio' => '💼 Servizio', 'ricetta_favorita' => '🍲 Ricetta che amo', 'squadra_favorita' => '🛡️ Squadra che amo', 'calciatore_favorito' => '👕 Calciatore che amo', 'partita_favorita' => '⚽ Partita che amo', 'pubblicazione_favorita' => '🔬 Pubblicazione che amo'];
    $label = $labels[$item['tipo']] ?? '';
    $eventoInfo = '';
    if ($item['tipo'] === 'evento') {
        $scheduleLabel = eventScheduleLabel($item['evento_recurrence'] ?? 'none', (bool) ($item['evento_is_perpetual'] ?? false));
        if ($scheduleLabel) {
            $eventoInfo = ' · ' . e($scheduleLabel);
        } elseif (!empty($item['evento_quando'])) {
            $eventoInfo = ' · si terrà il ' . e(formatLocalDateTime($item['evento_quando'], ['dashboard_theme' => $item['owner_tz'] ?? null]));
        }
    }
    $html = '<a href="' . e($item['url']) . '" class="card" style="display:flex;gap:14px;align-items:center;text-decoration:none;color:inherit;">';
    if ($coverSrc) {
        $html .= '<img src="' . e($coverSrc) . '" style="width:64px;height:64px;border-radius:10px;object-fit:cover;flex-shrink:0;">';
    }
    $html .= '<div style="flex:1;min-width:0;">';
    $html .= '<small style="color:rgba(var(--text-rgb),0.6);text-transform:uppercase;">' . e($label) . '</small><br>';
    $html .= '<strong>' . e($item['titolo']) . '</strong><br>';
    $html .= '<small style="color:rgba(var(--text-rgb),0.6);">' . e(formatLocalDateTime($item['data'], ['dashboard_theme' => $item['owner_tz'] ?? null])) . $eventoInfo . '</small>';
    $html .= '</div></a>';
    return $html;
}
