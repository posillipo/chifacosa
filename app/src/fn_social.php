<?php
// Follower, recensioni e valutazioni.
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


// Verifica se due account si seguono A VICENDA (condizione necessaria per potersi scrivere).
function areMutualFollowers(int $userIdA, int $userIdB): bool {
    $stmt = getDB()->prepare('SELECT COUNT(*) c FROM account_follows
        WHERE (follower_user_id = ? AND followed_user_id = ?) OR (follower_user_id = ? AND followed_user_id = ?)');
    $stmt->execute([$userIdA, $userIdB, $userIdB, $userIdA]);
    return (int) $stmt->fetch()['c'] === 2;
}


// ===== Segui tra account (diverso da "Segui via email") =====

function isFollowingAccount(int $followerId, int $followedId): bool {
    $stmt = getDB()->prepare('SELECT id FROM account_follows WHERE follower_user_id=? AND followed_user_id=?');
    $stmt->execute([$followerId, $followedId]);
    return (bool) $stmt->fetch();
}


function getFollowedUserIds(int $userId): array {
    $stmt = getDB()->prepare('SELECT followed_user_id FROM account_follows WHERE follower_user_id = ?');
    $stmt->execute([$userId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'followed_user_id'));
}


function getAccountFollowerCount(int $userId): int {
    $stmt = getDB()->prepare('SELECT COUNT(*) c FROM account_follows WHERE followed_user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetch()['c'];
}


// Rendering HTML condiviso di un singolo elemento della Timeline, riusato sia dal primo
// caricamento della pagina sia dalle richieste "carica altri" dello scrolling infinito.
// ===== Sistema di recensioni (solo voto a stelle, nessun commento) =====

// Media e conteggio voti per una band o un brano
function getBandRatingStats(int $bandUserId): array {
    $stmt = getDB()->prepare('SELECT AVG(rating) avg_r, COUNT(*) n FROM band_reviews WHERE band_user_id = ?');
    $stmt->execute([$bandUserId]);
    $r = $stmt->fetch();
    return ['avg' => $r['avg_r'] ? round((float) $r['avg_r'], 1) : null, 'count' => (int) $r['n']];
}


function getTrackRatingStats(int $trackId): array {
    $stmt = getDB()->prepare('SELECT AVG(rating) avg_r, COUNT(*) n FROM track_reviews WHERE track_id = ?');
    $stmt->execute([$trackId]);
    $r = $stmt->fetch();
    return ['avg' => $r['avg_r'] ? round((float) $r['avg_r'], 1) : null, 'count' => (int) $r['n']];
}


// Resa grafica a stelle piene (★), arrotondate al valore intero più vicino — usata sia per il
// voto di una singola persona sia per la media arrotondata di un gruppo di voti
function renderCromeRating(?float $rating, int $max = 5): string {
    if ($rating === null) {
        return '<span style="color:rgba(var(--text-rgb),0.4);font-size:13px;">Nessun voto ancora</span>';
    }
    $filled = (int) round($rating);
    $html = '<span style="letter-spacing:2px;">';
    for ($i = 1; $i <= $max; $i++) {
        $html .= $i <= $filled
            ? '<span style="color:rgb(108,92,231);">★</span>'
            : '<span style="color:rgba(var(--text-rgb),0.25);">★</span>';
    }
    $html .= '</span>';
    return $html;
}


// Form di voto a 5 stelle cliccabili (ognuna è un pulsante che invia quel valore) — mostra un
// messaggio diverso se l'utente ha già votato, senza permettere una seconda recensione
function renderRatingForm(string $action, int $targetId, ?int $viewerId, int $ownerUserId, ?int $existingRating): string {
    if (!$viewerId) {
        $currentUrl = ($_SERVER['REQUEST_URI'] ?? '/');
        $loginUrl = '/login.php?redirect=' . urlencode($currentUrl);
        return '<a href="' . e($loginUrl) . '" class="segui-pill" style="display:inline-block;">✨ Vota</a>'
             . '<p style="color:rgba(var(--text-rgb),0.55);font-size:12.5px;margin-top:6px;">Accedi o registrati per lasciare un voto.</p>';
    }
    if ($viewerId === $ownerUserId) {
        return '<p style="color:rgba(var(--text-rgb),0.6);font-size:13px;">Non puoi votare te stesso.</p>';
    }
    $html = '<div style="margin-top:10px;">';
    if ($existingRating !== null) {
        $html .= '<p style="font-size:13px;color:rgba(var(--text-rgb),0.6);margin-bottom:6px;">Il tuo voto: ' . renderCromeRating((float) $existingRating) . ' — clicca per modificarlo</p>';
    } else {
        $html .= '<p style="font-size:13px;color:rgba(var(--text-rgb),0.6);margin-bottom:6px;">Lascia il tuo voto:</p>';
    }
    $html .= '<div style="display:flex;gap:6px;">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<form method="post" style="display:inline;">' . csrfField()
            . '<input type="hidden" name="action" value="' . e($action) . '">'
            . '<input type="hidden" name="target_id" value="' . $targetId . '">'
            . '<input type="hidden" name="rating" value="' . $i . '">'
            . '<button type="submit" style="background:none;border:none;font-size:22px;cursor:pointer;color:' . ($existingRating !== null && $i <= $existingRating ? 'rgb(108,92,231)' : 'rgba(var(--text-rgb),0.3)') . ';">★</button>'
            . '</form>';
    }
    $html .= '</div></div>';
    return $html;
}


// ===== Sistema "Segui via email" =====

function getFollowerCount(int $artistUserId): int {
    $stmt = getDB()->prepare('SELECT COUNT(*) c FROM followers WHERE user_id = ? AND verified = 1');
    $stmt->execute([$artistUserId]);
    return (int) $stmt->fetch()['c'];
}


// ===== Messaggi diretti: cancellare una conversazione solo per sé =====
// Ogni messaggio ha due segni (migrazione 2026_11_40): cancellato da chi l'ha scritto / da chi l'ha ricevuto.
// Cancellare una conversazione la nasconde solo a chi la cancella: l'altra persona continua a vederla.
// Senza la migrazione tutto funziona come prima e la cancellazione non è disponibile.

/** True se direct_messages ha le colonne deleted_by_sender / deleted_by_recipient. */
function directMessagesHaveDeleteColumns(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool) getDB()->query("SHOW COLUMNS FROM direct_messages LIKE 'deleted_by_recipient'")->fetch();
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** Condizione da aggiungere a una WHERE su direct_messages: esclude i messaggi che $meId ha cancellato per sé. */
function dmHideDeletedSql(int $meId): string {
    if (!directMessagesHaveDeleteColumns()) {
        return '';
    }
    return " AND ((sender_id = {$meId} AND deleted_by_sender = 0) OR (recipient_id = {$meId} AND deleted_by_recipient = 0))";
}

/** Cancella per $meId tutta la conversazione con $otherId (solo per lui). Ritorna false se la funzione non è attiva. */
function dmDeleteConversation(int $meId, int $otherId): bool {
    if (!directMessagesHaveDeleteColumns()) {
        return false;
    }
    $db = getDB();
    $db->prepare('UPDATE direct_messages SET deleted_by_sender = 1 WHERE sender_id = ? AND recipient_id = ?')->execute([$meId, $otherId]);
    $db->prepare('UPDATE direct_messages SET deleted_by_recipient = 1, read_at = COALESCE(read_at, NOW()) WHERE sender_id = ? AND recipient_id = ?')->execute([$otherId, $meId]);
    return true;
}
