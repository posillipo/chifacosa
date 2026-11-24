<?php
// Posizione del profilo: città + provincia obbligatorie, coordinate del COMUNE per la Directory.
//  - provinceList()/provinceNormalize(): le province italiane per sigla (NA = Napoli), senza testo libero.
//  - enforceProfileLocationGate(): chi non ha città e provincia non entra nella dashboard (vedi fondo).
//  - profileGeocodeAndSave(): coordinate del comune (NON dell'indirizzo) con OpenStreetMap/Nominatim.
// Le coordinate stanno in profiles.geo_lat / geo_lng / geo_query (migrazione 2026_11_37); senza migrazione
// tutto resta inerte, senza errori.
require_once __DIR__ . '/geocoding.php';

/** Province italiane: sigla => nome. */
function provinceList(): array {
    return [
        'AG' => 'Agrigento', 'AL' => 'Alessandria', 'AN' => 'Ancona', 'AO' => 'Aosta', 'AP' => 'Ascoli Piceno', 'AQ' => "L'Aquila",
        'AR' => 'Arezzo', 'AT' => 'Asti', 'AV' => 'Avellino', 'BA' => 'Bari', 'BG' => 'Bergamo', 'BI' => 'Biella', 'BL' => 'Belluno',
        'BN' => 'Benevento', 'BO' => 'Bologna', 'BR' => 'Brindisi', 'BS' => 'Brescia', 'BT' => 'Barletta-Andria-Trani', 'BZ' => 'Bolzano',
        'CA' => 'Cagliari', 'CB' => 'Campobasso', 'CE' => 'Caserta', 'CH' => 'Chieti', 'CL' => 'Caltanissetta', 'CN' => 'Cuneo',
        'CO' => 'Como', 'CR' => 'Cremona', 'CS' => 'Cosenza', 'CT' => 'Catania', 'CZ' => 'Catanzaro', 'EN' => 'Enna',
        'FC' => 'Forlì-Cesena', 'FE' => 'Ferrara', 'FG' => 'Foggia', 'FI' => 'Firenze', 'FM' => 'Fermo', 'FR' => 'Frosinone',
        'GE' => 'Genova', 'GO' => 'Gorizia', 'GR' => 'Grosseto', 'IM' => 'Imperia', 'IS' => 'Isernia', 'KR' => 'Crotone',
        'LC' => 'Lecco', 'LE' => 'Lecce', 'LI' => 'Livorno', 'LO' => 'Lodi', 'LT' => 'Latina', 'LU' => 'Lucca',
        'MB' => 'Monza e Brianza', 'MC' => 'Macerata', 'ME' => 'Messina', 'MI' => 'Milano', 'MN' => 'Mantova', 'MO' => 'Modena',
        'MS' => 'Massa-Carrara', 'MT' => 'Matera', 'NA' => 'Napoli', 'NO' => 'Novara', 'NU' => 'Nuoro', 'OR' => 'Oristano',
        'PA' => 'Palermo', 'PC' => 'Piacenza', 'PD' => 'Padova', 'PE' => 'Pescara', 'PG' => 'Perugia', 'PI' => 'Pisa',
        'PN' => 'Pordenone', 'PO' => 'Prato', 'PR' => 'Parma', 'PT' => 'Pistoia', 'PU' => 'Pesaro e Urbino', 'PV' => 'Pavia',
        'PZ' => 'Potenza', 'RA' => 'Ravenna', 'RC' => 'Reggio Calabria', 'RE' => 'Reggio Emilia', 'RG' => 'Ragusa', 'RI' => 'Rieti',
        'RM' => 'Roma', 'RN' => 'Rimini', 'RO' => 'Rovigo', 'SA' => 'Salerno', 'SI' => 'Siena', 'SO' => 'Sondrio', 'SP' => 'La Spezia',
        'SR' => 'Siracusa', 'SS' => 'Sassari', 'SU' => 'Sud Sardegna', 'SV' => 'Savona', 'TA' => 'Taranto', 'TE' => 'Teramo',
        'TN' => 'Trento', 'TO' => 'Torino', 'TP' => 'Trapani', 'TR' => 'Terni', 'TS' => 'Trieste', 'TV' => 'Treviso', 'UD' => 'Udine',
        'VA' => 'Varese', 'VB' => 'Verbano-Cusio-Ossola', 'VC' => 'Vercelli', 'VE' => 'Venezia', 'VI' => 'Vicenza', 'VR' => 'Verona',
        'VT' => 'Viterbo', 'VV' => 'Vibo Valentia',
    ];
}

