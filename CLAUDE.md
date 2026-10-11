# CLAUDE.md — istruzioni persistenti per Claude Code

## Database

Container MySQL: `myband_db`
Credenziali: `-uroot -p'<vedi .env o chiedi a Gianluca>'`
Database attivi: `chifacosa`, `cfc_new`, `lacaraffa`, `myband`

Esempio migrazione su tutti i DB:
```bash
for db in chifacosa cfc_new lacaraffa myband; do
  docker exec -i myband_db mysql -uroot -p'cambiami_root_123' "$db" -e "..." && echo "$db OK" || echo "$db SKIP"
done
```

## Git / Deploy

- Branch di sviluppo: `claude/repository-capabilities-9x6fu2` (o nuovo branch per ogni feature)
- **Il merge su `main` lo fa sempre Claude** (non l'utente) — usare `mcp__github__merge_pull_request`
- Dopo il merge, l'utente rideploya su Portainer manualmente

## Architettura

- App PHP: `app/public/` — endpoint API in `api_*.php`, routing in `.htaccess`
- MCP server: `mcp-server/server.js` — Node.js su Portainer, si connette a `https://www.chifacosa.it/api/v1`
- Auth MCP: `MCP_ACCESS_TOKEN` (env var)
- Auth API chifacosa: Bearer token per profilo, salvato in `/data/profiles.json` sul container MCP
- myband repo si sincronizza automaticamente da chifacosa via sync bot

## Social Queue (Metricool auto-publish)

- Tabella: `social_queue`
- Funzione hook: `socialQueueEnqueueIfEnabled()` in `app/src/metricool_queue.php`
- MCP tool lettura: `get_social_queue` (profilo)
- MCP tool aggiornamento: `update_social_queue_item` (profilo, id, status, error_msg)
- La Routine Claude (oraria) processa la coda via MCP — NON fa chiamate HTTP dirette (bloccate dal proxy egress)
