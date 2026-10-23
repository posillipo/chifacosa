<?php
// Modulo Contest (MyBand Festival): candidature di un brano per artista, controllo Spotify,
// approvazione del direttore, email di conferma. Voti, gruppi e sponsor arriveranno nelle fasi
// successive.

function contestParseTrackId(string $input): ?string {
    $input = trim($input);
    if (preg_match('#open\.spotify\.com/(?:intl-[a-z]+/)?track/([A-Za-z0-9]{10,40})#', $input, $m)) {
        return $m[1];
    }
    return preg_match('/^[A-Za-z0-9]{10,40}$/', $input) ? $input : null;
}

function contestGet(int $id): ?array {
    $stmt = getDB()->prepare('SELECT * FROM contests WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// Il contest mostrato al pubblico: l'ultimo non in bozza.
function contestPublicCurrent(): ?array {
    return getDB()->query("SELECT * FROM contests WHERE status <> 'draft' ORDER BY id DESC LIMIT 1")->fetch() ?: null;
}

function contestCandidaturesOpen(array $c): bool {
    if ($c['status'] !== 'open') {
        return false;
    }
    $now = time();
    if (!empty($c['candidatures_open_at']) && strtotime($c['candidatures_open_at']) > $now) {
        return false;
    }
    if (!empty($c['candidatures_close_at']) && strtotime($c['candidatures_close_at']) < $now) {
        return false;
    }
    return true;
}

function contestSendMail(string $toEmail, string $toName, string $subject, string $body): bool {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return false;
    }
    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);
    return $mailer->send($cfg['from'], $cfg['fromName'], $toEmail, $toName, $subject, $body);
}

// Data di riferimento per il requisito "pubblicato da non più di un anno": l'inizio del festival
// (inizio votazioni), in mancanza la chiusura delle candidature, in mancanza oggi.
function contestReferenceDate(array $c): string {
    return substr((string) ($c['voting_start_at'] ?: ($c['candidatures_close_at'] ?: date('Y-m-d'))), 0, 10);
}

