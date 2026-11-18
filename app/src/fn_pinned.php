<?php
// Elementi fissati in "Primo Piano".
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


// Frammento SQL di visibilità per tipo — vedi commento sopra su PINNABLE_CONTENT_TYPES.
function pinnableVisibilityClause(string $mode): string {
    return match ($mode) {
        'standard' => "AND is_public = 1 AND (publish_at IS NULL OR publish_at <= NOW())",
        'timeline' => "AND visibility = 'public' AND (publish_at IS NULL OR publish_at <= NOW())",
        'blog' => "AND published_at <= NOW()",
        'offerta' => "AND is_active = 1 AND (valid_from IS NULL OR valid_from <= NOW()) AND (valid_until IS NULL OR valid_until >= NOW())",
        'evento' => "AND (publish_at IS NULL OR publish_at <= NOW())",
        default => '',
    };
}


// Colonna che rappresenta "quando" un elemento diventa visibile, per tipo — stessa mappa usata
// da pinnableVisibilityClause() qui sopra, ma qui serve isolata: la pagina "Programmati"
// (dashboard_schedule.php) deve interrogare solo QUELLA colonna con "> NOW()", non l'intera
// clausola di visibilità (che per 'timeline' include anche il filtro public/private — qui invece
// un post privato con data futura resta comunque "programmato" nello stesso senso in cui
// dashboard_post.php mostra già il badge "⏰ Programmato", che infatti non guarda la privacy).
function pinnableScheduleColumn(string $mode): ?string {
    return match ($mode) {
        'standard', 'timeline', 'evento' => 'publish_at',
        'blog' => 'published_at',
        'offerta' => 'valid_from',
        default => null,
    };
}


