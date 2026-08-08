# CHI FA COSA — Piattaforma Link in Bio per musicisti

Piattaforma multi-utente dove ogni musicista (band manager) può registrarsi e creare la propria
pagina pubblica (`tuodominio.it/nomepagina`) con link, brani audio, calendario concerti, blog e form
di contatto/booking.

Stack: **PHP 8.2 + Apache** (container `app`) + **MySQL 8** (container `db`), orchestrati con
Docker Compose. In produzione gestito con **Portainer** + **Nginx Proxy Manager** su Hetzner.

## Documentazione

- **`LOCAL_TESTING.md`** — testare il progetto in locale su WSL/Docker
- **`GITHUB.md`** — push da Windows, autenticazione, alternative senza Git
- **`DEPLOY.md`** — deploy e aggiornamento in produzione (Hetzner/Portainer/Nginx Proxy Manager),
  troubleshooting dei problemi più comuni
- **`ADMIN_SETUP.md`** — diventare admin, recuperare password DB, verificare account, elevare
  altri utenti
- **`SMTP.md`** — configurare le notifiche email, interpretare gli errori
- **`MIGRAZIONI.md`** — registro cronologico di tutte le modifiche allo schema database
- **`SCHEMA_DATABASE.md`** — struttura completa delle tabelle

## 1. Struttura del progetto

```
chifacosa/
├── docker-compose.yml
├── .env.example
├── app/
│   ├── Dockerfile
│   ├── public/        ← document root Apache (PHP + assets + upload)
│   └── src/            ← helper PHP (db.php, functions.php)
└── database/
    └── schema.sql       ← creato automaticamente al primo avvio di MySQL
```

## 2. Prerequisiti sul server Linux

- Docker Engine + plugin Docker Compose (`docker compose version` deve funzionare)
- Il tuo dominio (impostato durante il wizard di installazione) con i record DNS A/AAAA che
  puntano all'IP del server
- Porta 80/443 libere (o un reverse proxy che le gestisce, vedi punto 5)

## 3. Primo avvio

```bash
# 1. Copia il progetto sul server, ad es. in /opt/chifacosa
cd /opt/chifacosa

# 2. Crea il file .env con password reali
cp .env.example .env
nano .env   # imposta DB_PASSWORD e DB_ROOT_PASSWORD con password forti

# 3. Build e avvio dei container
docker compose up -d --build

# 4. Verifica che tutto sia attivo
docker compose ps
docker compose logs -f app
```

Al primo avvio MySQL importa automaticamente `database/schema.sql` e crea tutte le tabelle.

L'app sarà raggiungibile su `http://IP-DEL-SERVER:8080` (porta interna mappata nel `docker-compose.yml`).

## 4. Collegare il dominio

Il container espone la porta **8080** sull'host. Per servire il sito sul tuo dominio (es.
`https://tuodominio.it`) hai due opzioni:

**Opzione A — Nginx/Apache già presente sul server come reverse proxy** (consigliata se il server
ospita anche altri siti, come sembra essere il tuo caso):

