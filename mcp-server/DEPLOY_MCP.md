# Deploy del server MCP — stesso server Hetzner di CHIFACOSA

Server MCP remoto (Streamable HTTP) che collega Claude (claude.ai o Claude Desktop) a uno o
più profili CHIFACOSA, riusando l'API pubblica `/api/v1/social-posts/*` già esistente. Va sul
**stesso** server Hetzner, come stack Portainer separato — stessa Nginx Proxy Manager per
l'HTTPS, nessun hosting nuovo da pagare.

Se gestisci **più profili** sullo stesso account CHIFACOSA, un solo server/connector basta per
tutti: ogni strumento (crea/elenca/modifica/elimina post) chiede quale profilo usare. I profili
si registrano **dopo** il deploy, con una chiamata diretta (mai attraverso Claude — vedi punto
5) — aggiungerne uno nuovo in futuro non richiede un altro deploy né toccare le variabili
d'ambiente dello stack.

## 1) Genera il token di accesso al server MCP prima di iniziare

Un valore a scelta tua, lungo e casuale (es. `openssl rand -hex 32` dal terminale) — è
`MCP_ACCESS_TOKEN` più sotto: quello che incollerai in claude.ai quando aggiungi il connector,
e lo stesso che userai da terminale per registrare i profili. Non è legato a CHIFACOSA, lo
inventi tu, **uno solo** anche se gestirai più profili (protegge il server, non un profilo
specifico).

I token dei singoli profili CHIFACOSA (da Dashboard → API) li generi **dopo**, uno alla volta,
solo quando registri ciascun profilo al punto 4 — non servono ancora adesso.

## 2) Creare lo Stack in Portainer

1. **Stacks** → **Add stack**
2. **Nome Stack**: `chifacosa-mcp`
3. **Repository**: Attiva "Git repository"
   - **Repository URL**: `https://github.com/posillipo/chifacosa.git`
   - **Repository ref**: `refs/heads/main`
   - **Compose path**: `mcp-server/docker-compose.yml`
4. **Environment variables** — una sola, per questo e per sempre (i profili non ne aggiungono
   altre):

| Variabile | Valore |
|---|---|
| `MCP_ACCESS_TOKEN` | il token generato al punto 1 |

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

## 5) Registrare un profilo (e aggiungerne altri quando vuoi, senza redeploy)

Per ogni profilo che vuoi collegare: prima genera il suo token su
`https://www.chifacosa.it` → login → passa a quel profilo → Dashboard → API → crea un token →
copialo **dal riquadro verde**, subito dopo averlo creato (vedi nota in fondo). Poi, da un
terminale qualsiasi (**mai chiedendo a Claude di farlo** — il token CHIFACOSA non deve mai
passare per la chat):

```bash
curl -X POST https://mcp.chifacosa.it/admin/profiles \
  -H "Authorization: Bearer IL_TUO_MCP_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "bandmarione", "token": "IL_TOKEN_CHIFACOSA_DI_QUESTO_PROFILO"}'
```

`name` è un'etichetta a tua scelta (es. lo slug del profilo) — è quella che userai in chat
("pubblica su bandmarione...") e quella che Claude vede tramite lo strumento
`list_chifacosa_profiles`. Ripeti il comando (cambiando `name` e `token`) per ogni altro
profilo — **nessun redeploy necessario**, il profilo è utilizzabile immediatamente.

Altri comandi utili:
```bash
# Elenca i profili registrati (solo i nomi, mai i token)
curl https://mcp.chifacosa.it/admin/profiles -H "Authorization: Bearer IL_TUO_MCP_ACCESS_TOKEN"

# Rimuove un profilo
curl -X DELETE https://mcp.chifacosa.it/admin/profiles/bandmarione -H "Authorization: Bearer IL_TUO_MCP_ACCESS_TOKEN"
```

## 6) Aggiungere il connector su claude.ai

Impostazioni → Connectors → **Add custom connector**:
- **URL**: `https://mcp.chifacosa.it/mcp`
- **Autenticazione**: scegli **"Nessun accesso"** (server con chiave API, non OAuth), poi
  aggiungi un header di richiesta:
  - Nome: `Authorization`
  - Valore: `Bearer IL_TUO_MCP_ACCESS_TOKEN` (quello del punto 1)

Su Claude Desktop, invece, si aggiunge nel file di configurazione MCP con lo stesso URL e header.

## Aggiornamenti successivi

Come per lo stack principale: Portainer → Stacks → chifacosa-mcp → **Pull and redeploy**. I
profili registrati vivono su un volume Docker dedicato (`mcp_data`), separato dal checkout Git
dello stack — un redeploy (anche per aggiornare il codice del server) non li tocca né li perde,
esattamente come `uploads_data` per lo stack principale.

## Sicurezza

- `MCP_ACCESS_TOKEN` protegge sia gli strumenti MCP sia gli endpoint `/admin/profiles` da
  chiunque altro su internet — senza, chiunque conosca l'URL potrebbe registrare un profilo
  proprio o pubblicare contenuti a tuo nome su uno di quelli già configurati.
- I token CHIFACOSA dei singoli profili non vengono mai comunicati a claude.ai: restano solo
  nel file `profiles.json` sul volume `mcp_data`, esattamente come una password di servizio.
  Ogni token resta comunque limitato al SUO profilo (stessa regola dell'API REST): anche se il
  server ne gestisce più d'uno, un profilo non può mai toccare i dati di un altro.
- Se sospetti che `MCP_ACCESS_TOKEN` sia stato compromesso, ruotarlo invalida l'accesso a
  *tutti* i profili finché non aggiorni sia lo stack sia il connector su claude.ai — è l'unico
  segreto davvero "master" di questo server.
- Se sospetti che il token CHIFACOSA di un singolo profilo sia stato compromesso: rigeneralo da
  Dashboard → API (Revoca + Crea nuovo) e ri-registralo con lo stesso comando `POST
  /admin/profiles` del punto 5 (sovrascrive quello vecchio).

## Nota sul token che vedi nell'elenco di Dashboard → API

Quella lista mostra sempre e solo un'**anteprima troncata** (finisce con "…"), mai il token
intero — è voluto, per sicurezza (nel database è salvato solo un hash, non il valore vero). Il
token completo lo vedi **una sola volta**, nel riquadro verde subito dopo averlo creato: copialo
da lì, non dall'elenco. Se lo hai perso, non è recuperabile: creane uno nuovo.
