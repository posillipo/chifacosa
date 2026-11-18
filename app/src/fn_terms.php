<?php
// Condizioni d'uso: testo configurabile da Admin, accettazione alla registrazione e (se serve) al primo accesso.
// Finché nessun testo è configurato, niente cambia: nessuna casella, nessun blocco.

function termsContent(): string {
    return trim((string) (getSiteSetting('terms_content') ?: ''));
}

function termsEnabled(): bool {
    return termsContent() !== '';
}

// Versione = impronta breve del testo: si salva con l'accettazione, per sapere che cosa è stato accettato.
function termsVersion(): string {
    $c = termsContent();
    return $c === '' ? '' : substr(sha1($c), 0, 12);
}

function termsRecordAcceptance(int $userId): void {
    try {
        getDB()->prepare('UPDATE users SET terms_accepted_at = NOW(), terms_version = ? WHERE id = ?')->execute([termsVersion(), $userId]);
    } catch (Throwable $e) {
        // Colonne non ancora migrate (2026_11_28): la registrazione non deve fallire per questo.
        error_log('termsRecordAcceptance: ' . $e->getMessage());
    }
}

// Serve l'accettazione? Solo se: testo configurato, utente che non ha mai accettato, e (nuovo iscritto dopo
// l'attivazione oppure l'admin ha scelto di chiederla anche ai già registrati).
function termsNeededForUser(array $u): bool {
    if (!termsEnabled() || !array_key_exists('terms_accepted_at', $u) || !empty($u['terms_accepted_at'])) {
        return false;
    }
    if (getSiteSetting('terms_gate_existing') === '1') {
        return true;
    }
    $since = (string) (getSiteSetting('terms_enabled_since') ?: '');
    return $since !== '' && !empty($u['created_at']) && strtotime((string) $u['created_at']) >= strtotime($since);
}

// Da chiamare dopo il login: manda alla pagina di accettazione se serve (mai per le pagine che servono a uscire o leggere).
function termsGate(array $u): void {
    if (!termsNeededForUser($u)) {
        return;
    }
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $exempt = ['accetta_condizioni.php', 'condizioni.php', 'logout.php', 'dashboard_delete_account.php', 'account_eliminato.php'];
    if (in_array($script, $exempt, true) || strncmp($script, 'api_', 4) === 0 || $_SERVER['REQUEST_METHOD'] !== 'GET') {
        return;
    }
    header('Location: /accetta_condizioni.php?next=' . urlencode((string) ($_SERVER['REQUEST_URI'] ?? '/dashboard.php')));
    exit;
}