```nginx
server {
    listen 80;
    server_name tuodominio.it www.tuodominio.it;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Poi attiva il certificato SSL con Certbot:
```bash
sudo certbot --nginx -d tuodominio.it -d www.tuodominio.it
```

**Opzione B — Traefik come reverse proxy Docker** (se preferisci gestire tutto via container),
posso preparare la configurazione Traefik con Let's Encrypt automatico: basta chiedermelo.

## 5. Comandi utili

```bash
docker compose logs -f app        # log applicazione
docker compose logs -f db         # log database
docker compose exec db mysql -u chifacosa_user -p chifacosa   # accesso diretto al DB
docker compose down               # ferma i container (i dati restano nel volume db_data)
docker compose up -d --build      # rebuild dopo modifiche al codice
```

## 6. Backup del database

```bash
docker compose exec db mysqldump -u root -p chifacosa > backup_$(date +%F).sql
```

## 7. Note di sicurezza già incluse

- Password utenti hashate con `password_hash` (bcrypt)
- Query parametrizzate (PDO prepared statements) contro SQL injection
- Token CSRF su tutti i form POST
- Validazione dei file caricati (estensioni ammesse per immagini e audio)
- Slug riservati (`login`, `dashboard`, ecc.) per evitare collisioni di route

## 8. Possibili estensioni future

- **Ordinamento "Band che amo" per data di aggiunta**: oggi l'elenco (sia sulla pagina pubblica
  sia nella dashboard) non è ordinato per data — da mettere le band aggiunte più di recente
  in cima, sia lato pubblico sia in dashboard (query già ha `created_at` disponibile in
  `fan_favorite_bands`, basterebbe cambiare l'ordinamento da `sort_order` a
  `created_at DESC` nelle pagine coinvolte: `u.php`, `band_che_amo.php`,
  `dashboard_fan_bands.php`)
- **CHI FA COSA come prodotto installabile/clonabile** (progetto a sé, valutato ma non iniziato):
  wizard di primo avvio stile WordPress (`install.php`: verifica DB → applica schema →
  crea primo admin → nome/dominio sito), genericizzazione di ogni riferimento hardcoded a
  "myband.it" nel codice, separazione tra nucleo generico e strumenti specifici della
  migrazione da my-band.it (import Access, avatar storici, percorso-come-slug, import vecchia
  timeline — questi restano solo per l'installazione attuale, un nuovo clone parte a dati
  zero). Richiede anche di mantenere `schema.sql` sempre perfettamente allineato a tutte le
  migrazioni, come unica fonte di verità per un'installazione pulita.
- **Rinomina dei tab con prefisso "my___"** (in riflessione, non ancora decisa): idea di
  branding proposta per giocare sul nome del dominio — Timeline → myNews, Brani → myTracks,
  Band che amo → myFavs, Eventi → myEvents, Blog → myBlog, Follower → myFollowers. Confermati
  finora solo myTracks, myFavs, myFollowers; gli altri tre restano da confermare. Da NON
  usare "myBand" per "Band che amo" — collide col nome della piattaforma stessa.
- **Classifica settimanale/mensile dei brani**: richiede un sistema di tracciamento nel tempo
  (oggi non esiste), da progettare a parte quando si vorrà affrontarla — collegata al sistema
  di recensioni appena costruito, potrebbe basarsi sui voti ricevuti in un dato periodo
- **Sistema di cache** (come nei plugin cache di WordPress): OPcache PHP come primo passo a
  basso rischio (solo configurazione), poi eventuale cache di pagina intera per i visitatori
  anonimi (mai per gli utenti loggati, che devono vedere sempre lo stato aggiornato) — il
  server ha già Redis attivo per gli altri siti, riusabile per CHI FA COSA senza installare nulla
  di nuovo
- **Formattazione email uniforme**: tutte le email inviate dal sistema (verifica registrazione,
  notifiche contatto, conferma "Segui", reset password, notifiche follower/Timeline) sono oggi
  testo semplice — andrebbero uniformate con un template HTML coerente, vicino ai colori/stile
  di CHI FA COSA (accento viola `#6C5CE7`, coerente col resto della piattaforma), invece dei
  messaggi in solo testo attuali
- Statistiche dettagliate sui click (per link, per periodo)
- Piano gratuito/premium con limiti su numero di brani/eventi
- Invio email automatico (SMTP) quando arriva una richiesta di contatto
- **Formazione della band** (componenti con nome, ruolo/strumento, foto) — funzionalità
  identificata come "fondamentale per una band" e pianificata, da realizzare con un **sistema di
  invito**: ogni componente riceve un invito via email per gestire/confermare il proprio profilo
  all'interno della band, invece di essere inserito manualmente dal band manager. Nel vecchio
  myband.it esisteva un concetto analogo (tabella `musicisti` collegata alla band tramite
  `id_band`)
- **Footer pagina pubblica, ispirato all'analisi di un esempio di pagina Link in Bio**: badge "CHI FA COSA/tu"
  chiudibile con una X (oggi non è possibile nasconderlo); sfondo del blocco finale a
  **dissolvenza graduale** (dal colore della pagina verso uno scuro) invece dell'attuale blocco
  di colore pieno, per un aspetto più morbido e integrato con il resto della pagina
- **Multi-profilo per Etichette Discografiche**: un account etichetta che gestisce più profili
  band contemporaneamente — stesso bisogno dei 53 gestori multi-band rimasti fuori dall'import
  legacy; ristrutturazione architetturale importante, mai approfondita nel dettaglio
