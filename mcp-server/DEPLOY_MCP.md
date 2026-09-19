# Deploy del server MCP — stesso server Hetzner di CHIFACOSA

Server MCP remoto (Streamable HTTP) che collega Claude (claude.ai o Claude Desktop) a un
profilo CHIFACOSA, riusando l'API pubblica `/api/v1/social-posts/*` già esistente. Va sul
**stesso** server Hetzner, come stack Portainer separato — stessa Nginx Proxy Manager per
l'HTTPS, nessun hosting nuovo da pagare.

## 1) Genera i due token prima di iniziare

1. **Token CHIFACOSA**: su `https://www.chifacosa.it` → login → Dashboard → API → crea un
   token → copialo (è `CHIFACOSA_API_TOKEN` più sotto).
2. **Token di accesso al server MCP**: un valore a scelta tua, lungo e casuale (es.
   `openssl rand -hex 32` dal terminale) — è `MCP_ACCESS_TOKEN` più sotto, quello che incollerai
   in claude.ai quando aggiungi il connector. Non è legato a CHIFACOSA, lo inventi tu.

## 2) Creare lo Stack in Portainer

1. **Stacks** → **Add stack**
2. **Nome Stack**: `chifacosa-mcp`
3. **Repository**: Attiva "Git repository"
   - **Repository URL**: `https://github.com/posillipo/chifacosa.git`
   - **Repository ref**: `refs/heads/main`
   - **Compose path**: `mcp-server/docker-compose.yml`
4. **Environment variables**:

| Variabile | Valore |
|---|---|
| `MCP_ACCESS_TOKEN` | il token generato al punto 1.2 |
| `CHIFACOSA_API_TOKEN` | il token generato al punto 1.1 |
| `CHIFACOSA_BASE_URL` | lascia vuoto per usare `https://www.chifacosa.it/api/v1/social-posts` (default) |

5. **Deploy the stack**

## 3) Proxy Host in Nginx Proxy Manager

1. **Proxy Hosts** → **Add Proxy Host**
2. **Domain Names**: un sottodominio dedicato, es. `mcp.chifacosa.it` (serve un record DNS `A`
   verso l'IP del server, come per gli altri sottodomini)
3. **Scheme**: `http`, **Forward Hostname/IP**: `chifacosa_mcp`, **Forward Port**: `3000`
4. Tab **SSL**: richiedi certificato Let's Encrypt, **Force SSL**

## 4) Verifica che risponda

```bash
curl -s https://mcp.chifacosa.it/health
# deve rispondere: {"ok":true}

curl -s -o /dev/null -w "%{http_code}\n" -X POST https://mcp.chifacosa.it/mcp
# deve rispondere: 401 (nessun token fornito — corretto)
```

## 5) Aggiungere il connector su claude.ai

Impostazioni → Connectors → **Add custom connector**:
- **URL**: `https://mcp.chifacosa.it/mcp`
- **Autenticazione**: header personalizzato
  - Nome: `Authorization`
  - Valore: `Bearer IL_TUO_MCP_ACCESS_TOKEN` (quello del punto 1.2)

Su Claude Desktop, invece, si aggiunge nel file di configurazione MCP con lo stesso URL e header.

## Aggiornamenti successivi

Come per lo stack principale: Portainer → Stacks → chifacosa-mcp → **Pull and redeploy**. Il
server non ha stato/database proprio (parla solo con l'API di CHIFACOSA), quindi un redeploy è
sempre sicuro, nessun dato da perdere.

## Sicurezza

- `MCP_ACCESS_TOKEN` protegge il server da chiunque altro su internet — senza, chiunque conosca
  l'URL potrebbe pubblicare contenuti a tuo nome.
- `CHIFACOSA_API_TOKEN` non viene mai comunicato a claude.ai: resta solo nella configurazione di
  questo stack, esattamente come una password di servizio.
- Se sospetti che uno dei due token sia stato compromesso: rigeneralo (il token CHIFACOSA da
  Dashboard → API → Revoca + Crea nuovo; il token MCP a piacere) e aggiorna la variabile
  d'ambiente dello stack.