// Elementi attualmente fissati in "Primo Piano" per un profilo, in ordine di visualizzazione
// (sort_order). $respectVisibility=true filtra via quelli non ancora pubblici/programmati (per il
// carosello sul sito pubblico); false li mostra comunque (per la gestione in dashboard, dove il
// proprietario deve vedere ed eventualmente togliere anche un pin su un elemento non ancora
// pubblicato). Una query per ogni tipo effettivamente presente tra i pin (non una per ognuno dei
// tipi possibili), raggruppando gli ID per tipo — stesso principio di batching già usato altrove
// in questo file per le foto extra dei post Timeline/Viaggi/Album.
function getPinnedItemsForUser(int $userId, bool $respectVisibility = true): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM pinned_items WHERE user_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$userId]);
    $pins = $stmt->fetchAll();
    if (!$pins) {
        return [];
    }

    $stmt = $db->prepare('SELECT slug FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $slug = $stmt->fetchColumn();
    if (!$slug) {
        return [];
    }

    $idsByType = [];
    foreach ($pins as $p) {
        $idsByType[$p['content_type']][] = (int) $p['content_id'];
    }

    $itemsByKey = [];
    foreach ($idsByType as $type => $ids) {
        $cfg = PINNABLE_CONTENT_TYPES[$type] ?? null;
        if (!$cfg) {
            continue;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $visClause = $respectVisibility ? pinnableVisibilityClause($cfg['visibility']) : '';

        if ($type === 'blog') {
            // slug e published_at SENZA alias: blogPostUrl() li legge con questi nomi esatti
            // (leggerli come "post_slug"/"data" produceva un link col solo prefisso di data e
            // niente slug, es. "/slug/blog/1970.01.01." — troncato e non corrispondente a
            // nessun articolo reale).
            $stmt = $db->prepare("SELECT id, {$cfg['title_col']} AS titolo, {$cfg['cover_cols'][0]} AS cover, published_at, slug
                FROM {$cfg['table']} WHERE id IN ($ph) AND user_id = ? {$visClause}");
            $stmt->execute(array_merge($ids, [$userId]));
            foreach ($stmt->fetchAll() as $r) {
                $itemsByKey[$type . ':' . $r['id']] = [
                    'tipo' => $type, 'id' => (int) $r['id'], 'titolo' => $r['titolo'], 'cover' => $r['cover'], 'data' => $r['published_at'],
                    'url' => blogPostUrl($slug, $r),
                ];
            }
            continue;
        }

        $coverExpr = 'COALESCE(' . implode(', ', array_map(fn ($c) => "NULLIF($c,'')", $cfg['cover_cols'])) . ')';
        $stmt = $db->prepare("SELECT id, {$cfg['title_col']} AS titolo, {$coverExpr} AS cover, {$cfg['date_col']} AS data
            FROM {$cfg['table']} WHERE id IN ($ph) AND user_id = ? {$visClause}");
        $stmt->execute(array_merge($ids, [$userId]));
        foreach ($stmt->fetchAll() as $r) {
            $titolo = ($r['titolo'] !== null && $r['titolo'] !== '') ? $r['titolo'] : '📷 Foto';
            $itemsByKey[$type . ':' . $r['id']] = [
                'tipo' => $type, 'id' => (int) $r['id'], 'titolo' => $titolo, 'cover' => $r['cover'], 'data' => $r['data'],
                'url' => sprintf($cfg['url_tpl'], $slug, (int) $r['id']),
            ];
        }
    }

    // Ricompone nell'ordine di sort_order salvato: le query sopra, raggruppate per tipo, non
    // preservano l'ordine originale dei pin.
    $ordered = [];
    foreach ($pins as $p) {
        $key = $p['content_type'] . ':' . $p['content_id'];
        if (isset($itemsByKey[$key])) {
            $ordered[] = $itemsByKey[$key] + ['pin_id' => (int) $p['id']];
        }
    }
    return $ordered;
}


// Cerca tra tutti i tipi di contenuto pinnabili di un profilo (per titolo/nome) — usata dal
// motorino di ricerca di dashboard_featured.php. Nessun filtro di visibilità: il proprietario deve
// poter trovare e fissare anche un contenuto "Solo io" o ancora programmato (il carosello
// pubblico lo mostrerà comunque solo quando diventa visibile, vedi getPinnedItemsForUser()).
function searchPinnableContent(int $userId, string $query, int $limitPerType = 5): array {
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $db = getDB();
    $likeTerm = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';

    $stmt = $db->prepare('SELECT slug FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $slug = $stmt->fetchColumn();
    if (!$slug) {
        return [];
    }

    $stmt = $db->prepare('SELECT content_type, content_id FROM pinned_items WHERE user_id = ?');
    $stmt->execute([$userId]);
    $pinnedKeys = [];
    foreach ($stmt->fetchAll() as $p) {
        $pinnedKeys[$p['content_type'] . ':' . $p['content_id']] = true;
    }

    $results = [];
    foreach (PINNABLE_CONTENT_TYPES as $type => $cfg) {
        if ($type === 'blog') {
            // slug e published_at SENZA alias, stesso motivo di getPinnedItemsForUser() qui sopra.
            $stmt = $db->prepare("SELECT id, {$cfg['title_col']} AS titolo, {$cfg['cover_cols'][0]} AS cover, published_at, slug
                FROM {$cfg['table']} WHERE user_id = ? AND {$cfg['title_col']} LIKE ? ESCAPE '\\\\'
                ORDER BY {$cfg['date_col']} DESC LIMIT {$limitPerType}");
            $stmt->execute([$userId, $likeTerm]);
            foreach ($stmt->fetchAll() as $r) {
                $results[] = [
                    'tipo' => $type, 'id' => (int) $r['id'], 'titolo' => $r['titolo'], 'cover' => $r['cover'],
                    'url' => blogPostUrl($slug, $r), 'label' => $cfg['label'],
                    'pinned' => isset($pinnedKeys[$type . ':' . $r['id']]),
                ];
            }
            continue;
        }
        $coverExpr = 'COALESCE(' . implode(', ', array_map(fn ($c) => "NULLIF($c,'')", $cfg['cover_cols'])) . ')';
        $stmt = $db->prepare("SELECT id, {$cfg['title_col']} AS titolo, {$coverExpr} AS cover
            FROM {$cfg['table']} WHERE user_id = ? AND {$cfg['title_col']} LIKE ? ESCAPE '\\\\'
            ORDER BY {$cfg['date_col']} DESC LIMIT {$limitPerType}");
        $stmt->execute([$userId, $likeTerm]);
        foreach ($stmt->fetchAll() as $r) {
            $titolo = ($r['titolo'] !== null && $r['titolo'] !== '') ? $r['titolo'] : '📷 Foto';
            $results[] = [
                'tipo' => $type, 'id' => (int) $r['id'], 'titolo' => $titolo, 'cover' => $r['cover'],
                'url' => sprintf($cfg['url_tpl'], $slug, (int) $r['id']), 'label' => $cfg['label'],
                'pinned' => isset($pinnedKeys[$type . ':' . $r['id']]),
            ];
        }
    }
    return $results;
}


// Fissa un contenuto in "Primo Piano": in coda all'ordine attuale. INSERT IGNORE perché la
// UNIQUE KEY (user_id, content_type, content_id) impedisce comunque il doppio pin dello stesso
// elemento — un secondo tentativo (es. doppio click) non fa nulla invece di sollevare un errore.
function pinContentItem(int $userId, string $type, int $contentId): void {
    if (!isset(PINNABLE_CONTENT_TYPES[$type])) {
        return;
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM pinned_items WHERE user_id = ?');
    $stmt->execute([$userId]);
    $nextOrder = (int) $stmt->fetchColumn();
    $stmt = $db->prepare('INSERT IGNORE INTO pinned_items (user_id, content_type, content_id, sort_order) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $type, $contentId, $nextOrder]);
}


function unpinContentItem(int $userId, int $pinId): void {
    $stmt = getDB()->prepare('DELETE FROM pinned_items WHERE id = ? AND user_id = ?');
    $stmt->execute([$pinId, $userId]);
}


// Scambia la posizione (sort_order) di un pin con quello immediatamente sopra/sotto — riordino a
// tasti, senza drag & drop: nessun'altra lista di questa dashboard usa il drag, meglio restare
// coerenti con il pattern già presente piuttosto che introdurne uno nuovo per questa sola pagina.
function movePinnedItem(int $userId, int $pinId, string $direction): void {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, sort_order FROM pinned_items WHERE user_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    $index = null;
    foreach ($rows as $i => $r) {
        if ((int) $r['id'] === $pinId) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        return;
    }
    $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
    if ($swapWith < 0 || $swapWith >= count($rows)) {
        return;
    }
    $a = $rows[$index];
    $b = $rows[$swapWith];
    $db->prepare('UPDATE pinned_items SET sort_order = ? WHERE id = ? AND user_id = ?')->execute([$b['sort_order'], $a['id'], $userId]);
    $db->prepare('UPDATE pinned_items SET sort_order = ? WHERE id = ? AND user_id = ?')->execute([$a['sort_order'], $b['id'], $userId]);
}
