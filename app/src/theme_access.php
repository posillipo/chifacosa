<?php
// Visibilità dei temi grafici per utente (Area Admin > Temi grafici).
//
// Per ogni tema (chiave di PAGE_THEMES) l'amministratore sceglie CHI può sceglierlo:
//   all       tutti (impostazione predefinita: un tema senza regola è visibile a tutti)
//   admins    solo gli amministratori del sito
//   selected  gli amministratori + gli utenti indicati uno per uno
// Gli amministratori possono sempre usare qualunque tema. Una regola limita solo la SCELTA di un tema:
// i profili che lo stanno già usando lo mantengono (la loro pagina pubblica non cambia da sola).
// Se le tabelle non esistono ancora (migrazione non eseguita) tutti i temi restano visibili a tutti.

const THEME_VISIBILITY_MODES = [
    'all' => 'Tutti gli utenti',
    'admins' => 'Solo amministratori',
    'selected' => 'Amministratori + utenti scelti',
];

// Regole salvate: [theme_key => ['mode' => ..., 'users' => [user_id, ...]]]. Solo i temi con una regola.
function themeVisibilityRules(bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $cache = [];
    try {
        $db = getDB();
        foreach ($db->query('SELECT theme_key, mode FROM theme_visibility')->fetchAll() as $r) {
            $cache[$r['theme_key']] = ['mode' => $r['mode'], 'users' => []];
        }
        foreach ($db->query('SELECT theme_key, user_id FROM theme_visibility_users')->fetchAll() as $r) {
            if (isset($cache[$r['theme_key']])) {
                $cache[$r['theme_key']]['users'][] = (int) $r['user_id'];
            }
        }
    } catch (Throwable $e) {
        $cache = []; // tabelle assenti: nessuna restrizione
    }
    return $cache;
}

// Il tema $themeKey può essere scelto da chi ha uno degli id in $userIds (account e profilo attivo)?
function themeIsAllowed(string $themeKey, array $userIds, bool $isAdmin): bool {
    if ($isAdmin) {
        return true;
    }
    $rule = themeVisibilityRules()[$themeKey] ?? null;
    if ($rule === null || $rule['mode'] === 'all') {
        return true;
    }
    if ($rule['mode'] === 'admins') {
        return false;
    }
    foreach ($userIds as $id) {
        if (in_array((int) $id, $rule['users'], true)) {
            return true;
        }
    }
    return false;
}

// Temi che l'utente può scegliere, nell'ordine di PAGE_THEMES. $keepKey (il tema già in uso) resta
// sempre nell'elenco, anche se nel frattempo è stato limitato, così l'utente non lo perde.
function themesVisibleFor(array $userIds, bool $isAdmin, ?string $keepKey = null): array {
    $out = [];
    foreach (PAGE_THEMES as $key => $theme) {
        if ($key === $keepKey || themeIsAllowed($key, $userIds, $isAdmin)) {
            $out[$key] = $theme;
        }
    }
    return $out;
}

// Salva la regola di un tema. $users = elenco di id utente (solo per "selected").
function themeVisibilitySave(string $themeKey, string $mode, array $users): void {
    $db = getDB();
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM theme_visibility_users WHERE theme_key=?')->execute([$themeKey]);
        if ($mode === 'all') {
            $db->prepare('DELETE FROM theme_visibility WHERE theme_key=?')->execute([$themeKey]);
        } else {
            $db->prepare('INSERT INTO theme_visibility (theme_key, mode) VALUES (?, ?) ON DUPLICATE KEY UPDATE mode=VALUES(mode)')->execute([$themeKey, $mode]);
            if ($mode === 'selected') {
                $ins = $db->prepare('INSERT IGNORE INTO theme_visibility_users (theme_key, user_id) VALUES (?, ?)');
                foreach (array_unique(array_map('intval', $users)) as $uid) {
                    if ($uid > 0) {
                        $ins->execute([$themeKey, $uid]);
                    }
                }
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
