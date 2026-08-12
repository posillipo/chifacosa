# Testare CHI FA COSA in locale su WSL

Sì, puoi testare tutto in locale prima di metterlo sul server di produzione. WSL è già un
ambiente Linux, quindi i comandi sono identici a quelli che userai sul server.

## 1. Prerequisiti su WSL

Hai due strade, scegli quella più comoda:

**Opzione A — Docker Desktop per Windows con integrazione WSL2** (la più semplice)
1. Installa [Docker Desktop](https://www.docker.com/products/docker-desktop/) su Windows
2. In Docker Desktop: Settings → Resources → WSL Integration → attiva la tua distro (es. Ubuntu)
3. Apri il terminale WSL e verifica: `docker compose version`

**Opzione B — Docker Engine nativo dentro WSL** (senza Docker Desktop)
```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
# chiudi e riapri il terminale WSL, poi:
sudo service docker start
docker compose version
```

## 2. Estrai il progetto e avvialo

```bash
# copia/estrai lo zip nella tua home di WSL, es:
cd ~
unzip chifacosa-platform.zip
cd chifacosa

# crea il file .env con password (anche semplici, è solo test locale)
cp .env.example .env
nano .env   # imposta DB_PASSWORD e DB_ROOT_PASSWORD

# build e avvio
docker compose up -d --build

# controlla che i container siano su
docker compose ps
```

## 3. Apri il sito

Da Windows, apri il browser su:

```
http://localhost:8080
```

WSL2 inoltra automaticamente le porte verso Windows, quindi funziona senza configurazioni
aggiuntive. Registra un account di prova e verifica che la pagina pubblica funzioni su:

```
http://localhost:8080/nomepagina-scelta
```

(Il rewrite `.htaccess` funziona in base al percorso, non al dominio, quindi in locale con
`localhost:8080` si comporta esattamente come sul tuo dominio in produzione.)

## 4. Cose da controllare durante il test

- [ ] Registrazione nuovo utente e login
- [ ] Upload foto profilo (dashboard → Profilo)
- [ ] Aggiunta/rimozione link e verifica redirect + conteggio click (`/link.php?id=...`)
- [ ] Upload di un brano audio e riproduzione nel player
- [ ] Creazione di un evento/concerto futuro
- [ ] Pubblicazione di un post sul blog
- [ ] Invio di un messaggio dal form di contatto della pagina pubblica → verifica che compaia
      in dashboard → Contatti

## 5. Comandi utili durante il test

```bash
docker compose logs -f app          # log applicazione in tempo reale
docker compose logs -f db           # log database
docker compose exec db mysql -u chifacosa_user -p chifacosa   # accesso diretto al DB per ispezionare i dati
docker compose down                 # ferma tutto
docker compose down -v              # ferma tutto E CANCELLA il database (riparti da zero)
docker compose up -d --build        # rebuild dopo aver modificato il codice PHP
```

> Nota: modificando i file PHP dentro `app/public/`, dopo aver rilanciato `docker compose up -d --build`
> le modifiche saranno visibili. Se vuoi vedere le modifiche istantaneamente senza rebuild ogni volta,
> possiamo aggiungere un bind mount di sviluppo — fammelo sapere.

## 6. Quando sei soddisfatto del test

Una volta verificato che tutto funziona in locale:
1. `docker compose down -v` per ripulire il database di test
2. Trasferisci la cartella `chifacosa/` sul server Linux di produzione
3. Segui le istruzioni in `README.md` per il collegamento al tuo dominio e HTTPS
