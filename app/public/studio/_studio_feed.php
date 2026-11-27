<?php
// Schede del Feed per Studio: stato in evidenza, tipo, autore, data e azioni. Sostituiscono renderDashboardTimelineItem/Card
// (make-studio.py cambia le chiamate nelle pagine copiate). Il Feed originale non cambia.

function studioFeedType(string $tipo): array {
    return match ($tipo) {
        'blog'       => ['Articolo', 'violet'],
        'video'      => ['Video', 'blue'],
        'pensiero'   => ['Aggiornamento', 'grey'],
        'evento'     => ['Evento', 'grey'],
        'album_foto' => ['Album', 'grey'],
        'servizio'   => ['Servizio', 'grey'],
        'brano'      => ['Brano', 'grey'],
        'offerta'    => ['Offerta', 'grey'],
        default      => [(str_ends_with($tipo, '_favorita') || str_ends_with($tipo, '_favorito')) ? 'Che amo' : 'Contenuto', 'grey'],
    };
}

// Stato: Errore (coda social in errore) / Programmato / Solo io / Pubblicato.
function studioFeedStatus(array $item): array {
    $q = $item['x_queue'] ?? null;
    if ($q && ($q['status'] ?? '') === 'failed') {
        return ['Errore', 'danger', 'fa-circle-exclamation'];
    }
    if (!empty($item['data']) && strtotime($item['data']) > time()) {
        return ['Programmato · ' . formatLocalDateTime($item['data'], ['dashboard_theme' => $item['owner_tz'] ?? null]), 'warn', 'fa-clock'];
    }
    if (isset($item['x_pub']) && (int) $item['x_pub'] === 0) {
        return ['Solo io', 'grey', 'fa-lock'];
    }
    return ['Pubblicato', 'ok', 'fa-circle-check'];
}

function studioRenderFeedCard(array $item, ?string $viewer, bool $metricool, string $mode): string {
    [$tlabel, $tone] = studioFeedType((string) $item['tipo']);
    [$slabel, $stone, $sicon] = studioFeedStatus($item);
    $mine = $viewer !== null && $item['user_slug'] === $viewer;
    $cover = $item['cover_thumb'] ?? $item['cover'] ?? null;
    $coverSrc = $cover ? (str_starts_with($cover, 'http') ? $cover : '/' . ltrim($cover, '/')) : null;
    $href = $mine ? ($item['edit_url'] ?? $item['url']) : $item['url'];
    $fp = $mine ? '' : dashboardFeedPreviewAttr($item, $tlabel);
    $title = (string) $item['titolo'];
    $when = !empty($item['data']) ? italianLongDateTime($item['data'], ['dashboard_theme' => $item['owner_tz'] ?? null]) : '';
    $thumb = $coverSrc ? '<img src="' . e($coverSrc) . '" alt="" loading="lazy">' : '<span class="stc-ph">' . e(mb_strtoupper(mb_substr($tlabel, 0, 1))) . '</span>';
    $h = '<article class="stc stc-' . e($mode) . ($mine ? ' stc-mine' : '') . '">';
    // --tb: la stessa immagine sfocata fa da sfondo, così l'immagine intera (mai tagliata) non lascia bande vuote.
    $h .= '<a class="stc-thumb" href="' . e($href) . '"' . ($coverSrc ? ' style="--tb:url(&quot;' . e($coverSrc) . '&quot;)"' : '') . $fp . '>' . $thumb . '</a>';
    $h .= '<div class="stc-body"><div class="stc-meta1"><span class="stc-type stc-' . $tone . '">' . e($tlabel) . '</span>'
        . '<span class="stc-status stc-s-' . $stone . '"><i class="fa-solid ' . $sicon . '"></i> ' . e($slabel) . '</span>';
    if ($mine && isset($item['in_feed']) && (int) $item['in_feed'] === 0) {
        $h .= '<span class="stc-status stc-s-grey">Fuori dal Feed</span>';
    }
    $h .= '</div><h4 class="stc-title"><a href="' . e($href) . '"' . $fp . '>' . e($title) . '</a></h4>';
    $h .= '<div class="stc-meta2">' . e($item['display_name']) . ($mine ? ' <b>(tu)</b>' : '') . ($when !== '' ? ' · ' . e($when) : '') . '</div>';
    $h .= '<div class="stc-actions">';
    if ($mine) {
        $h .= '<a class="stc-act primary" href="' . e((string) ($item['edit_url'] ?? $item['url'])) . '"><i class="fa-solid fa-pen"></i><span>Modifica</span></a>';
    } else {
        $h .= '<a class="stc-act primary" href="' . e((string) $item['url']) . '"' . $fp . '><i class="fa-solid fa-eye"></i><span>Anteprima</span></a>';
    }
    $h .= '<a class="stc-act" href="' . e((string) $item['url']) . '" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i><span>Pagina pubblica</span></a>';
    $share = e(json_encode(['t' => $title, 'u' => siteUrl((string) $item['url'])], JSON_UNESCAPED_UNICODE));
    $h .= '<button type="button" class="stc-act" data-share="' . $share . '"><i class="fa-solid fa-share-nodes"></i><span>Condividi</span></button>';
    if (!empty($item['redirect_link'])) {
        $h .= '<a class="stc-act" href="' . e($item['redirect_link']) . '" target="_blank" rel="noopener" title="Verifica il link personalizzato"><i class="fa-solid fa-link"></i><span>Link</span></a>';
    }
    if ($mine && $metricool) {
        $map = dashboardFeedSqTypeMap();
        $sq = $map[$item['tipo']] ?? null;
        if ($sq) {
            $imgUrl = $cover ? (str_starts_with($cover, 'http') ? $cover : siteUrl('/' . ltrim($cover, '/'))) : null;
            $payload = json_encode(['suggest' => true, 'type' => $sq, 'id' => (int) $item['item_id'], 'title' => $title, 'img' => $imgUrl, 'url' => siteUrl((string) $item['url'])]);
            $h .= '<button type="button" class="stc-act" onclick=\'window.sqSuggest(' . htmlspecialchars($payload, ENT_QUOTES) . ')\' title="Condividi sui social"><i class="fa-solid fa-paper-plane"></i><span>Coda social</span></button>';
        }
    }
    // Ogni pulsante d'azione riceve come suggerimento il suo nome (nella lista compatta restano solo le icone).
    $h = preg_replace_callback('~<(a|button) class="stc-act([^"]*)"([^>]*)>(<i [^>]*></i>)<span>([^<]*)</span>~u', function ($m) {
        return strpos($m[3], ' title=') !== false ? $m[0] : '<' . $m[1] . ' class="stc-act' . $m[2] . '"' . $m[3] . ' title="' . $m[5] . '" aria-label="' . $m[5] . '">' . $m[4] . '<span>' . $m[5] . '</span>';
    }, $h);
    return $h . '</div></div></article>';
}

function studioRenderTimelineItem(array $item, ?string $viewerSlug = null, bool $metricoolActive = false): string {
    return studioRenderFeedCard($item, $viewerSlug, $metricoolActive, 'list');
}

function studioRenderTimelineCard(array $item, ?string $viewerSlug = null, bool $metricoolActive = false): string {
    return studioRenderFeedCard($item, $viewerSlug, $metricoolActive, 'card');
}
