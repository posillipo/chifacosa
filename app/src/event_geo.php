<?php
// Posizione degli eventi sulla mappa (pagina pubblica Eventi, tema AdminLTE). Le coordinate si
// calcolano dall'indirizzo (locale + città + provincia) con OpenStreetMap/Nominatim (gratuito, nessuna
// chiave), UNA richiesta per evento e solo quando l'indirizzo cambia — mai a ogni visita della pagina.
// Se la migrazione 2026_11_20_events_geo.sql non è ancora applicata, tutto resta inerte senza errori.
require_once __DIR__ . '/geocoding.php';

function eventGeoQuery(?string $venue, ?string $city, ?string $provincia): string {
    $parts = [];
    foreach ([$venue, $city, $provincia] as $p) {
        $p = trim((string) $p);
        if ($p !== '') {
            $parts[] = $p;
        }
    }
    // Senza almeno la città un indirizzo è troppo vago per essere affidabile.
    if (trim((string) $city) === '' || !$parts) {
        return '';
    }
    return mb_substr(implode(', ', $parts) . ', Italia', 0, 400);
}

// Calcola e salva le coordinate di un evento del profilo $userId. Ritorna 'ok', 'skip' (già calcolate
// per questo indirizzo o indirizzo troppo vago), 'notfound' (indirizzo non trovato) o 'error'.
function eventGeocodeAndSave(int $eventId, int $userId): string {
    try {
        $stmt = getDB()->prepare('SELECT venue, city, provincia, lat, geo_query FROM events WHERE id=? AND user_id=?');
        $stmt->execute([$eventId, $userId]);
        $ev = $stmt->fetch();
        if (!$ev) {
            return 'error';
        }
        $query = eventGeoQuery($ev['venue'], $ev['city'], $ev['provincia']);
        if ($query === '') {
            getDB()->prepare('UPDATE events SET lat=NULL, lng=NULL, geo_query=NULL WHERE id=? AND user_id=?')->execute([$eventId, $userId]);
            return 'skip';
        }
        if (($ev['geo_query'] ?? null) === $query) {
            return 'skip';
        }
        $geo = geocodeAddress($query);
        if ($geo === null) {
            // Si ricorda il tentativo: non si ripete finché l'indirizzo non cambia.
            getDB()->prepare('UPDATE events SET lat=NULL, lng=NULL, geo_query=? WHERE id=? AND user_id=?')->execute([$query, $eventId, $userId]);
            return 'notfound';
        }
        getDB()->prepare('UPDATE events SET lat=?, lng=?, geo_query=? WHERE id=? AND user_id=?')
            ->execute([round($geo['lat'], 6), round($geo['lng'], 6), $query, $eventId, $userId]);
        return 'ok';
    } catch (Throwable $e) {
        error_log('eventGeocodeAndSave: ' . $e->getMessage());
        return 'error';
    }
}

// Eventi del profilo che hanno un indirizzo ma non sono ancora stati calcolati (per il pulsante in dashboard).
function eventsMissingGeo(int $userId, int $limit = 0): array {
    try {
        $sql = "SELECT id FROM events WHERE user_id=? AND geo_query IS NULL AND city IS NOT NULL AND city <> '' ORDER BY id DESC" . ($limit > 0 ? ' LIMIT ' . (int) $limit : '');
        $stmt = getDB()->prepare($sql);
        $stmt->execute([$userId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    } catch (Throwable $e) {
        return [];
    }
}

// Punti per la mappa pubblica: stessi filtri dell'elenco (prossimi o perpetui, già pubblicati, provincia).
function eventMapPoints(int $userId, array $artist, ?string $provincia = null): array {
    try {
        $sql = 'SELECT id, title, venue, city, event_date, lat, lng FROM events WHERE user_id=? AND lat IS NOT NULL AND lng IS NOT NULL
                AND (event_date >= NOW() OR is_perpetual = 1) AND (publish_at IS NULL OR publish_at <= NOW())';
        $params = [$userId];
        if ($provincia !== null && $provincia !== '') {
            $sql .= ' AND provincia = ?';
            $params[] = $provincia;
        }
        $stmt = getDB()->prepare($sql . ' ORDER BY is_perpetual DESC, event_date ASC LIMIT 500');
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'where' => trim((string) $r['venue'] . ((string) $r['venue'] !== '' && (string) $r['city'] !== '' ? ', ' : '') . (string) $r['city']),
                'date' => formatLocalDateTime($r['event_date'], $artist),
                'lat' => (float) $r['lat'],
                'lng' => (float) $r['lng'],
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
