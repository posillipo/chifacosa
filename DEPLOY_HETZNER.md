# CHIFACOSA — Deploy su Hetzner (Portainer + Nginx Proxy Manager)

## 📋 Prerequisiti

- ✅ Accesso a Portainer su Hetzner (https://hetzner-ip:9443)
- ✅ myBand già deployato (container `myband_db` e network `myband2_myband_net` esistono)
- ✅ Nginx Proxy Manager attivo
- ✅ Dominio `chifacosa.it` configurato con DNS verso Hetzner

---

## 🚀 Step 1: Push del codice a GitHub

Da Windows (PowerShell):

```powershell
# Clone repository (se non esiste già)
cd ~
git clone https://github.com/posillipo/chifacosa.git
cd chifacosa

# Verifica lo stato
git status

# Se ci sono modifiche locali, aggiungile
git add -A

# Commit con messaggio descrittivo
git commit -m "feat: Hetzner deploy - PHP-FPM, shared MySQL, Nginx Proxy Manager"

# Push a GitHub (branch main)
git push origin main
```

**Nota:** Se ottieni errore di autenticazione, assicurati di aver configurato SSH keys o GitHub token in PowerShell.

---

## 🐳 Step 2: Creare il nuovo Stack in Portainer

### 2.1 Accedi a Portainer

- URL: `https://tuoip-hetzner:9443`
- Vai a **Stacks** (barra sinistra)
- Clicca **Add Stack**

### 2.2 Compila il form

**Nome Stack:** `chifacosa`

**Repository:** Attiva "Git repository"
- **Repository URL:** `https://github.com/posillipo/chifacosa.git`
- **Repository ref:** `main`
- **Compose path:** `docker-compose.yml`
- **Auto update:** `On` (opzionale, per deploy automatico su ogni push)

### 2.3 Environment variables

Clicca su **Environment** e aggiungi:

| Variabile | Valore | Note |
|-----------|--------|------|
| `DB_PASSWORD` | `chifacosa_secure_123` | **Cambia con una password forte!** |
| `SITE_URL` | `https://chifacosa.it` | URL pubblico del sito |
| `SMTP_HOST` | (lascia vuoto per ora) | Configurare dopo se serve email |
| `SMTP_FROM_NAME` | `CHI FA COSA` | Nome mittente email |

### 2.4 Deploy

Clicca **Deploy the stack**

Portainer farà:
1. Clone del repository da GitHub
2. Build dell'immagine Docker (Apache)
3. Avvio del container `chifacosa_app` sul network `myband2_myband_net`
4. Collegamento al MySQL di myBand

**Tempo previsto:** 2-3 minuti

---

## 💾 Step 3: Inizializzare il Database

Una volta che il container `chifacosa_app` è up, importa lo schema SQL nel MySQL di myBand.

### 3.1 Accedi al container MySQL di myBand (via Portainer o SSH)

**Opzione A: Via Portainer** (più facile):
1. Vai su **Containers**
2. Clicca su `myband_db`
3. Clicca **Exec** (o il pulsante di terminale)
4. Esegui:

```bash
mysql -u root -p
# Immetti la password root di myBand
```

### 3.2 Crea l'utente e il database

```sql
-- Crea database chifacosa
CREATE DATABASE IF NOT EXISTS chifacosa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Crea utente chifacosa_user
CREATE USER IF NOT EXISTS 'chifacosa_user'@'%' IDENTIFIED BY 'chifacosa_secure_123';

-- Grant completi
GRANT ALL PRIVILEGES ON chifacosa.* TO 'chifacosa_user'@'%';
FLUSH PRIVILEGES;

-- Verifica
SHOW DATABASES;
SELECT User, Host FROM mysql.user WHERE User='chifacosa_user';

-- Esci
EXIT;
```

### 3.3 Importa lo schema SQL

Da Portainer o via SSH su Hetzner:

```bash
# Copia lo schema SQL nel container MySQL
docker cp /home/gianluca/docker/chifacosa/database/schema.sql myband_db:/tmp/schema.sql

# Importalo nel database
docker exec myband_db mysql -u root -p -e "SOURCE /tmp/schema.sql"
# Oppure, se hai la password root memorizzata:
docker exec -e MYSQL_PWD="your_root_password" myband_db mysql -u root < /tmp/schema.sql
```

### 3.4 Verifica

```bash
docker exec myband_db mysql -u chifacosa_user -p'chifacosa_secure_123' chifacosa -e "SHOW TABLES LIMIT 5;"
```

Dovrebbe mostrare la lista delle tabelle (users, profiles, links, etc.).

---

## 🌐 Step 4: Configurare Nginx Proxy Manager

Accedi all'interfaccia di Nginx Proxy Manager e crea una nuova entry:

### 4.1 Aggiungi un nuovo Proxy Host

1. Vai a **Nginx Proxy Manager** (porta 81 o panel)
2. **Proxy Hosts** → **Add Proxy Host**

### 4.2 Compila il form

**Domain Names:** `chifacosa.it` e `www.chifacosa.it`

**Scheme:** `http`

**Forward Hostname/IP:** `chifacosa_app`

**Forward Port:** `80` (il container gira su Apache, non PHP-FPM — la porta 9000 indicata in
versioni precedenti di questa guida era superata)

**Cache Assets:** ✓ (abilitato)

**Block Common Exploits:** ✓ (abilitato)

### 4.3 SSL Certificate

Vai al tab **SSL**:
- **SSL Certificate:** `Request a new SSL Certificate`
- **Email Address:** la tua email
- **Agree to Let's Encrypt:** ✓
- **Use a DNS challenge:** dipende dal tuo setup

Salva. Let's Encrypt genererà il certificato automaticamente.

### 4.4 Verifica

Vai a `https://chifacosa.it` nel browser. Dovrebbe mostrare l'app CHI FA COSA.

---

## ✅ Step 5: Verifiche Finali

```bash
# SSH in Hetzner
ssh gianluca@tuoip

# Verifica container
docker ps | grep chifacosa
# Dovrebbe mostrare: chifacosa_app running

# Verifica logs
docker logs -f chifacosa_app
# Dovrebbe mostrare i log di avvio di Apache ("apache2 -D FOREGROUND" o simile)

# Verifica connessione al DB
docker exec chifacosa_app php -r "require 'src/db.php'; echo 'DB connected'; getDB();"
# Dovrebbe stampare: DB connected

# Verifica rete
docker network inspect myband2_myband_net | grep -A 10 '"Containers"'
# Dovrebbe mostrare sia myband_db che chifacosa_app
```

---

## 🔄 Aggiornamenti Futuri

Se modifichi il codice su GitHub e pushhi a `main`:

**Opzione A: Auto-redeploy** (se hai abilitato "Auto update" in Portainer)
- Portainer tirerà automaticamente il nuovo codice

**Opzione B: Redeploy manuale**
1. Portainer → Stacks → chifacosa
2. Clicca **Pull and Redeploy**

---

## 🐛 Troubleshooting

### Container non parte
```bash
docker logs chifacosa_app
# Guarda i messaggi di errore
```

### Database connection error
```bash
# Verifica che myband_db sia raggiungibile
docker exec chifacosa_app ping myband_db
# Dovrebbe rispondere se il network è collegato
```

### Nginx Proxy Manager non fa forward
- Verifica che chifacosa_app sia sulla stessa rete (myband2_myband_net)
- Usa `chifacosa_app:80` come indirizzo (non IP)

### SSL certificate non si genera
- Verifica che il dominio sia raggiungibile via DNS
- Prova il DNS challenge anziché HTTP challenge

---

## 📝 Note

- **Password DB:** La password `chifacosa_secure_123` è di esempio. Cambia con una forte in produzione.
- **Risorse:** il container riusa la stessa istanza MySQL condivisa (`myband_db`), nessun database dedicato da gestire a parte.
- **Uploads:** La cartella `app/public/uploads` è mappata come volume per persistenza tra redeploy.
- **SMTP:** Se non hai configurato email, la maggior parte delle funzioni funzionerà comunque. Aggiungi SMTP solo se necessario.

---

**Fatto!** CHI FA COSA è live su `https://chifacosa.it`
