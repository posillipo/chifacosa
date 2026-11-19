<?php
// Eliminazione dell'account da parte dell'utente (diritto alla cancellazione): riepilogo, controlli e cancellazione.
// Le tabelle con chiave esterna verso users si svuotano da sole (ON DELETE CASCADE); qui si aggiungono i file
// su disco e le (poche) tabelle senza chiave esterna.

// Profili creati dall'utente (ruolo "owner"), esclusi se stesso: vengono eliminati insieme all'account.
function accountDeletionOwnedProfiles(int $userId): array {
    $st = getDB()->prepare("SELECT u.id, u.slug, p.display_name FROM profile_admins pa JOIN users u ON u.id = pa.owner_user_id LEFT JOIN profiles p ON p.user_id = u.id WHERE pa.admin_user_id = ? AND pa.role = 'owner' AND u.id <> ? ORDER BY u.id");
    $st->execute([$userId, $userId]);
    return $st->fetchAll();
}

// Quanti elementi verranno cancellati, per i soli profili coinvolti (account + profili creati).
function accountDeletionSummary(array $userIds): array {
    $tables = ['blog_posts' => 'Articoli del blog', 'timeline_posts' => 'Post della Timeline', 'photo_albums' => 'Album foto', 'events' => 'Eventi', 'services' => 'Servizi', 'videos' => 'Video', 'audio_tracks' => 'Brani audio', 'links' => 'Link', 'contact_requests' => 'Messaggi ricevuti dai contatti', 'followers' => 'Follower via email'];
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $out = [];
    foreach ($tables as $t => $label) {
        try {
            $st = getDB()->prepare("SELECT COUNT(*) FROM `$t` WHERE user_id IN ($in)");
            $st->execute($userIds);
            $out[$label] = (int) $st->fetchColumn();
        } catch (Throwable $e) {
        }
    }
    return $out;
}

// Motivo per cui l'account NON si può eliminare da qui (null = si può).
function accountDeletionBlocker(array $user): ?string {
    $acting = $_SESSION['acting_as_user_id'] ?? null;
    if ($acting !== null && (int) $acting !== (int) $user['id']) {
        return 'Stai gestendo un altro profilo. Torna al tuo account principale (cambio profilo) per eliminarlo.';
    }
    if (!empty($user['is_admin'])) {
        $st = getDB()->prepare('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND is_active = 1 AND id <> ?');
        $st->execute([(int) $user['id']]);
        if ((int) $st->fetchColumn() === 0) {
            return 'Sei l\'unico amministratore del sito: nomina prima un altro amministratore, poi potrai eliminare l\'account.';
        }
    }
    return null;
}

// Elimina una cartella dentro /var/www/html/uploads (percorso controllato, mai fuori da uploads).
function accountRemoveUploadsDir(string $dir): void {
    $root = realpath('/var/www/html/uploads');
    $real = realpath($dir);
    if (!$root || !$real || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0 || !is_dir($real)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($real);
}

// Percorsi dei file caricati (uploads/...) referenziati nel database dai profili indicati: tutte le colonne
// che contengono un percorso (path, image, photo, cover, avatar, thumb, file, media) delle tabelle con user_id,
// più le foto degli album. Va chiamata PRIMA di cancellare le righe.
function accountCollectUploadPaths(array $userIds): array {
    $db = getDB();
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $paths = [];
    $add = function ($v) use (&$paths) {
        $v = trim((string) $v);
        if ($v === '') return;
        // accetta sia "uploads/..." sia URL completi del sito
        if (($i = strpos($v, 'uploads/')) !== false && !str_contains($v, '..')) {
            $paths[substr($v, $i)] = true;
        }
    };
    try {
        $cols = $db->query("SELECT c.TABLE_NAME t, c.COLUMN_NAME c FROM information_schema.COLUMNS c JOIN information_schema.TABLES tb ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME AND tb.TABLE_TYPE = 'BASE TABLE' WHERE c.TABLE_SCHEMA = DATABASE() AND c.DATA_TYPE IN ('varchar','text','mediumtext','longtext') AND c.COLUMN_NAME REGEXP 'path|image|photo|cover|avatar|thumb|file|media|logo|banner'")->fetchAll();
        $hasUser = [];
        foreach ($db->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'user_id'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $hasUser[$t] = true;
        }
        foreach ($cols as $r) {
            $t = $r['t'];
            $c = $r['c'];
            if ($t === 'users') {
                $sql = "SELECT `$c` FROM `users` WHERE id IN ($in)";
            } elseif ($t === 'photo_album_photos') {
                $sql = "SELECT `$c` FROM `photo_album_photos` WHERE album_id IN (SELECT id FROM photo_albums WHERE user_id IN ($in))";
            } elseif (isset($hasUser[$t])) {
                $sql = "SELECT `$c` FROM `$t` WHERE user_id IN ($in)";
            } else {
                continue;
            }
            try {
                $st = $db->prepare($sql);
                $st->execute($userIds);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) $add($v);
            } catch (Throwable $e) {
            }
        }
    } catch (Throwable $e) {
        error_log('accountCollectUploadPaths: ' . $e->getMessage());
    }
    return array_keys($paths);
}

