# Deploy del server MCP — stesso server Hetzner di CHIFACOSA

Server MCP remoto (Streamable HTTP) che collega Claude (claude.ai o Claude Desktop) a uno o
più profili CHIFACOSA, riusando l'API pubblica `/api/v1/social-posts/*` già esistente. Va sul
**stesso** server Hetzner, come stack Portainer separato — stessa Nginx Proxy Manager per
l'HTTPS, nessun hosting nuovo da pagare.

Se gestisci **più profili** sullo stesso account CHIFACOSA, un solo server/connector basta per
tutti: ogni strumento (crea/elenca/modifica/elimina post) chiede quale profilo usare, a patto
di aver configurato un token per ciascuno (vedi punto 1).

## 1) Genera i token prima di iniziare

1. **Un token CHIFACOSA per ogni profilo che vuoi gestire da Claude**: su
   `https://www.chifacosa.it` → login → passa al profilo che ti interessa (se ne gestisci più
   d'uno) → Dashboard → API → crea un token → copialo e segnati anche a quale profilo si
   riferisce. Ripeti per ogni profilo che vuoi poter usare (anche solo uno, se ti basta).
2. **Token di accesso al server MCP**: un valore a scelta tua, lungo e casuale (es.
   `openssl rand -hex 32` dal terminale) — è `MCP_ACCESS_TOKEN` più sotto, quello che incollerai
   in claude.ai quando aggiungi il connector. Non è legato a CHIFACOSA, lo inventi tu, **uno
   solo** anche se gestisci più profili (protegge il server, non un singolo profilo).

## 2) Creare lo Stack in Portainer

1. **Stacks** → **Add stack**
2. **Nome Stack**: `chifacosa-mcp`
3. **Repository**: Attiva "Git repository"
   - **Repository URL**: `https://github.com/posillipo/chifacosa.git`
   - **Repository ref**: `refs/heads/main`
   - **Compose path**: `mcp-server/docker-compose.yml`
4. **Environment variables** — una riga `NAME`/`TOKEN` per ciascun profilo, numerate in ordine
   a partire da 1 (il "nome" è solo un'etichetta comoda per te e per Claude, es. lo slug del
   profilo — non deve corrispondere a nulla di tecnico):

| Variabile | Valore |
|---|---|
| `MCP_ACCESS_TOKEN` | il token generato al punto 1.2 |
| `CHIFACOSA_PROFILE_1_NAME` | es. `bandmarione` (nome a piacere del primo profilo) |
| `CHIFACOSA_PROFILE_1_TOKEN` | il token CHIFACOSA di quel profilo (punto 1.1) |
| `CHIFACOSA_PROFILE_2_NAME` | es. `pizzerialacaraffa` (secondo profilo, se ne hai un altro) |
| `CHIFACOSA_PROFILE_2_TOKEN` | il token CHIFACOSA di quel secondo profilo |
| ... | continua con `_3_`, `_4_`, ecc. per altri profili |
| `CHIFACOSA_BASE_URL` | lascia vuoto per usare `https://www.chifacosa.it/api/v1/social-posts` (default) |

Se gestisci **un solo profilo** e vuoi la configurazione più semplice, puoi anche usare solo
`CHIFACOSA_API_TOKEN` (senza numerazione) invece della coppia `CHIFACOSA_PROFILE_1_NAME`/
`_1_TOKEN` — il server lo riconosce comunque, chiamando quel profilo "principale".

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
  l'URL potrebbe pubblicare contenuti a tuo nome, su qualunque profilo configurato.
- I token `CHIFACOSA_PROFILE_N_TOKEN` (o `CHIFACOSA_API_TOKEN`) non vengono mai comunicati a
  claude.ai: restano solo nella configurazione di questo stack, esattamente come una password
  di servizio. Ogni token resta comunque limitato al SUO profilo (stessa regola dell'API REST):
  anche se il server ne gestisce più d'uno, un profilo non può mai toccare i dati di un altro.
- Se sospetti che un token sia stato compromesso: rigeneralo (il token CHIFACOSA di quel
  profilo da Dashboard → API → Revoca + Crea nuovo; il token MCP a piacere, invalida però
  l'accesso per TUTTI i profili finché non aggiorni il connector) e aggiorna la variabile
  d'ambiente corrispondente dello stack.

## Nota sul token che vedi nell'elenco di Dashboard → API

Quella lista mostra sempre e solo un'**anteprima troncata** (finisce con "…"), mai il token
intero — è voluto, per sicurezza (nel database è salvato solo un hash, non il valore vero). Il
token completo lo vedi **una sola volta**, nel riquadro verde subito dopo averlo creato: copialo
da lì, non dall'elenco. Se lo hai perso, non è recuperabile: creane uno nuovo.