/** Sigla (o nome) di una provincia -> sigla maiuscola valida, oppure null. "na", "Na", "Napoli" -> "NA". */
function provinceNormalize(?string $value): ?string {
    $v = trim((string) $value);
    if ($v === '') {
        return null;
    }
    $list = provinceList();
    $up = mb_strtoupper($v);
    if (isset($list[$up])) {
        return $up;
    }
    $needle = mb_strtolower(str_replace(["'", '’'], "'", $v));
    foreach ($list as $sigla => $name) {
        if (mb_strtolower($name) === $needle) {
            return $sigla;
        }
    }
    return null;
}

/** Città e provincia compilate (provincia valida)? */
function locationComplete(?string $city, ?string $province): bool {
    return mb_strlen(trim((string) $city)) >= 2 && provinceNormalize($province) !== null;
}

/** True se la tabella profiles ha le colonne delle coordinate (migrazione 2026_11_37). */
function profilesHaveGeoColumns(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool) getDB()->query("SHOW COLUMNS FROM profiles LIKE 'geo_query'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** Testo cercato su Nominatim: SOLO comune + provincia (mai indirizzo), così il punto è il centro del comune. */
function profileGeoQuery(string $city, string $province): string {
    $sigla = provinceNormalize($province);
    if ($sigla === null || trim($city) === '') {
        return '';
    }
    return mb_substr(trim($city), 0, 120) . ', ' . provinceList()[$sigla] . ', ' . $sigla;
}

/**
 * Cerca il comune su Nominatim e sceglie il risultato giusto: solo Italia (countrycodes=it) e, quando
 * Nominatim indica la provincia (ISO3166-2-lvl6 = IT-NA), solo quello della provincia scelta.
 * Prima prova la ricerca strutturata (city + county), poi quella a testo libero.
 * Ritorna ['status' => 'ok', 'lat', 'lng'] | ['status' => 'notfound'] | ['status' => 'error'] (servizio non raggiungibile).
 */
function geocodeComuneProvincia(string $city, string $province): array {
    $sigla = provinceNormalize($province);
    $city = trim($city);
    if ($sigla === null || $city === '') {
        return ['status' => 'notfound'];
    }
    $provName = provinceList()[$sigla];
    $base = 'https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=5&countrycodes=it&accept-language=it';
    $urls = [
        $base . '&city=' . urlencode($city) . '&county=' . urlencode($provName),
        $base . '&q=' . urlencode($city . ', ' . $provName . ', Italia'),
    ];
    $anyResponse = false;
    foreach ($urls as $i => $url) {
        if ($i > 0) {
            usleep(1100000); // Nominatim: al massimo una richiesta al secondo
        }
        // Attesa massima 5 secondi: se il servizio non risponde il salvataggio del profilo non resta bloccato.
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true, 'header' => 'User-Agent: ChiFaCosaApp/1.0 (' . siteUrl('/') . ')']]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            $resp = null;
        }
        if ($resp === null) {
            continue;
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            continue;
        }
        $anyResponse = true;
        $fallback = null;
        foreach ($data as $r) {
            if (empty($r['lat']) || empty($r['lon'])) {
                continue;
            }
            $iso = (string) ($r['address']['ISO3166-2-lvl6'] ?? '');
            if ($iso === '') {
                $fallback = $fallback ?? $r; // provincia non indicata: accettabile solo se non c'è di meglio
                continue;
            }
            if (strtoupper($iso) === 'IT-' . $sigla) {
                return ['status' => 'ok', 'lat' => round((float) $r['lat'], 6), 'lng' => round((float) $r['lon'], 6)];
            }
        }
        if ($fallback) {
            return ['status' => 'ok', 'lat' => round((float) $fallback['lat'], 6), 'lng' => round((float) $fallback['lon'], 6)];
        }
    }
    return ['status' => $anyResponse ? 'notfound' : 'error'];
}

