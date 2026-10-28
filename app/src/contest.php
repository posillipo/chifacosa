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
    $stmt = $db->prepare('SELECT e.*, c.name AS contest_name, c.slug AS contest_slug FROM contest_entries e JOIN contests c ON c.id = e.contest_id WHERE e.id = ?');
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
            . "Trovi la lista dei candidati qui: " . siteUrl('/contest/' . $e['contest_slug']) . "\n\nIn bocca al lupo!\n" . siteName());
    }
}

// ---------------------------------------------------------------------------------------------
// Fase 2: gruppi, voto Gmail con conferma, classifiche
// ---------------------------------------------------------------------------------------------

const CONTEST_VOTE_TTL_HOURS = 48;
const CONTEST_GROUP_LABELS = ['big' => 'Big', 'new' => 'Nuove Proposte', 'single' => 'Classifica'];

// Solo Gmail/Googlemail. Normalizza: minuscole, niente punti, niente parte dopo "+", googlemail -> gmail.
// Ritorna l'indirizzo normalizzato o null se non è un Gmail valido.
function contestNormalizeGmail(string $email): ?string {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    [$local, $domain] = explode('@', $email, 2);
    if (!in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
        return null;
    }
    $local = str_replace('.', '', explode('+', $local, 2)[0]);
    return $local !== '' ? $local . '@gmail.com' : null;
}

function contestVotingOpen(array $c): bool {
    if ($c['status'] !== 'voting' || empty($c['groups_locked_at'])) {
        return false;
    }
    $now = time();
    if (!empty($c['voting_start_at']) && strtotime($c['voting_start_at']) > $now) {
        return false;
    }
    if (!empty($c['voting_end_at']) && strtotime($c['voting_end_at']) < $now) {
        return false;
    }
    return true;
}

// Fotografa la popolarità Spotify dell'artista di ogni brano approvato (se Spotify non risponde
// resta il valore letto alla candidatura). Non tocca i gruppi.
function contestSnapshotPopularity(int $contestId): void {
    $db = getDB();
    $rows = $db->prepare("SELECT id, spotify_artist_id, artist_popularity FROM contest_entries WHERE contest_id = ? AND status = 'approved'");
    $rows->execute([$contestId]);
    $upd = $db->prepare('UPDATE contest_entries SET popularity_snapshot = ? WHERE id = ?');
    foreach ($rows->fetchAll() as $r) {
        $pop = $r['artist_popularity'] !== null ? (int) $r['artist_popularity'] : null;
        if ($r['spotify_artist_id'] && ($a = spotifyGetArtist($r['spotify_artist_id'])) && isset($a['popularity'])) {
            $pop = (int) $a['popularity'];
        }
        $upd->execute([$pop, (int) $r['id']]);
    }
}

// Regola dei gruppi sui valori fotografati: due gruppi se almeno big_min_count artisti sono >= soglia
// e almeno new_min_count restano sotto; altrimenti gruppo unico. Ritorna ['mode', 'big', 'new', 'entries'].
function contestProposeGroups(array $c): array {
    $stmt = getDB()->prepare("SELECT id, band_name, track_name, popularity_snapshot FROM contest_entries WHERE contest_id = ? AND status = 'approved' ORDER BY popularity_snapshot DESC, id");
    $stmt->execute([(int) $c['id']]);
    $entries = $stmt->fetchAll();
    $big = 0;
    foreach ($entries as $e) {
        if ($e['popularity_snapshot'] !== null && (int) $e['popularity_snapshot'] >= (int) $c['big_threshold']) {
            $big++;
        }
    }
    $new = count($entries) - $big;
    $two = $big >= (int) $c['big_min_count'] && $new >= (int) $c['new_min_count'];
    return ['mode' => $two ? 'two' : 'single', 'big' => $big, 'new' => $new, 'entries' => $entries];
}

// Blocca i gruppi: assegna group_key a ogni brano approvato e segna il contest. Non si ricalcola più.
function contestLockGroups(int $contestId): bool {
    $c = contestGet($contestId);
    if (!$c || !empty($c['groups_locked_at'])) {
        return false;
    }
    $p = contestProposeGroups($c);
    $db = getDB();
    $upd = $db->prepare('UPDATE contest_entries SET group_key = ? WHERE id = ?');
    $db->beginTransaction();
    foreach ($p['entries'] as $e) {
        $key = 'single';
        if ($p['mode'] === 'two') {
            $key = ($e['popularity_snapshot'] !== null && (int) $e['popularity_snapshot'] >= (int) $c['big_threshold']) ? 'big' : 'new';
        }
        $upd->execute([$key, (int) $e['id']]);
    }
    $db->prepare('UPDATE contests SET groups_mode = ?, groups_locked_at = NOW() WHERE id = ?')->execute([$p['mode'], $contestId]);
    $db->commit();
    return true;
}

