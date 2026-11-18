<?php
// Firma dell'autore dei contenuti (dashboard, Telegram, API/MCP).
// Funzioni spostate da functions.php senza modifiche (stesso contenuto, solo file diverso).


// Converte una data/ora salvata nel database (interpretata nel fuso orario con cui il server PHP
// l'ha scritta — quello restituito da date_default_timezone_get(), lo stesso usato da NOW()/
// CURRENT_TIMESTAMP/date() ovunque nel codice) nel fuso orario scelto dal profilo, SOLO per la
// visualizzazione: non tocca mai il valore salvato. Nata da una segnalazione utente ("l'orario è
// sballato, forse perché il server non è in Italia") — prima ogni pagina ristampava la data così
// com'è, nel fuso del server, chiunque fosse il proprietario del profilo.
// ===== Firma dell'autore dei contenuti (solo dashboard) =====
// Salva chi ha creato un contenuto (colonna created_by): persona in dashboard, Telegram o IA via API/MCP.
// Solo tabelle ammesse (nome tabella mai preso da input).
function setContentAuthor(string $table, int $id, ?string $by): void {
    if (!$by || $id <= 0 || !in_array($table, ['timeline_posts', 'photo_albums', 'events', 'services', 'videos', 'blog_posts'], true)) {
        return;
    }
    try {
        getDB()->prepare("UPDATE `$table` SET created_by = ? WHERE id = ?")->execute([mb_substr($by, 0, 160), $id]);
    } catch (Throwable $e) {
        // Colonna non ancora migrata: la firma è un extra, non deve bloccare la creazione.
    }
}


function dashboardAuthorSignature(array $user): string {
    return 'Dashboard · ' . mb_substr((string) (($user['display_name'] ?? '') ?: ($user['slug'] ?? 'utente')), 0, 100);
}


// Firma per le richieste API/MCP: etichetta del token + eventuale nome dichiarato dall'IA ("author").
// Nessuna verifica: si scrive il nome che l'IA dichiara. Senza nome (non dovrebbe accadere, il campo è
// obbligatorio in creazione) si ripiega sull'etichetta del token.
function apiAuthorSignature(array $auth, array $data): string {
    $decl = trim((string) ($data['author'] ?? ''));
    return mb_substr($decl !== '' ? 'IA · ' . $decl : 'API · ' . ($auth['token_label'] ?? 'token'), 0, 160);
}


// In modifica da API/MCP la firma è facoltativa: se "author" è presente (non vuoto) sostituisce quella salvata.
function apiApplyAuthorOnUpdate(string $table, int $id, array $auth, array $data): void {
    if (trim((string) ($data['author'] ?? '')) !== '') {
        setContentAuthor($table, $id, apiAuthorSignature($auth, $data));
    }
}


// In creazione da API/MCP la firma è obbligatoria: senza "author" la richiesta viene rifiutata con un messaggio chiaro.
function apiRequireAuthor(array $auth, array $data): void {
    if (trim((string) ($data['author'] ?? '')) === '') {
        apiError(422, 'Il campo "author" è obbligatorio in creazione: indica chi sta creando il contenuto (es. il nome dell\'IA o della routine).', $auth['token_id'] ?? null, $auth['user_id'] ?? null);
    }
}


function authorBadgeHtml(?string $by): string {
    if ($by === null || $by === '') {
        return '';
    }
    return '<span style="background:#f1f3f5;color:#495057;border:1px solid #ced4da;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;" title="Autore (visibile solo qui)"><i class="fa-solid fa-pen-nib"></i> ' . e($by) . '</span>';
}