/**
 * Calcola e salva le coordinate del comune di un profilo, solo se città/provincia sono cambiate dall'ultima volta.
 * Ritorna 'ok', 'skip' (già calcolate o dati incompleti), 'notfound' o 'error'. Non solleva mai eccezioni.
 */
function profileGeocodeAndSave(int $userId): string {
    if (!profilesHaveGeoColumns()) {
        return 'skip';
    }
    try {
        $st = getDB()->prepare('SELECT citta, provincia, geo_query FROM profiles WHERE user_id = ?');
        $st->execute([$userId]);
        $p = $st->fetch();
        if (!$p) {
            return 'error';
        }
        $q = profileGeoQuery((string) $p['citta'], (string) $p['provincia']);
        if ($q === '') {
            getDB()->prepare('UPDATE profiles SET geo_lat = NULL, geo_lng = NULL, geo_query = NULL WHERE user_id = ?')->execute([$userId]);
            return 'skip';
        }
        if (($p['geo_query'] ?? null) === $q) {
            return 'skip';
        }
        $g = geocodeComuneProvincia((string) $p['citta'], (string) $p['provincia']);
        if ($g['status'] === 'ok') {
            getDB()->prepare('UPDATE profiles SET geo_lat = ?, geo_lng = ?, geo_query = ? WHERE user_id = ?')->execute([$g['lat'], $g['lng'], $q, $userId]);
            return 'ok';
        }
        if ($g['status'] === 'notfound') {
            // Si ricorda il tentativo: non si ripete finché città/provincia non cambiano.
            getDB()->prepare('UPDATE profiles SET geo_lat = NULL, geo_lng = NULL, geo_query = ? WHERE user_id = ?')->execute([$q, $userId]);
            return 'notfound';
        }
        return 'error'; // servizio non raggiungibile: nessun tentativo registrato, si riproverà
    } catch (Throwable $e) {
        error_log('profileGeocodeAndSave: ' . $e->getMessage());
        return 'error';
    }
}

/**
 * Chi non ha città e provincia non entra nella dashboard: viene mandato alla pagina Profilo (che resta
 * raggiungibile, insieme a "Elimina account"). Vale per la dashboard classica e per Studio. Si apre
 * sempre in caso di errore (mai bloccare tutti per un problema tecnico).
 */
function enforceProfileLocationGate(): void {
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if (PHP_SAPI === 'cli' || empty($_SESSION['user_id'])) {
        return;
    }
    $base = basename($script);
    if (!preg_match('~^dashboard(_[a-z0-9_]+)?\.php$~', $base) || in_array($base, ['dashboard_profile.php', 'dashboard_delete_account.php'], true)) {
        return;
    }
    try {
        $st = getDB()->prepare('SELECT citta, provincia FROM profiles WHERE user_id = ?');
        $st->execute([(int) $_SESSION['user_id']]);
        $p = $st->fetch();
    } catch (Throwable $e) {
        return;
    }
    if (!$p || locationComplete($p['citta'] ?? '', $p['provincia'] ?? '')) {
        return;
    }
    $dir = strpos($script, '/studio/') !== false ? '/studio' : '';
    header('Location: ' . $dir . '/dashboard_profile.php?need_location=1', true, ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 302 : 303);
    exit;
}

// ===== Directory: band e etichette con profilo visibile, per provincia e città =====

/** Condizione comune: profili band/etichetta attivi, visibili (non privati), con città e provincia. */
function directoryBaseWhere(): string {
    return "u.is_active = 1 AND u.account_type_chosen = 1 AND u.account_type IN ('band','label')"
        . " AND p.citta IS NOT NULL AND p.citta <> '' AND p.provincia IS NOT NULL AND p.provincia <> ''"
        . (function_exists('privateProfileExcludeSql') ? privateProfileExcludeSql('p') : '');
}

