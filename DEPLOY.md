# Deploy in produzione — Hetzner + Portainer + Nginx Proxy Manager

Ambiente reale: server Hetzner gestito con **Portainer** (orchestrazione container) e
**Nginx Proxy Manager** (reverse proxy + SSL), stack collegato al repository GitHub
`posillipo/chifacosa`.

## Setup iniziale del server (una tantum, se il server è nuovo)

```bash
ssh root@IP_DEL_SERVER
apt update && apt upgrade -y
adduser gianluca && usermod -aG sudo gianluca
apt install -y ufw
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp && ufw enable
```
Poi Docker: `curl -fsSL https://get.docker.com | sudo sh` e `sudo usermod -aG docker $USER`
(disconnetti e riconnetti via SSH perché il gruppo abbia effetto).

## Creare/aggiornare lo Stack in Portainer

1. **Stacks** → **Add stack** (o apri lo stack `chifacosa` esistente)
2. **Build method**: Repository
3. **Repository URL**: `https://github.com/posillipo/chifacosa.git`
4. **Repository reference**: `refs/heads/main`
5. **Compose path**: `docker-compose.yml`
6. **Environment variables**: `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `SITE_URL` (niente file `.env`
   da gestire, Portainer le inietta direttamente)
7. **Deploy the stack**

### Se "Deploy the stack" non si attiva
Controlla in ordine: Repository reference compilato (`refs/heads/main`), Compose path esatto
(`docker-compose.yml`), se il toggle Authentication è attivo assicurati che Username/Token siano
compilati (o disattivalo se il repo è pubblico), e che ogni riga delle Environment variables
abbia sia nome che valore.

### Se il clone fallisce con errore di autenticazione
Il repo è privato e serve un token: GitHub → Settings → Developer settings → **Personal access
tokens (classic)** (non "Fine-grained", danno più problemi di permessi) → scope `repo`. In
alternativa, la soluzione più semplice: rendi il repository pubblico (vedi `GITHUB.md`) e
disattiva il toggle Authentication in Portainer.

## Configurare il Proxy Host in Nginx Proxy Manager

1. Apri Nginx Proxy Manager (porta 81)
2. **Proxy Hosts** → cerca il tuo dominio (se esiste già un host per un vecchio progetto su
   quel dominio, **modificalo** invece di crearne uno nuovo, altrimenti "dominio già in uso")
3. **Forward Hostname/IP**: lo stesso valore già usato per gli altri tuoi Proxy Host
4. **Forward Port**: la porta pubblicata dal container `app` nel `docker-compose.yml`
   (attualmente `8085`, verificalo nel file se cambia)
5. Tab **SSL**: richiedi certificato Let's Encrypt, Force SSL

## DNS

```
Tipo A   Nome: @      Valore: IP_DEL_SERVER
Tipo A   Nome: www    Valore: IP_DEL_SERVER
```

## Diagnosi problemi comuni

**Container attivo ma il sito non risponde**: testa dal server `curl -I http://127.0.0.1:8085`
(o la porta attuale). 200 OK → problema nel Proxy Host o DNS. Connection refused → il container
non risponde, controlla i log. Errore 500 → probabile problema di connessione al database.

**Errore PDO "getaddrinfo for db failed"**: il container `app` non risolve il nome del servizio
`db` sulla rete Docker interna. Verifica in Portainer → Containers che `myband_db` sia
"Running" (non "Restarting"), prova un riavvio del container `app`, e controlla in Networks che
entrambi i container risultino sulla stessa rete dello stack. Se persiste, verifica che
`DB_HOST` nel `docker-compose.yml` corrisponda al `container_name` esatto del servizio db.

**Errore 500 solo su un'azione specifica (es. salvare un nuovo link), mentre la lettura della
pagina funziona**: quasi sempre significa che manca una migrazione SQL. Le pagine che leggono
dati (`SELECT *`) non falliscono solo perché una colonna attesa dal codice non esiste ancora;
le query che scrivono (`INSERT`/`UPDATE`) nominando quella colonna esplicitamente invece
falliscono sempre. Controlla `MIGRAZIONI.md` per vedere se c'è un comando SQL in sospeso non
ancora eseguito dopo l'ultimo redeploy.

**Upload foto/audio fallisce** ("move_uploaded_file... No such file or directory"): il
Dockerfile include un entrypoint (`docker-entrypoint-wrapper.sh`) che sistema automaticamente
permessi e cartelle di `uploads/` ad ogni avvio — se il problema si ripresenta dopo un
redeploy, verifica che quello script sia ancora presente e referenziato nel Dockerfile.

## Aggiornamenti successivi

**Codice**: Portainer → Stacks → chifacosa → **Pull and redeploy**. Non tocca mai il database
(volume separato) né i file caricati dagli utenti (volume `uploads/` separato).

**Modifiche allo schema del database**: git non le applica da solo. Ogni volta che una modifica
tocca lo schema, il comando SQL esatto da lanciare è raccolto in `MIGRAZIONI.md` — vanno
eseguiti manualmente via Portainer → Containers → `myband_db` → Console →
`mysql -u chifacosa_user -p chifacosa` (per la password, vedi `ADMIN_SETUP.md`).

**Backup database**: `mysqldump -u root -p chifacosa > backup_$(date +%F).sql` dalla console del
container `myband_db`.
