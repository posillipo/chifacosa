<?php require_once __DIR__ . '/_studio_boot.php'; /* Override di Studio: azioni identiche alla pagina originale + 'reorder' (trascinamento) */ ?>
<?php
session_start();
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/geocoding.php';
$user = requireLogin();
$profile = getActingProfile($user); requireFullOwnerAccess($user, $profile);
$activeTab = 'links';
$pageTitle = 'Link';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $label = trim($_POST['label'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $isWebsite = isset($_POST['is_website_icon']) ? 1 : 0;
        if ($label !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $coverPath = handleCoverUpload($profile['slug']);
            $stmt = getDB()->prepare('INSERT INTO links (user_id, label, url, is_website_icon, cover_path, sort_order) VALUES (?,?,?,?,?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM links WHERE user_id=?) t))');
            $stmt->execute([$profile['id'], $label, $url, $isWebsite, $coverPath, $profile['id']]);
        } else {
            $error = 'Inserisci un\'etichetta e un URL valido.';
        }
    } elseif ($action === 'add_divider') {
        $label = trim($_POST['label'] ?? '');
        if ($label !== '') {
            $stmt = getDB()->prepare("INSERT INTO links (user_id, label, url, link_type, sort_order) VALUES (?,?,'','divider', (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM links WHERE user_id=?) t))");
            $stmt->execute([$profile['id'], $label, $profile['id']]);
        } else {
            $error = 'Inserisci un titolo per il separatore.';
        }
    } elseif ($action === 'add_map') {
        $address = trim($_POST['address'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $latRaw = str_replace(',', '.', trim($_POST['map_lat'] ?? ''));
        $lngRaw = str_replace(',', '.', trim($_POST['map_lng'] ?? ''));

        if ($latRaw !== '' && $lngRaw !== '' && is_numeric($latRaw) && is_numeric($lngRaw)) {
            $geo = ['lat' => (float) $latRaw, 'lng' => (float) $lngRaw, 'display_name' => null];
        } elseif ($address !== '') {
            $geo = geocodeAddress($address);
            if (!$geo) {
                header('Location: /dashboard_links.php?geocode_error=1');
                exit;
            }
        } else {
            header('Location: /dashboard_links.php?map_input_error=1');
            exit;
        }
        $mapLabel = $label !== '' ? $label : ($geo['display_name'] ?? (number_format($geo['lat'], 5) . ', ' . number_format($geo['lng'], 5)));
        $stmt = getDB()->prepare("INSERT INTO links (user_id, label, url, link_type, map_lat, map_lng, sort_order) VALUES (?,?,'','map',?,?, (SELECT n FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM links WHERE user_id=?) t))");
        $stmt->execute([$profile['id'], $mapLabel, $geo['lat'], $geo['lng'], $profile['id']]);
    } elseif ($action === 'update_link') {
        $id = (int) ($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $isWebsite = isset($_POST['is_website_icon']) ? 1 : 0;
        if ($label !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $newCover = handleCoverUpload($profile['slug']);
            if ($newCover) {
                $stmt = getDB()->prepare('UPDATE links SET label=?, url=?, is_website_icon=?, cover_path=? WHERE id=? AND user_id=?');
                $stmt->execute([$label, $url, $isWebsite, $newCover, $id, $profile['id']]);
            } else {
                $stmt = getDB()->prepare('UPDATE links SET label=?, url=?, is_website_icon=? WHERE id=? AND user_id=?');
                $stmt->execute([$label, $url, $isWebsite, $id, $profile['id']]);
            }
        } else {
            header('Location: /dashboard_links.php?edit=' . $id . '&error=1');
            exit;
        }
    } elseif ($action === 'update_divider') {
        $id = (int) ($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        if ($label !== '') {
            $stmt = getDB()->prepare("UPDATE links SET label=? WHERE id=? AND user_id=? AND link_type='divider'");
            $stmt->execute([$label, $id, $profile['id']]);
        } else {
            header('Location: /dashboard_links.php?edit=' . $id . '&error=1');
            exit;
        }
    } elseif ($action === 'update_map') {
        $id = (int) ($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $latRaw = str_replace(',', '.', trim($_POST['map_lat'] ?? ''));
        $lngRaw = str_replace(',', '.', trim($_POST['map_lng'] ?? ''));
        if ($latRaw !== '' && $lngRaw !== '' && is_numeric($latRaw) && is_numeric($lngRaw)) {
            if ($label !== '') {
                $stmt = getDB()->prepare("UPDATE links SET label=?, map_lat=?, map_lng=? WHERE id=? AND user_id=? AND link_type='map'");
                $stmt->execute([$label, (float) $latRaw, (float) $lngRaw, $id, $profile['id']]);
            } else {
                $stmt = getDB()->prepare("UPDATE links SET map_lat=?, map_lng=? WHERE id=? AND user_id=? AND link_type='map'");
                $stmt->execute([(float) $latRaw, (float) $lngRaw, $id, $profile['id']]);
            }
        } elseif ($address !== '') {
            $geo = geocodeAddress($address);
            if (!$geo) {
                header('Location: /dashboard_links.php?edit=' . $id . '&geocode_error=1');
                exit;
            }
            $mapLabel = $label !== '' ? $label : $geo['display_name'];
            $stmt = getDB()->prepare("UPDATE links SET label=?, map_lat=?, map_lng=? WHERE id=? AND user_id=? AND link_type='map'");
            $stmt->execute([$mapLabel, $geo['lat'], $geo['lng'], $id, $profile['id']]);
        } elseif ($label !== '') {
            $stmt = getDB()->prepare("UPDATE links SET label=? WHERE id=? AND user_id=? AND link_type='map'");
            $stmt->execute([$label, $id, $profile['id']]);
        }
    } elseif ($action === 'toggle_website') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('UPDATE links SET is_website_icon = NOT is_website_icon WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT cover_path FROM links WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
        if ($row = $stmt->fetch()) {
            deleteCoverFile($row['cover_path']);
        }
        $stmt = getDB()->prepare('DELETE FROM links WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('UPDATE links SET is_active = NOT is_active WHERE id=? AND user_id=?');
        $stmt->execute([$id, $profile['id']]);
    } elseif ($action === 'sync_section_links') {
        $result = syncSectionLinksForProfile($profile);
        if ($result['ok']) {
            header('Location: /dashboard_links.php?sync_ok=1&added=' . $result['added'] . '&updated=' . $result['updated'] . '&removed=' . $result['removed']);
        } else {
            header('Location: /dashboard_links.php?sync_error=' . rawurlencode($result['error']));
        }
        exit;
    } elseif ($action === 'reorder') {
        // Nuovo ordine dal trascinamento: elenco di id separati da virgola, solo link di questo profilo.
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? '')))));
        $pos = 1;
        $upd = getDB()->prepare('UPDATE links SET sort_order=? WHERE id=? AND user_id=?');
        foreach ($ids as $rid) {
            $upd->execute([$pos++, $rid, $profile['id']]);
        }
        http_response_code(204);
        exit;
    } elseif ($action === 'move_up' || $action === 'move_down') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = getDB()->prepare('SELECT id, sort_order FROM links WHERE user_id=? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$profile['id']]);
        $all = $stmt->fetchAll();
        $idx = null;
        foreach ($all as $i => $row) {
            if ((int)$row['id'] === $id) { $idx = $i; break; }
        }
        if ($idx !== null) {
            $swapIdx = $action === 'move_up' ? $idx - 1 : $idx + 1;
            if (isset($all[$swapIdx])) {
                $a = $all[$idx];
                $b = $all[$swapIdx];
                getDB()->prepare('UPDATE links SET sort_order=? WHERE id=? AND user_id=?')->execute([$b['sort_order'], $a['id'], $profile['id']]);
                getDB()->prepare('UPDATE links SET sort_order=? WHERE id=? AND user_id=?')->execute([$a['sort_order'], $b['id'], $profile['id']]);
            }
        }
    }
    header('Location: /dashboard_links.php');
    exit;
}

$stmt = getDB()->prepare('SELECT * FROM links WHERE user_id=? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$profile['id']]);
$links = $stmt->fetchAll();

// Modalità modifica: se è presente ?edit=ID, precarichiamo quel link nel form al posto di "Aggiungi"
$editingLink = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    foreach ($links as $l) {
        if ((int)$l['id'] === $editId) { $editingLink = $l; break; }
    }
}

// Stessa suddivisione usata per costruire davvero la pagina pubblica (vedi splitSocialAndActionLinks
// in functions.php): così l'elenco qui in dashboard mostra esattamente quali link diventeranno
// icone social in cima alla pagina e quali restano pulsanti normali, invece di un elenco unico.
list($socialLinks, $actionLinks) = splitSocialAndActionLinks($links);
$linkIndex = [];
foreach ($links as $i => $l) {
    $linkIndex[$l['id']] = $i;
}

$linkJson = [];
$totalClicks = 0; $maxClicks = 0; $activeN = 0;
foreach ($links as $l) {
    $totalClicks += (int) $l['click_count'];
    $maxClicks = max($maxClicks, (int) $l['click_count']);
    if ($l['is_active']) { $activeN++; }
}
$socialIds = array_map(fn ($l) => (int) $l['id'], $socialLinks);
$pubUrl = siteUrl('/' . $profile['slug']);
$tlName = trim((string) ($profile['display_name'] ?? ''));
$tlAvatar = !empty($profile['avatar_path']) ? '/' . ltrim($profile['avatar_path'], '/') : '';
include __DIR__ . '/../_dash_header.php';
$share = json_encode(['t' => $tlName ?: $profile['slug'], 'u' => $pubUrl], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <?php if (isset($_GET['error'])): ?><div class="alert error">Inserisci un'etichetta e un URL valido.</div><?php endif; ?>
  <?php if (isset($_GET['geocode_error'])): ?><div class="alert error">Indirizzo non trovato. Prova a scriverlo in modo più preciso (via, numero civico, città).</div><?php endif; ?>
  <?php if (isset($_GET['map_input_error'])): ?><div class="alert error">Inserisci un indirizzo oppure le coordinate.</div><?php endif; ?>
  <?php if (isset($_GET['sync_ok'])): ?><div class="alert success">Sezioni sincronizzate: <?= (int) ($_GET['added'] ?? 0) ?> aggiunte, <?= (int) ($_GET['updated'] ?? 0) ?> aggiornate, <?= (int) ($_GET['removed'] ?? 0) ?> rimosse.</div><?php endif; ?>
  <?php if (isset($_GET['sync_error'])): ?><div class="alert error"><?= e($_GET['sync_error']) ?></div><?php endif; ?>

  <div class="lkx" data-profile="<?= e($tlName ?: $profile['slug']) ?>" data-slug="<?= e($profile['slug']) ?>" data-avatar="<?= e($tlAvatar) ?>"
       data-platforms='<?= e(json_encode(array_values(array_map(fn ($d, $i) => ['d' => $d, 'k' => $i['key'], 'i' => $i['icon_class'], 'l' => $i['label']], array_keys(platformMap()), platformMap())), JSON_UNESCAPED_SLASHES)) ?>'>

    <div class="lkx-top">
      <div class="tlx-kpis">
        <div class="tlx-kpi"><b id="lkx-n"><?= $activeN ?></b><span>Link visibili</span></div>
        <div class="tlx-kpi"><b><?= $totalClicks ?></b><span>Click totali</span></div>
        <a class="tlx-kpi tlx-kpi-link" href="/<?= e($profile['slug']) ?>" target="_blank"><b><i class="fa-solid fa-arrow-up-right-from-square"></i></b><span>Apri la tua pagina</span></a>
        <button type="button" class="tlx-kpi tlx-kpi-link lkx-copy" data-share='<?= e($share) ?>'><b><i class="fa-solid fa-share-nodes"></i></b><span>Condividi il link</span></button>
      </div>
    </div>

    <div class="lkx-grid">
      <div class="lkx-main">
        <form method="post" enctype="multipart/form-data" class="lkx-add" id="lkx-add" autocomplete="off">
          <?= csrfField() ?>
          <input type="hidden" name="action" id="lkx-action" value="add">
          <input type="hidden" name="label" id="lkx-label-h">
          <input type="hidden" name="url" id="lkx-url-h">
          <input type="hidden" name="address" id="lkx-addr-h">
          <input type="checkbox" name="is_website_icon" value="1" id="lkx-site-h" hidden>
          <label class="lkx-bar" for="lkx-q">
            <i class="fa-solid fa-link"></i>
            <input type="text" id="lkx-q" placeholder="Incolla un link, scrivi un titolo o un indirizzo…" inputmode="url" autocapitalize="off" spellcheck="false">
            <button type="button" class="lkx-clear" id="lkx-clear" aria-label="Svuota" hidden><i class="fa-solid fa-xmark"></i></button>
          </label>

          <div class="lkx-sug" id="lkx-sug" hidden>
            <span class="lkx-sug-ic" id="lkx-sug-ic"><i class="fa-solid fa-link"></i></span>
            <div class="lkx-sug-b">
              <small id="lkx-sug-t">Link riconosciuto</small>
              <input type="text" id="lkx-sug-label" maxlength="150" placeholder="Nome del pulsante" aria-label="Nome del pulsante">
              <label class="tlx-switch" id="lkx-site-row" hidden><input type="checkbox" id="lkx-site"><i></i><span>È il mio sito web (icona in alto)</span></label>
            </div>
            <button type="submit" class="lkx-go" id="lkx-go"><i class="fa-solid fa-plus"></i> <span>Aggiungi</span></button>
          </div>

          <div class="lkx-text" id="lkx-text" hidden>
            <p>Non è un link. Cosa vuoi creare?</p>
            <div class="lkx-text-b">
              <button type="button" class="lkx-opt" data-kind="divider"><i class="fa-solid fa-grip-lines"></i><span><b>Separatore</b><small>Un titolo che divide la lista, es. «Per prenotare»</small></span></button>
              <button type="button" class="lkx-opt" data-kind="map"><i class="fa-solid fa-location-dot"></i><span><b>Mappa</b><small>Mostra una mappa di questo indirizzo</small></span></button>
            </div>
          </div>

          <div class="lkx-tiles" id="lkx-tiles" aria-label="Aggiunta rapida">
            <button type="button" data-p="instagram.com/"><i class="fa-brands fa-instagram"></i><span>Instagram</span></button>
            <button type="button" data-p="open.spotify.com/"><i class="fa-brands fa-spotify"></i><span>Spotify</span></button>
            <button type="button" data-p="youtube.com/@"><i class="fa-brands fa-youtube"></i><span>YouTube</span></button>
            <button type="button" data-p="tiktok.com/@"><i class="fa-brands fa-tiktok"></i><span>TikTok</span></button>
            <button type="button" data-p="facebook.com/"><i class="fa-brands fa-facebook-f"></i><span>Facebook</span></button>
            <button type="button" data-p="wa.me/39"><i class="fa-brands fa-whatsapp"></i><span>WhatsApp</span></button>
            <button type="button" data-p="https://" data-site="1"><i class="fa-solid fa-globe"></i><span>Sito web</span></button>
            <button type="button" data-kind="map"><i class="fa-solid fa-location-dot"></i><span>Mappa</span></button>
            <button type="button" data-kind="divider"><i class="fa-solid fa-grip-lines"></i><span>Separatore</span></button>
          </div>
        </form>

        <div class="tlx-listhead"><h2>I tuoi link <small id="lkx-count">(<?= count($links) ?>)</small></h2><span class="lkx-hint"><i class="fa-solid fa-grip-vertical"></i> Trascina per riordinare</span></div>

        <?php if (!$links): ?>
          <div class="tlx-empty" id="lkx-empty"><i class="fa-solid fa-link"></i><b>Ancora nessun link</b><span>Incolla un indirizzo qui sopra o tocca una delle icone: ci penso io a riconoscerlo.</span></div>
        <?php endif; ?>

        <div class="lkx-list" id="lkx-list">
          <?php foreach ($links as $l):
            $type = $l['link_type'] ?? 'link';
            $platform = in_array((int) $l['id'], $socialIds, true) ? ($l['platform'] ?? null) : null;
            foreach ($socialLinks as $sl) { if ((int) $sl['id'] === (int) $l['id']) { $platform = $sl['platform']; } }
            $det = $type === 'link' ? detectPlatform($l['url']) : null;
            $icon = $type === 'divider' ? 'fa-solid fa-grip-lines' : ($type === 'map' ? 'fa-solid fa-location-dot' : ($det['icon_class'] ?? (!empty($l['is_website_icon']) ? 'fa-solid fa-globe' : 'fa-solid fa-link')));
            $clicks = (int) $l['click_count'];
            $pct = $maxClicks > 0 ? max(4, (int) round($clicks / $maxClicks * 100)) : 0;
          ?>
          <article class="lkx-item<?= $l['is_active'] ? '' : ' off' ?> lkx-t-<?= e($type) ?>" data-id="<?= (int) $l['id'] ?>" data-type="<?= e($type) ?>"
                   data-label="<?= e($l['label']) ?>" data-url="<?= e($l['url']) ?>" data-active="<?= (int) $l['is_active'] ?>" data-site="<?= (int) !empty($l['is_website_icon']) ?>"
                   data-icon="<?= e($icon) ?>" data-pk="<?= e($det['key'] ?? '') ?>" data-lat="<?= e((string) ($l['map_lat'] ?? '')) ?>" data-lng="<?= e((string) ($l['map_lng'] ?? '')) ?>"
                   data-cover="<?= e((string) ($l['cover_path'] ?? '')) ?>">
            <span class="lkx-grip" aria-hidden="true"><i class="fa-solid fa-grip-vertical"></i></span>
            <span class="lkx-ic<?= $type === 'link' && $l['cover_path'] ? ' has-img' : '' ?>"><?php if ($type === 'link' && $l['cover_path']): ?><img src="/<?= e($l['cover_path']) ?>" alt=""><?php else: ?><i class="<?= e($icon) ?>"></i><?php endif; ?></span>
            <div class="lkx-b">
              <b><?= e($l['label']) ?></b>
              <?php if ($type === 'divider'): ?><small>Separatore · titolo di sezione</small>
              <?php elseif ($type === 'map'): ?><small>Mappa · <?= number_format((float) $l['map_lat'], 4) ?>, <?= number_format((float) $l['map_lng'], 4) ?></small>
              <?php else: ?><small><?= e(preg_replace('~^https?://(www\.)?~i', '', $l['url'])) ?></small><?php endif; ?>
              <?php if ($type === 'link'): ?>
                <span class="lkx-stat"><span class="lkx-bar-c"><i style="--w:<?= $pct ?>%"></i></span><em><?= $clicks ?> click</em></span>
              <?php endif; ?>
            </div>
            <?php if ($platform): ?><span class="lkx-tag" title="Compare come icona in cima alla tua pagina"><i class="fa-solid fa-arrow-up"></i> In alto</span><?php endif; ?>
            <div class="lkx-act">
              <label class="tlx-switch lkx-sw" title="Visibile sulla tua pagina"><input type="checkbox" class="lkx-toggle" <?= $l['is_active'] ? 'checked' : '' ?>><i></i></label>
              <button type="button" class="lkx-btn lkx-edit" title="Modifica" aria-label="Modifica"><i class="fa-solid fa-pen"></i></button>
              <button type="button" class="lkx-btn danger lkx-del" title="Elimina" aria-label="Elimina"><i class="fa-solid fa-trash"></i></button>
            </div>
          </article>
          <?php endforeach; ?>
        </div>

        <div class="lkx-sync">
          <div><b><i class="fa-solid fa-wand-magic-sparkles"></i> Collega le sezioni del sito</b>
            <p>Crea o aggiorna da solo un pulsante per ogni sezione del tuo profilo che ha già contenuti (Blog, Eventi, Video…), sotto un separatore dedicato.</p></div>
          <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="sync_section_links"><button type="submit" class="stc-act"><i class="fa-solid fa-rotate"></i> <span>Genera / aggiorna</span></button></form>
        </div>
      </div>

      <aside class="lkx-side" aria-label="Anteprima della tua pagina">
        <div class="tlx-lbl">Così appare la tua pagina</div>
        <div class="lkx-phone"><div class="lkx-screen" id="lkx-prev"></div></div>
      </aside>
    </div>

    <button type="button" class="lkx-fab" id="lkx-prev-open"><i class="fa-regular fa-eye"></i> Anteprima</button>
  </div>

  <dialog class="cmp lkx-dlg" id="lkx-dlg" aria-labelledby="lkx-dlg-t">
    <form method="post" enctype="multipart/form-data" class="cmp-form" id="lkx-form">
      <?= csrfField() ?>
      <input type="hidden" name="action" id="lkx-d-action" value="update_link">
      <input type="hidden" name="id" id="lkx-d-id">
      <section class="cmp-panel">
        <header class="cmp-head"><h2 id="lkx-dlg-t">Modifica</h2><button type="button" class="cmp-x" data-lkx-close aria-label="Chiudi"><i class="fa-solid fa-xmark"></i></button></header>
        <div class="cmp-body">
          <label for="lkx-d-label">Nome</label>
          <input type="text" name="label" id="lkx-d-label" required maxlength="150">
          <div data-t="link">
            <label for="lkx-d-url">Indirizzo (URL)</label>
            <input type="url" name="url" id="lkx-d-url" placeholder="https://...">
            <label>Immagine quadrata (opzionale)</label>
            <div class="lkx-cover"><img id="lkx-d-cover" alt="" hidden><input type="file" name="cover" accept="image/*"></div>
            <label class="tlx-switch"><input type="checkbox" name="is_website_icon" value="1" id="lkx-d-site"><i></i><span>È il mio sito web (icona in alto)</span></label>
          </div>
          <div data-t="map">
            <label for="lkx-d-addr">Nuovo indirizzo <small>(vuoto = non sposta la mappa)</small></label>
            <input type="text" name="address" id="lkx-d-addr" placeholder="Via Roma 1, Napoli">
            <details class="tlx-adv"><summary>Oppure inserisci le coordinate</summary>
              <div class="lkx-coords"><input type="text" name="map_lat" id="lkx-d-lat" placeholder="Latitudine" inputmode="decimal"><input type="text" name="map_lng" id="lkx-d-lng" placeholder="Longitudine" inputmode="decimal"></div>
            </details>
          </div>
        </div>
        <footer class="cmp-foot"><button type="submit" class="tlx-submit"><i class="fa-solid fa-check"></i> <span>Salva</span></button></footer>
      </section>
    </form>
  </dialog>

  <dialog class="cmp lkx-pdlg" id="lkx-pdlg" aria-label="Anteprima">
    <header class="cmp-head"><h2>Anteprima</h2><button type="button" class="cmp-x" data-lkx-close aria-label="Chiudi"><i class="fa-solid fa-xmark"></i></button></header>
    <div class="cmp-body"><div class="lkx-phone"><div class="lkx-screen" id="lkx-prev2"></div></div></div>
  </dialog>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js" defer></script>
  <script src="/studio/assets/studio-links.js?v=<?= (int) @filemtime(__DIR__ . '/assets/studio-links.js') ?>" defer></script>
<?php include __DIR__ . '/../_dash_footer.php'; ?>