function contestClientIpHash(): string {
    $ip = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]) ?: ($_SERVER['REMOTE_ADDR'] ?? '');
    return hash('sha256', 'contest-ip:' . $ip);
}

// Richiede un voto: controlla l'email, registra il voto "in attesa" e invia il link di conferma.
function contestRequestVote(array $c, int $entryId, string $emailInput): array {
    if (!contestVotingOpen($c)) {
        return ['ok' => false, 'error' => 'Le votazioni non sono aperte.'];
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM contest_entries WHERE id = ? AND contest_id = ? AND status = 'approved' AND group_key IS NOT NULL");
    $stmt->execute([$entryId, (int) $c['id']]);
    $entry = $stmt->fetch();
    if (!$entry) {
        return ['ok' => false, 'error' => 'Brano non trovato tra quelli in gara.'];
    }
    $norm = contestNormalizeGmail($emailInput);
    if ($norm === null) {
        return ['ok' => false, 'error' => 'Per votare serve un indirizzo Gmail (gmail.com o googlemail.com).'];
    }
    $dup = $db->prepare("SELECT id FROM contest_votes WHERE contest_id = ? AND group_key = ? AND email_norm = ? AND status = 'confirmed'");
    $dup->execute([(int) $c['id'], $entry['group_key'], $norm]);
    if ($dup->fetch()) {
        return ['ok' => false, 'error' => 'Hai già votato in questa classifica.'];
    }
    $ipHash = contestClientIpHash();
    $recentEmail = $db->prepare('SELECT COUNT(*) FROM contest_votes WHERE contest_id = ? AND email_norm = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    $recentEmail->execute([(int) $c['id'], $norm]);
    $recentIp = $db->prepare('SELECT COUNT(*) FROM contest_votes WHERE contest_id = ? AND ip_hash = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    $recentIp->execute([(int) $c['id'], $ipHash]);
    if ((int) $recentEmail->fetchColumn() >= 3 || (int) $recentIp->fetchColumn() >= 10) {
        return ['ok' => false, 'error' => 'Troppe richieste: riprova tra un po\'.'];
    }
    // Una sola richiesta in attesa per persona e classifica: la nuova sostituisce la precedente.
    $db->prepare("UPDATE contest_votes SET status = 'cancelled' WHERE contest_id = ? AND group_key = ? AND email_norm = ? AND status = 'pending'")
        ->execute([(int) $c['id'], $entry['group_key'], $norm]);
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO contest_votes (contest_id, entry_id, group_key, email_norm, email_to, ip_hash, token, expires_at) VALUES (?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ' . CONTEST_VOTE_TTL_HOURS . ' HOUR))')
        ->execute([(int) $c['id'], $entryId, $entry['group_key'], $norm, mb_substr(trim($emailInput), 0, 190), $ipHash, $token]);
    $voteId = (int) $db->lastInsertId();

    $link = siteUrl('/contest/vota/' . $token);
    $sponsorLine = '';
    if (!empty($entry['sponsor_id'])) {
        $sp = $db->prepare('SELECT name FROM contest_sponsors WHERE id = ?');
        $sp->execute([(int) $entry['sponsor_id']]);
        if ($spName = $sp->fetchColumn()) {
            $sponsorLine = "Questo brano è sostenuto da {$spName}.\n\n";
        }
    }
    $sent = contestSendMail(trim($emailInput), '', 'Conferma il tuo voto — ' . $c['name'],
        "Hai chiesto di votare \"{$entry['track_name']}\" di {$entry['band_name']} per {$c['name']}.\n\n" . $sponsorLine
        . "Per confermare il voto clicca qui:\n{$link}\n\n"
        . "Il link è personale, si usa una sola volta ed è valido " . CONTEST_VOTE_TTL_HOURS . " ore. Finché non confermi, il voto non viene contato. "
        . "Se non sei stato tu, ignora questa email.\n\n" . siteName());
    if (!$sent) {
        $db->prepare('DELETE FROM contest_votes WHERE id = ?')->execute([$voteId]);
        return ['ok' => false, 'error' => 'Non è stato possibile inviare l\'email di conferma. Riprova più tardi.'];
    }
    return ['ok' => true];
}

// Conferma un voto dal link. state: confirmed | already | expired | closed | duplicate | invalid
function contestConfirmVote(string $token): array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return ['state' => 'invalid'];
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT v.*, e.track_name, e.band_name, c.name AS contest_name, c.slug AS contest_slug FROM contest_votes v
        JOIN contest_entries e ON e.id = v.entry_id JOIN contests c ON c.id = v.contest_id WHERE v.token = ?');
    $stmt->execute([$token]);
    $v = $stmt->fetch();
    if (!$v) {
        return ['state' => 'invalid'];
    }
    if ($v['status'] === 'confirmed') {
        return ['state' => 'already', 'vote' => $v];
    }
    if ($v['status'] === 'cancelled') {
        return ['state' => 'invalid'];
    }
    if (strtotime($v['expires_at']) < time()) {
        return ['state' => 'expired', 'vote' => $v];
    }
    $c = contestGet((int) $v['contest_id']);
    if (!$c || !contestVotingOpen($c)) {
        return ['state' => 'closed', 'vote' => $v];
    }
    try {
        $upd = $db->prepare("UPDATE contest_votes SET status = 'confirmed', confirmed_at = NOW(), unique_key = ? WHERE id = ? AND status = 'pending'");
        $upd->execute([$v['contest_id'] . '|' . $v['group_key'] . '|' . $v['email_norm'], (int) $v['id']]);
    } catch (PDOException $e) {
        return ['state' => 'duplicate', 'vote' => $v];
    }
    return ['state' => 'confirmed', 'vote' => $v];
}

// Classifiche per gruppo con i soli voti confermati. ['big' => ['label', 'rows' => [...]], ...]
function contestRanking(array $c): array {
    $stmt = getDB()->prepare("SELECT e.id, e.band_name, e.track_name, e.image_url, e.track_url, e.group_key, e.popularity_snapshot,
            s.name AS sponsor_name, s.logo_path AS sponsor_logo, s.website_url AS sponsor_url,
            (SELECT COUNT(*) FROM contest_votes v WHERE v.entry_id = e.id AND v.status = 'confirmed') AS votes
        FROM contest_entries e LEFT JOIN contest_sponsors s ON s.id = e.sponsor_id
        WHERE e.contest_id = ? AND e.status = 'approved' AND e.group_key IS NOT NULL
        ORDER BY votes DESC, e.band_name ASC");
    $stmt->execute([(int) $c['id']]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $k = $r['group_key'];
        $out[$k]['label'] = CONTEST_GROUP_LABELS[$k] ?? $k;
        $out[$k]['rows'][] = $r;
    }
    uksort($out, fn ($a, $b) => array_search($a, ['big', 'new', 'single']) <=> array_search($b, ['big', 'new', 'single']));
    return $out;
}

// ---------------------------------------------------------------------------------------------
// Fase 3: sponsor
// ---------------------------------------------------------------------------------------------

// Salva il logo di uno sponsor mantenendo la trasparenza (PNG), max 600 px. Ritorna il percorso relativo o null.
function contestSaveLogo(string $field): ?string {
    $f = $_FILES[$field] ?? null;
    if (!$f || empty($f['name']) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || ($f['size'] ?? 0) > 5 * 1024 * 1024) {
        return null;
    }
    $raw = @file_get_contents($f['tmp_name']);
    $img = $raw !== false ? @imagecreatefromstring($raw) : false;
    if (!$img) {
        return null;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, 600 / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $dir = '/var/www/html/uploads/images/contest';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return null;
    }
    $name = bin2hex(random_bytes(8)) . '.png';
    return imagepng($out, $dir . '/' . $name, 6) ? 'uploads/images/contest/' . $name : null;
}

function contestDeleteLogo(?string $rel): void {
    if ($rel && str_starts_with($rel, 'uploads/images/contest/') && !str_contains($rel, '..')) {
        @unlink('/var/www/html/' . $rel);
    }
}

function contestSponsors(int $contestId): array {
    $stmt = getDB()->prepare('SELECT s.*, (SELECT COUNT(*) FROM contest_entries e WHERE e.sponsor_id = s.id) AS entries_count FROM contest_sponsors s WHERE s.contest_id = ? ORDER BY s.name');
    $stmt->execute([$contestId]);
    return $stmt->fetchAll();
}

function contestSponsorByToken(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $stmt = getDB()->prepare('SELECT * FROM contest_sponsors WHERE access_token = ?');
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function contestSponsorPreviewUrl(array $sponsor): string {
    return siteUrl('/contest/sponsor/' . $sponsor['access_token']);
}

// Abbina (o toglie, con 0) lo sponsor a un brano dello stesso contest.
function contestSetEntrySponsor(int $entryId, int $sponsorId): bool {
    $db = getDB();
    $e = $db->prepare('SELECT contest_id FROM contest_entries WHERE id = ?');
    $e->execute([$entryId]);
    $entry = $e->fetch();
    if (!$entry) {
        return false;
    }
    if ($sponsorId > 0) {
        $s = $db->prepare('SELECT id FROM contest_sponsors WHERE id = ? AND contest_id = ?');
        $s->execute([$sponsorId, (int) $entry['contest_id']]);
        if (!$s->fetch()) {
            return false;
        }
    }
    $db->prepare('UPDATE contest_entries SET sponsor_id = ? WHERE id = ?')->execute([$sponsorId > 0 ? $sponsorId : null, $entryId]);
    return true;
}

// ---------------------------------------------------------------------------------------------
// Indirizzi (slug), elenco e fasi
// ---------------------------------------------------------------------------------------------

// Parole che hanno già un significato sotto /contest/ e non possono essere uno slug.
const CONTEST_RESERVED_SLUGS = ['brano', 'vota', 'sponsor', 'archivio', 'nuovo', 'admin'];

function contestSlugAvailable(string $slug, ?int $excludeId = null): bool {
    if ($slug === '' || strlen($slug) > 80 || in_array($slug, CONTEST_RESERVED_SLUGS, true) || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
        return false;
    }
    $stmt = getDB()->prepare('SELECT id FROM contests WHERE slug = ?' . ($excludeId ? ' AND id <> ' . (int) $excludeId : ''));
    $stmt->execute([$slug]);
    return !$stmt->fetch();
}

// Proposta di slug unico a partire dal nome (suffisso -2, -3... se serve).
function contestUniqueSlug(string $name, ?int $excludeId = null): string {
    $base = substr(slugify($name), 0, 70) ?: 'contest';
    $slug = $base;
    for ($i = 2; !contestSlugAvailable($slug, $excludeId) && $i < 200; $i++) {
        $slug = $base . '-' . $i;
    }
    return $slug;
}

function contestUrl(array $c): string {
    return '/contest/' . $c['slug'];
}

function contestGetBySlug(string $slug): ?array {
    $stmt = getDB()->prepare('SELECT * FROM contests WHERE slug = ?');
    $stmt->execute([$slug]);
    return $stmt->fetch() ?: null;
}

// Contest pubblici (non in bozza): prima quelli attivi, poi i conclusi.
function contestPublicList(): array {
    return getDB()->query("SELECT c.*, (SELECT COUNT(*) FROM contest_entries e WHERE e.contest_id = c.id AND e.status = 'approved') AS entries_count
        FROM contests c WHERE c.status <> 'draft' ORDER BY FIELD(c.status, 'voting', 'open', 'closed'), c.id DESC")->fetchAll();
}

// Fase corrente per la barra di avanzamento: step 0 candidature, 1 votazioni, 2 vincitori.
function contestPhaseInfo(array $c): array {
    if ($c['status'] === 'draft') {
        return ['step' => 0, 'label' => 'Bozza', 'target' => null, 'countdown' => ''];
    }
    if ($c['status'] === 'closed') {
        return ['step' => 2, 'label' => 'Concluso', 'target' => null, 'countdown' => ''];
    }
    if ($c['status'] === 'voting') {
        $start = !empty($c['voting_start_at']) ? strtotime($c['voting_start_at']) : null;
        if ($start && $start > time()) {
            return ['step' => 1, 'label' => 'Votazioni in arrivo', 'target' => $start, 'countdown' => 'Le votazioni aprono tra'];
        }
        $end = !empty($c['voting_end_at']) ? strtotime($c['voting_end_at']) : null;
        return ['step' => 1, 'label' => 'Votazioni aperte', 'target' => $end, 'countdown' => 'Le votazioni chiudono tra'];
    }
    $close = !empty($c['candidatures_close_at']) ? strtotime($c['candidatures_close_at']) : null;
    return ['step' => 0, 'label' => 'Candidature aperte', 'target' => $close, 'countdown' => 'Le candidature chiudono tra'];
}

// ---------------------------------------------------------------------------------------------
// Scheletro grafico condiviso dalle pagine pubbliche del contest (stile link in bio)
// ---------------------------------------------------------------------------------------------

function contestHead(string $title, string $extraHead = '', bool $noindex = false): void {
    ?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($noindex): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<title><?= e($title) ?> — <?= e(siteName()) ?></title>
<?= $extraHead ?>
<link rel="stylesheet" href="<?= assetUrl('/assets/css/contest.css') ?>">
<?= embedPrivacyScript() ?>
<?= embedTrackingHead() ?>
<?= embedGoogleAnalytics() ?>
</head>
<body class="contest">
<div class="wrap">
<?php
}

function contestFoot(string $scripts = ''): void {
    ?>
  <div class="foot"><?= e(siteName()) ?></div>
</div>
<?= $scripts ?>
</body>
</html>
<?php
}

// Logo del contest (immagine caricata) oppure emoji di ripiego.
function contestLogoHtml(array $c): string {
    return !empty($c['logo_path'])
        ? '<img src="/' . e($c['logo_path']) . '" alt="' . e($c['name']) . '">'
        : '🎸';
}

function contestDateRange(?string $a, ?string $b): string {
    $f = fn ($d) => $d ? date('d/m/Y', strtotime($d)) : null;
    if ($a && $b) {
        return $f($a) . ' – ' . $f($b);
    }
    return (string) ($f($a) ?: $f($b) ?: '');
}