// Candida un brano. $userId null = candidatura inserita dal direttore per un artista senza profilo.
// Ritorna ['ok' => true, 'entry_id' => n] oppure ['ok' => false, 'error' => '...'].
function contestAddEntry(array $contest, ?int $userId, string $bandName, string $email, string $trackInput, ?string $profileSpotifyArtistId = null, bool $byAdmin = false): array {
    if (!$byAdmin && !contestCandidaturesOpen($contest)) {
        return ['ok' => false, 'error' => 'Le candidature non sono aperte.'];
    }
    $bandName = trim($bandName);
    if ($bandName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Nome band ed email valida sono obbligatori.'];
    }
    $trackId = contestParseTrackId($trackInput);
    if (!$trackId) {
        return ['ok' => false, 'error' => 'Indirizzo del brano Spotify non valido.'];
    }
    $track = spotifyGetTrack($trackId);
    if (!$track) {
        return ['ok' => false, 'error' => 'Brano non trovato su Spotify (o servizio momentaneamente non raggiungibile).'];
    }
    if (!$track['release_date']) {
        return ['ok' => false, 'error' => 'Spotify non fornisce la data di pubblicazione di questo brano.'];
    }
    $ref = contestReferenceDate($contest);
    $limit = date('Y-m-d', strtotime($ref . ' -' . (int) $contest['max_track_age_months'] . ' months'));
    if ($track['release_date'] < $limit) {
        return ['ok' => false, 'error' => 'Il brano è stato pubblicato il ' . date('d/m/Y', strtotime($track['release_date'])) . ': deve essere uscito dopo il ' . date('d/m/Y', strtotime($limit)) . ' (massimo ' . (int) $contest['max_track_age_months'] . ' mesi prima dell\'inizio del festival).'];
    }

    $db = getDB();
    if ($userId) {
        $dup = $db->prepare('SELECT id FROM contest_entries WHERE contest_id = ? AND user_id = ?');
        $dup->execute([(int) $contest['id'], $userId]);
        if ($dup->fetch()) {
            return ['ok' => false, 'error' => 'Hai già candidato un brano: è ammesso un solo brano per artista.'];
        }
    }
    $dup = $db->prepare('SELECT id FROM contest_entries WHERE contest_id = ? AND spotify_track_id = ?');
    $dup->execute([(int) $contest['id'], $trackId]);
    if ($dup->fetch()) {
        return ['ok' => false, 'error' => 'Questo brano è già stato candidato.'];
    }
    // Un solo brano per artista anche tra candidature senza profilo: stesso artista Spotify.
    $mainArtist = $track['artist_ids'][0] ?? null;
    if ($mainArtist) {
        $dup = $db->prepare('SELECT id FROM contest_entries WHERE contest_id = ? AND spotify_artist_id = ?');
        $dup->execute([(int) $contest['id'], $mainArtist]);
        if ($dup->fetch()) {
            return ['ok' => false, 'error' => 'Per questo artista è già stato candidato un brano.'];
        }
    }

    // Titolarità: il profilo ha collegato lo stesso artista Spotify del brano? (verifica indicativa,
    // la decisione resta al direttore.)
    $match = $profileSpotifyArtistId && in_array($profileSpotifyArtistId, $track['artist_ids'], true);
    $artist = $mainArtist ? spotifyGetArtist($mainArtist) : null;
    $popularity = isset($artist['popularity']) ? (int) $artist['popularity'] : null;

    try {
        $db->prepare('INSERT INTO contest_entries (contest_id, user_id, band_name, contact_email, spotify_track_id, track_name, spotify_artist_id, track_url, image_url, release_date, artist_popularity, artist_match)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([(int) $contest['id'], $userId, mb_substr($bandName, 0, 150), mb_substr($email, 0, 190), $trackId, mb_substr($track['name'], 0, 200),
                $mainArtist, $track['spotify_url'], $track['image'], $track['release_date'], $popularity, $match ? 1 : 0]);
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'Candidatura già presente.'];
    }
    $entryId = (int) $db->lastInsertId();

    contestSendMail($email, $bandName, 'Hai proposto il brano per il ' . $contest['name'],
        "Ciao {$bandName},\n\nhai proposto il brano \"{$track['name']}\" per {$contest['name']}.\n\n"
        . "La candidatura è in attesa di approvazione: quando sarà approvata riceverai una conferma e il brano entrerà nella lista ufficiale dei candidati.\n\n"
        . "A presto,\n" . siteName());
    return ['ok' => true, 'entry_id' => $entryId];
}

// Approva o rifiuta una candidatura (solo admin). Alla prima approvazione invia la conferma.
function contestSetEntryStatus(int $entryId, string $status, ?string $note = null): void {
    if (!in_array($status, ['approved', 'rejected', 'pending'], true)) {
        return;
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT e.*, c.name AS contest_name FROM contest_entries e JOIN contests c ON c.id = e.contest_id WHERE e.id = ?');
    $stmt->execute([$entryId]);
    $e = $stmt->fetch();
    if (!$e) {
        return;
    }
    $wasApproved = $e['status'] === 'approved';
    $db->prepare('UPDATE contest_entries SET status = ?, admin_note = ?, approved_at = ? WHERE id = ?')
        ->execute([$status, $note !== null && $note !== '' ? mb_substr($note, 0, 300) : $e['admin_note'], $status === 'approved' ? ($e['approved_at'] ?: date('Y-m-d H:i:s')) : null, $entryId]);
    if ($status === 'approved' && !$wasApproved) {
        contestSendMail($e['contact_email'], $e['band_name'], 'Il tuo brano è in gara: ' . $e['contest_name'],
            "Ciao {$e['band_name']},\n\nla candidatura del brano \"{$e['track_name']}\" è stata approvata: il brano è ufficialmente in gara per {$e['contest_name']}.\n\n"
            . "Trovi la lista dei candidati qui: " . siteUrl('/contest') . "\n\nIn bocca al lupo!\n" . siteName());
    }
}