/** Profili della Directory, filtrati per provincia (sigla) e/o città (testo contenuto). Max 600. */
function directoryProfiles(?string $provSigla, string $city = ''): array {
    $geo = profilesHaveGeoColumns() ? ', p.geo_lat, p.geo_lng, p.geo_query' : '';
    $sql = "SELECT u.id, u.slug, u.account_type, p.display_name, p.avatar_path, p.genere, p.citta, p.provincia{$geo}
            FROM users u JOIN profiles p ON p.user_id = u.id WHERE " . directoryBaseWhere();
    $params = [];
    if ($provSigla) {
        // I profili più vecchi hanno la provincia scritta come capita («na», «Napoli»): si cerca sia la sigla sia il nome.
        $sql .= ' AND LOWER(p.provincia) IN (?, ?)';
        $params[] = mb_strtolower($provSigla);
        $params[] = mb_strtolower(provinceList()[$provSigla] ?? $provSigla);
    }
    $city = trim($city);
    if ($city !== '') {
        $sql .= ' AND p.citta LIKE ?';
        $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $city) . '%';
    }
    $sql .= ' ORDER BY p.provincia, p.citta, p.display_name LIMIT 600';
    try {
        $st = getDB()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        error_log('directoryProfiles: ' . $e->getMessage());
        return [];
    }
}

/** Indice: [sigla => ['name' => 'Napoli', 'n' => totale, 'cities' => [città => n]]], ordinato per provincia e città. */
function directoryIndex(): array {
    $out = [];
    try {
        $rows = getDB()->query("SELECT p.provincia, p.citta, COUNT(*) n FROM users u JOIN profiles p ON p.user_id = u.id WHERE "
            . directoryBaseWhere() . ' GROUP BY p.provincia, p.citta ORDER BY p.provincia, p.citta')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $names = provinceList();
    foreach ($rows as $r) {
        $sg = provinceNormalize($r['provincia']);
        if ($sg === null) {
            continue;
        }
        $out[$sg]['name'] = $names[$sg];
        $out[$sg]['n'] = ($out[$sg]['n'] ?? 0) + (int) $r['n'];
        $out[$sg]['cities'][$r['citta']] = ($out[$sg]['cities'][$r['citta']] ?? 0) + (int) $r['n'];
    }
    uasort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/**
 * Calcola le coordinate dei profili mostrati che non le hanno ancora (profili con città/provincia salvati
 * prima di questa funzione). Al massimo $max per richiesta, ognuno una sola volta: poi restano in memoria.
 * Ritorna le righe aggiornate.
 */
function directoryEnsureGeo(array $rows, int $max = 2): array {
    if (!profilesHaveGeoColumns()) {
        return $rows;
    }
    $done = 0;
    foreach ($rows as &$r) {
        if ($done >= $max) {
            break;
        }
        if ($r['geo_lat'] === null && ($r['geo_query'] ?? null) === null) {
            if ($done > 0) {
                usleep(1100000);
            }
            $done++;
            profileGeocodeAndSave((int) $r['id']);
            $st = getDB()->prepare('SELECT geo_lat, geo_lng, geo_query FROM profiles WHERE user_id = ?');
            $st->execute([(int) $r['id']]);
            if ($n = $st->fetch()) {
                $r['geo_lat'] = $n['geo_lat'];
                $r['geo_lng'] = $n['geo_lng'];
                $r['geo_query'] = $n['geo_query'];
            }
        }
    }
    unset($r);
    return $rows;
}

/** Punti per la mappa: un punto per comune con l'elenco dei profili di quel comune. */
function directoryPoints(array $rows): array {
    $pts = [];
    foreach ($rows as $r) {
        if (!isset($r['geo_lat']) || $r['geo_lat'] === null || $r['geo_lng'] === null) {
            continue;
        }
        $k = round((float) $r['geo_lat'], 4) . ',' . round((float) $r['geo_lng'], 4);
        if (!isset($pts[$k])) {
            $pts[$k] = ['lat' => (float) $r['geo_lat'], 'lng' => (float) $r['geo_lng'], 'city' => $r['citta'], 'prov' => strtoupper((string) $r['provincia']), 'bands' => []];
        }
        $pts[$k]['bands'][] = ['name' => $r['display_name'] ?: $r['slug'], 'slug' => $r['slug']];
    }
    return array_values($pts);
}
