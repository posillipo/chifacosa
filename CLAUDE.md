# CLAUDE.md — chifacosa

## Regole operative
- **Il merge su main lo fa sempre Claude** (`mcp__github__merge_pull_request`)
- Non chiedere conferma per il merge, farlo autonomamente

## Architettura
- Due repo: `posillipo/chifacosa` e `posillipo/myband`
- myband sincronizza i file `app/` da chifacosa via sync bot, ma NON `mcp-server/server.js`
- Il MCP server myband usa `mybandApi` (non `chifacosaApi`) — modificare direttamente nel repo myband

## Struttura del codice
- `app/src/functions.php` è solo il punto d'ingresso (24 righe): carica i file per argomento `app/src/fn_*.php` (core, auth, feed, adminlte_layout, adminlte_pages, blog, dates, media, notifications, navigation, ...). Le pagine includono sempre e solo `functions.php`.
- Le funzioni nuove vanno nel `fn_*.php` dell'argomento giusto (o in un nuovo file, con il relativo `require_once` in `functions.php`), non in `functions.php`. I file stanno in `app/src/` perché alcune funzioni usano `__DIR__`.
- Le costanti condivise stanno in `fn_constants.php`, caricato per primo.

## Database
- Container MySQL: `myband_db`
- Root password: in Portainer (non salvare qui)
- 4 database: `chifacosa`, `cfc_new`, `lacaraffa`, `myband`

### Template migration script
```bash
for db in chifacosa cfc_new lacaraffa myband; do
  docker exec -i myband_db mysql -uroot -p'<ROOT_PASSWORD>' "$db" -e "SQL_QUI;" 2>/dev/null \
    && echo "$db OK" || echo "$db SKIP"
done
```

## Bearer tokens profili
- Salvati in `/data/profiles.json` sul volume Docker
- NON in variabili d'ambiente, NON in chat, NON in codice

## MCP_ACCESS_TOKEN
- Solo variabile d'ambiente, mai in codice

## Guide delle pagine e documentazione
- Le pagine della dashboard NON hanno riquadri descrittivi in cima: il testo di aiuto sta nella tabella `page_help` (popup "?" accanto al titolo, modificabile da Admin → Guide delle pagine). Codice in `app/src/fn_help.php`.
- Ogni modifica al comportamento di una pagina deve aggiornare anche il suo testo in `page_help` (nuova migrazione con `INSERT ... ON DUPLICATE KEY UPDATE`) e la documentazione (`DOCUMENTAZIONE_TECNICA_COMPLETA.md`, `SCHEMA_DATABASE.md`, `MIGRAZIONI.md`).

## Studio (copia della dashboard per la nuova grafica)
- `app/public/studio/` è una copia GENERATA della dashboard (`scripts/make-studio.py`): non modificare a mano le pagine in `studio/dashboard*.php`. Dopo ogni modifica a `app/public/dashboard*.php` (o alla testata `_dash_header.php`) rilancia `python3 scripts/make-studio.py` e committa il risultato. La grafica nuova sta in `studio/assets/studio.css`.
- **CSS e JavaScript fuori dalle pagine**: in Studio niente `<style>`, `style="..."` e `<script>` con codice dentro le pagine. Gli stili stanno in `studio/assets/studio.css`, i comportamenti in `studio/assets/*.js`. Fanno eccezione solo i valori dinamici (es. colori scelti dall'utente), passati come variabili CSS o attributi `data-*`.