// Cancella account e profili creati dall'utente. Ritorna ['ok'=>bool, 'deleted'=>n profili] .
function accountDeleteEverything(int $userId): array {
    $db = getDB();
    $owned = accountDeletionOwnedProfiles($userId);
    $ids = array_merge([$userId], array_map(fn($p) => (int) $p['id'], $owned));
    $in = implode(',', array_fill(0, count($ids), '?'));

    $st = $db->prepare("SELECT slug FROM users WHERE id IN ($in)");
    $st->execute($ids);
    $slugs = $st->fetchAll(PDO::FETCH_COLUMN);
    $files = accountCollectUploadPaths($ids);

    try {
        $db->beginTransaction();
        // Tabelle senza chiave esterna verso users: pulizia esplicita.
        foreach (['api_request_logs' => 'user_id', 'telegram_pending_posts' => 'profile_user_id', 'social_queue' => 'user_id'] as $t => $col) {
            try {
                $db->prepare("DELETE FROM `$t` WHERE `$col` IN ($in)")->execute($ids);
            } catch (Throwable $e) {
                // tabella non presente su questo database: si prosegue
            }
        }
        $db->prepare("DELETE FROM users WHERE id IN ($in)")->execute($ids);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('accountDeleteEverything: ' . $e->getMessage());
        return ['ok' => false, 'deleted' => 0];
    }

    // File su disco, solo dopo che il database è andato a buon fine.
    foreach ($slugs as $slug) {
        if (preg_match('/^[A-Za-z0-9_-]+$/', (string) $slug)) {
            accountRemoveUploadsDir('/var/www/html/uploads/images/' . $slug);
            accountRemoveUploadsDir('/var/www/html/uploads/videos/' . $slug);
        }
    }
    // Singoli file referenziati dal database (copre anche cartelle di slug vecchi, cambiati dopo il caricamento).
    $root = realpath('/var/www/html/uploads');
    foreach ($files as $rel) {
        $path = realpath('/var/www/html/' . ltrim($rel, '/'));
        if ($path && $root && strpos($path, $root . DIRECTORY_SEPARATOR) === 0 && is_file($path)) {
            @unlink($path);
        }
    }
    return ['ok' => true, 'deleted' => count($ids)];
}

// Email di conferma dell'eliminazione (se l'SMTP non è configurato non fa nulla).
function notifyAccountDeleted(string $toEmail, string $toName): bool {
    $cfg = getSmtpConfig();
    if (!$cfg['host']) {
        return false;
    }
    require_once __DIR__ . '/mailer.php';
    $mailer = new SimpleSmtpMailer($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['secure'], $cfg['verifyCert']);
    $subject = 'Il tuo account su ' . siteName() . ' è stato eliminato';
    $body = "Ciao {$toName},\n\n"
          . "Come richiesto, abbiamo eliminato il tuo account su " . siteName() . " con tutti i contenuti che avevi pubblicato.\n"
          . "L'operazione è definitiva.\n\n"
          . "Se non sei stato tu a chiederlo, rispondi a questa email il prima possibile.\n"
          . "Se un giorno vorrai tornare, potrai registrarti di nuovo su " . siteUrl('/register.php') . ".";
    return $mailer->send($cfg['from'], $cfg['fromName'], $toEmail, $toName, $subject, $body);
}
