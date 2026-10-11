# CLAUDE.md — chifacosa

## Regole operative
- **Il merge su main lo fa sempre Claude** (`mcp__github__merge_pull_request`)
- Non chiedere conferma per il merge, farlo autonomamente

## Architettura
- Due repo: `posillipo/chifacosa` e `posillipo/myband`
- myband sincronizza i file `app/` da chifacosa via sync bot, ma NON `mcp-server/server.js`
- Il MCP server myband usa `mybandApi` (non `chifacosaApi`) — modificare direttamente nel repo myband

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