- **Directory pubblica degli artisti** (es. tuodominio.it/artisti): elenco/vetrina di tutti i
  profili pubblici, possibile leva per l'effetto rete del sistema Segui, mai costruita
- **Servizio a tema Sanremo 2027**: idee emerse — microsito "Verso Sanremo 2027", contest a
  voti tra artisti, playlist collettiva Spotify, pacchetto PR (sfruttando il ruolo di
  giornalista), gioco pronostici per i fan (arrivato a una struttura tecnica dettagliata:
  edizione/domande/risposte/punteggio/classifica, ma non implementato)
- **Contest a voti tra band** (ripresa del vecchio concorso di my-band.it, 264 partecipanti
  storici): funzionalità a sé, indipendente da Sanremo, con precedente storico dimostrato

## 9. Decisioni di prodotto scartate

**Login con Spotify + rilevamento automatico del profilo artista** (valutato e scartato):
- Non esiste nessuna API pubblica Spotify che colleghi un account personale (da ascoltatore,
  usato per il login OAuth) al relativo profilo Artista — sono due sistemi verificati
  separatamente da Spotify, e questo legame non è esposto agli sviluppatori terzi. L'automatismo
  richiesto ("logout con Spotify → riconosce l'artista") non è quindi realizzabile
- Il login OAuth con Spotify da solo sarebbe fattibile, ma le nuove app partono limitate a 25
  utenti totali ("Development Mode"); sbloccare l'uso pubblico richiede una richiesta di
  approvazione a Spotify ("Extended Quota") con tempi ed esito non garantiti
- Alternativa valutata ma non implementata: ricerca/collegamento manuale del profilo artista
  Spotify da parte del band manager (senza bisogno di OAuth utente, solo API di catalogo
  pubblico) + pagina dedicata con la discografia. Tecnicamente più semplice e senza il problema
  di quota, ma la richiesta complessiva è stata scartata prima di procedere allo sviluppo

Se in futuro si torna a valutare questa funzionalità, l'analisi completa resta valida come
punto di partenza — evita di rifare da zero la ricerca sui limiti dell'API Spotify.

**Recupero dei "msgdiretto" della vecchia tabella `timeline`** (scartato): i messaggi diretti
(1.015 su 4.241 post totali) sono per lo più comunicazioni private tra lo staff del vecchio
myband.it e i singoli band manager (reset credenziali, avvisi tecnici) — dati sensibili dal
punto di vista privacy, giudicati irrilevanti da recuperare nel nuovo sistema. Se in futuro si
valuta un recupero parziale della vecchia timeline (foto, video, link, mp3 pubblicati dalle
band), i messaggi diretti restano esclusi per principio.

## 10. Principio guida per le integrazioni esterne: coerenza grafica

**CHI FA COSA è un "Link in Bio musicale"**: ogni integrazione con un servizio esterno deve rispettare
lo stesso linguaggio visivo della piattaforma (card arrotondate, palette coerente, copertine
quadrate), non introdurre widget/player "estranei" con lo stile grafico del servizio di origine.

Criterio pratico da applicare a ogni nuova integrazione, in ordine di preferenza:
1. **Solo dati** (titolo, immagine, link, testo) renderizzati con lo stile già esistente di
   CHI FA COSA → via preferita, come già fatto per Spotify (Music e Podcast)
2. **Player nativo necessario** solo se serve davvero riprodurre audio/video e non esiste
   alternativa (es. YouTube) → eccezione accettabile, minimizzando l'ingombro visivo attorno
3. **Solo un widget precostituito con lo stile del servizio esterno** (es. l'oEmbed di
   SoundCloud, con il suo player arancione/nero riconoscibile) → da evitare; se non c'è
   un'alternativa "a soli dati", meglio non integrare il servizio piuttosto che rompere la
   coerenza visiva

Esempio applicato: l'integrazione SoundCloud è stata valutata e scartata nella forma "player
oEmbed incorporato" proprio per questo motivo (richiederebbe l'API a pagamento per accedere solo
ai dati, l'alternativa gratuita è solo il widget con lo stile SoundCloud).

