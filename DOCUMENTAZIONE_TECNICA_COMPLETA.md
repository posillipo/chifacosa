# CHI FA COSA — Documentazione tecnica completa del progetto

Piattaforma multi-utente in stile "Link in Bio", nata per band e musicisti ma pensata per
qualsiasi tipo di attività o profilo (locali, ristoranti, professionisti, fan), con area pubblica
per ogni profilo, dashboard di gestione contenuti, area di amministrazione, e integrazioni con
servizi esterni (email transazionali, Spotify, tracking).

---

## 1. Architettura e stack tecnologico

| Componente | Tecnologia |
|---|---|
| Linguaggio backend | PHP 8.2 (procedurale, nessun framework — scelta deliberata per semplicità e trasparenza) |
| Web server | Apache 2 (immagine ufficiale `php:8.2-apache`) |
| Database | MySQL 8.0 |
| Frontend | HTML + CSS custom (nessun framework CSS nelle pagine pubbliche/dashboard) + Bootstrap 4/AdminLTE 3 solo nell'Area Admin |
| Icone | Font Awesome 6 (CDN) |
| Containerizzazione | Docker + Docker Compose (2 servizi: `app` e `db`) |
| Orchestrazione in produzione | Portainer (deploy diretto da repository Git) |
| Reverse proxy / SSL | Nginx Proxy Manager (Let's Encrypt automatico) |
| Versionamento | Git, repository GitHub `posillipo/chifacosa` |
| Server di produzione | Hetzner Cloud (Ubuntu), condiviso con altri progetti dell'utente (WordPress, ecc.) |

Nessuna dipendenza esterna via Composer/npm: sia il client SMTP (`app/src/mailer.php`) sia il
client Spotify (`app/src/spotify.php`) sono scritti da zero usando solo le funzioni native di
PHP (`stream_socket_client`, `file_get_contents` con stream context), per evitare di dover
gestire un processo di build nel Dockerfile.

---

## 2. Infrastruttura Docker

**`docker-compose.yml`** definisce due servizi su una rete Docker dedicata:

- **`app`** (container `chifacosa_app`): build da `app/Dockerfile`, espone la porta 8085 sull'host,
  monta come volume la cartella `uploads/` (foto profilo e brani audio) per la persistenza dei
  file caricati dagli utenti
- **`db`** (container `myband_db`): immagine ufficiale `mysql:8.0`, volume persistente per i
  dati, inizializza lo schema da `database/schema.sql` al primo avvio (se il volume è vuoto)

**`app/Dockerfile`** (basato su `php:8.2-apache`):
- Estensioni PHP installate: `pdo`, `pdo_mysql`, `mysqli`
- `mod_rewrite` e `mod_headers` abilitati
- `AllowOverride All` per il supporto a `.htaccess`
- Limiti di upload configurati (`upload_max_filesize=20M`, `post_max_size=25M`) per i brani audio
- **Configurazione errori di produzione**: `display_errors=Off`, `log_errors=On`,
  `error_log=/dev/stderr` — nessun warning/errore PHP viene mai mostrato ai visitatori, tutto
  finisce nei log del container (visibili da Portainer)
- **Entrypoint personalizzato** (`docker-entrypoint-wrapper.sh`): ad ogni avvio del container,
  ricrea le cartelle `uploads/images` e `uploads/audio` e ne corregge i permessi per `www-data`,
  risolvendo alla radice un problema riscontrato in produzione dove il bind mount Docker non
  garantiva permessi coerenti

Variabili d'ambiente iniettate da Portainer (Stack "chifacosa"): `DB_PASSWORD`, `DB_ROOT_PASSWORD`,
`SITE_URL`. Le credenziali di servizi esterni (SMTP, Spotify) **non** passano più da variabili
d'ambiente: sono gestite interamente dall'interfaccia di amministrazione e salvate cifrate nel
database (vedi sezione Area Admin).

---

## 3. Schema del database (10 tabelle)

| Tabella | Scopo | Colonne chiave |
|---|---|---|
| `users` | Account (profili + admin) | `slug` (univoco, pagina pubblica), `email`, `password_hash` (bcrypt), `is_active`, `is_admin`, `email_verified`, `verification_token`/`verification_expires` |
| `profiles` | Dati profilo pubblico, 1:1 con `users` | `display_name`, `bio`, `avatar_path`, `theme_color`, `dashboard_theme` (non più usato in UI), `spotify_artist_id`/`spotify_artist_name` |
| `links` | Link della pagina Link in Bio | `label`, `url`, `sort_order` (riordinabile), `click_count`, `is_active`, `is_website_icon` (flag manuale per l'icona "sito web personale") |
| `audio_tracks` | Brani audio caricati | `title`, `file_path`, `sort_order` |
| `events` | Concerti/date in calendario | `title`, `venue`, `city`, `event_date`, `ticket_url` |
| `blog_posts` | Articoli del blog | `title`, `slug` (univoco per utente, permalink SEO), `excerpt`, `content`, `published_at` |
| `contact_requests` | Messaggi dal form contatti/booking | `sender_name`, `sender_email`, `message`, `is_read` |
| `site_settings` | Impostazioni globali chiave/valore | script privacy, GTM/Pixel, credenziali SMTP, credenziali Spotify, URL privacy policy |
| `remember_tokens` | Login persistente "ricordami" | `selector` (in chiaro), `validator_hash` (mai in chiaro), `expires_at` — pattern standard selector/validator |
| `followers` | Sistema "Segui via email" | `email`, `verified`, `token` (doppio uso: conferma iscrizione + disiscrizione) |

Tutte le tabelle collegate a `users` hanno `ON DELETE CASCADE`: eliminare un account elimina
automaticamente profilo, link, brani, eventi, articoli, contatti e follower collegati.

---

## 4. Sito pubblico di ogni profilo

Ogni utente ha una pagina pubblica raggiungibile su `tuodominio.it/nomeslug`, con routing gestito
via `.htaccess` (mod_rewrite) verso i seguenti script:

| URL | Script | Contenuto |
|---|---|---|
| `/slug` | `u.php` | Home artista: avatar, bio (a comparsa al passaggio del mouse), icone social ufficiali, pulsanti link colorati, form "Segui via email" |
| `/slug/blog` | `blog_index.php` | Elenco articoli |
| `/slug/blog/anno.mese.giorno.slug-articolo` | `blog_post.php` | Singolo articolo, permalink SEO-friendly |
| `/slug/brani` | `brani.php` | Brani audio caricati (player HTML5) |
| `/slug/eventi` | `eventi.php` | Prossimi concerti |
| `/slug/contatti` | `contatti.php` | Form di contatto/booking |
| `/slug/spotify` | `artist_spotify.php` | Discografia Spotify (solo se collegata) |

**Header condiviso** (`publicProfileHeader()` in `functions.php`): avatar, nome, menu di
navigazione identico su tutte le pagine, con la voce attiva evidenziata in bianco. Il tab
"Spotify" compare solo se l'artista ha collegato un profilo.

**Icone social**: rilevamento automatico della piattaforma da dominio URL (Spotify, Apple Music,
Instagram, Facebook, TikTok, YouTube, LinkedIn, SoundCloud, WhatsApp) tramite
`detectPlatform()`/`splitSocialAndActionLinks()`. Solo la prima occorrenza di ciascuna
piattaforma diventa icona (nell'ordine in cui il band manager dispone i link); i duplicati
restano pulsanti normali. Un flag manuale (`is_website_icon`) permette di forzare un link
qualsiasi come icona "sito web personale" (dominio non riconoscibile automaticamente).

**Tema visivo "colorful"**: sfondo sfumato pastello, pulsanti arrotondati con palette di 8 colori
a rotazione, ispirato al layout del tema WordPress "Meeek".

**Bio come vignetta**: passando il mouse sull'avatar compare un fumetto con la biografia (CSS
puro, nessun JavaScript).

**Barra fissa "Unisciti a CHI FA COSA"**: sempre visibile in fondo a ogni pagina pubblica, con
`position: fixed`, invita alla registrazione di nuovi band manager.

**Footer**: link orizzontali "Preferenze Cookie" (riapre il pannello CookieYes tramite la classe
`cky-banner-element`) · "Privacy" (URL configurabile da admin) · "CHI FA COSA" (torna alla home).

**Open Graph / Twitter Card** su tutte le pagine per una condivisione social corretta.

**Cache-busting automatico**: il CSS viene caricato con un parametro di versione basato sulla
data di modifica del file (`assetUrl()`), così un aggiornamento del CSS si riflette subito senza
richiedere lo svuotamento manuale della cache del browser.

---

## 5. Dashboard band manager (utente registrato)

Layout: navbar + tab orizzontali (stile originale, non AdminLTE — l'unificazione grafica con
l'Area Admin era stata valutata e poi annullata su richiesta esplicita). Tema forzato "chiaro"
(la scelta scuro/chiaro è stata rimossa dall'interfaccia).

| Pagina | Funzione |
|---|---|
| `dashboard_profile.php` | Nome, bio, foto profilo, colore tema pagina pubblica |
| `dashboard_links.php` | Gestione link: aggiungi, **modifica** (nuovo), riordina (frecce ▲▼ con pulsanti a sola icona), mostra/nascondi, elimina, flag "sito web personale" |
| `dashboard_audio.php` | Upload/gestione brani audio |
| `dashboard_events.php` | Gestione concerti |
| `dashboard_blog.php` | Editor articoli (genera slug SEO automaticamente, notifica i follower alla pubblicazione) |
| `dashboard_contacts.php` | Richieste di contatto ricevute |
| `dashboard_spotify.php` | Ricerca e collegamento manuale del proprio profilo Spotify |
| `dashboard_followers.php` | Conteggio e elenco follower, crescita 7/30 giorni |

I pulsanti azione (sposta su/giù, modifica, mostra/nascondi, elimina) usano icone Font Awesome
(`.icon-btn`), non testo — corretto un bug per cui il tab attivo nel menu diventava illeggibile
(bianco su bianco) nel tema chiaro.

---

## 6. Area di amministrazione (`is_admin = 1`)

Layout **AdminLTE 3** (Bootstrap 4) via CDN, sidebar laterale con 7 sezioni:

| Pagina | Funzione |
|---|---|
| `admin_dashboard.php` | Statistiche generali: utenti totali/attivi/disattivati/da verificare, nuove iscrizioni 7/30gg, contenuti totali, contatti ricevuti |
| `admin_users.php` | Elenco utenti con filtri (nome/email/slug, stato), attiva/disattiva, **rendi/rimuovi admin**, verifica email manuale, elimina account |
| `admin_user_edit.php` | Modifica dati utente (nome, email, slug) |
| `admin_user_detail.php` | Dettaglio utente + moderazione contenuti (elimina singolarmente link/brani/eventi/articoli senza disattivare l'account) |
| `admin_contacts.php` | Casella globale di tutte le richieste di contatto di tutti gli utenti |
| `admin_privacy.php` | Script privacy/cookie (iniettato in automatico su tutte le pagine pubbliche) + URL Privacy Policy + **Google Analytics** (solo Measurement ID, snippet generato automaticamente) |
| `admin_tracking.php` | Script Google Tag Manager (head + noscript body) e Facebook Pixel |
| `admin_smtp.php` | Configurazione SMTP completa (host/porta/tipo cifratura/credenziali/mittente) con pulsante "Invia prova"; opzione per disattivare la verifica del certificato SSL (utile con hosting che usano hostname personalizzati) |
| `admin_spotify.php` | Credenziali API Spotify (Client ID/Secret) con test di connessione |

Il primo account admin va promosso via comando SQL diretto (`UPDATE users SET is_admin=1 WHERE
email=...`); da lì in poi si possono promuovere altri utenti dall'interfaccia.

---

## 7. Sistema di autenticazione

- **Registrazione** (`register.php`): crea l'account con `email_verified=0`, genera un token di
  verifica (32 byte casuali, validità 24 ore), invia un'email con link di conferma
- **Verifica** (`verify.php`): valida il token, attiva l'account
- **Reinvio conferma** (`resend_verification.php`): rigenera il token se necessario (risposta
  identica indipendentemente dall'esito, per non rivelare quali email sono registrate)
- **Login** (`login.php`): bloccato per account non verificati o disattivati; checkbox
  "Ricordami" opzionale
- **Login persistente "ricordami"**: cookie con pattern selector/validator (32 byte casuali per
  il validator, solo l'hash SHA-256 salvato nel database); validità 30 giorni; il token viene
  **ruotato** (invalidato e riemesso) ad ogni utilizzo automatico, per limitare i danni in caso
  di furto del cookie
- **Logout**: invalida sia la sessione sia l'eventuale token "ricordami"

---

## 8. Sistema "Segui via email"

Meccanismo di following leggero (nessun account fan, solo email) per dare ai visitatori un modo
di restare aggiornati sulle novità di un artista senza registrarsi:

1. Form email sulla pagina pubblica artista (`follow.php`)
2. **Doppio opt-in obbligatorio**: email di conferma con token prima che l'iscrizione sia attiva
   (`follow_confirm.php`) — protezione anti-spam essenziale, non opzionale
3. **Notifica automatica** ai follower verificati quando l'artista pubblica un nuovo articolo
   blog o un nuovo evento (`notifyFollowersNewContent()` in `functions.php`), con link di
   disiscrizione obbligatorio in ogni email (`follow_unsubscribe.php`)
4. Contatore follower pubblico sulla pagina artista
5. Sezione dedicata nella dashboard del band manager con elenco ed evoluzione nel tempo

Non ancora implementata (valutata e rimandata): una directory pubblica per scoprire altri
artisti sulla piattaforma — rafforzerebbe l'effetto rete del sistema di following.

---

## 9. Integrazione email (SMTP)

Client SMTP scritto da zero (`app/src/mailer.php`, classe `SimpleSmtpMailer`), senza dipendenze
esterne: supporta STARTTLS (porta 587), SSL implicito (porta 465), o nessuna cifratura,
autenticazione AUTH LOGIN, e un'opzione per disattivare la verifica del certificato SSL (utile
con hosting che usano un hostname personalizzato diverso dal nome nel certificato).

**Provider attivo in produzione: SendPulse** (email transazionali approvate), configurato
interamente da `admin_smtp.php` — nessuna variabile d'ambiente da gestire.

Usato per: email di verifica registrazione, notifiche messaggi di contatto, email di conferma
"Segui", notifiche nuovi contenuti ai follower. In tutti i casi l'invio è "best effort": un
fallimento non blocca mai l'azione principale dell'utente (registrazione, pubblicazione post,
ecc.), e viene solo registrato nei log per la diagnosi (`error_log`, prefisso
`[SimpleSmtpMailer]`).

---

## 10. Integrazione Spotify

Client scritto da zero (`app/src/spotify.php`), **Client Credentials Flow** (autenticazione
app-to-app, nessun login utente Spotify richiesto):

- `getSpotifyAppToken()`: ottiene e mette in cache nel database il token di accesso (validità
  ~1 ora, rinnovo automatico)
- `spotifySearchArtist()`: ricerca artisti per nome (usata nella dashboard per il collegamento
  manuale del profilo)
- `spotifyGetArtistAlbums()` / `spotifyGetArtistTopTracks()`: dati pubblici del catalogo per la
  pagina discografia pubblica

Scelta progettuale importante: **non è stato implementato il login OAuth personale con
Spotify**, dopo un'analisi che ha evidenziato due criticità — (1) non esiste alcuna API pubblica
che colleghi un account Spotify personale al relativo profilo Artista, quindi l'automatismo
richiesto in origine non è tecnicamente realizzabile; (2) le nuove app Spotify sono limitate a
25 utenti finché non approvata la "Extended Quota Mode". Il Client Credentials Flow non è
soggetto a questa restrizione, quindi la funzionalità realizzata è pienamente utilizzabile da
subito da tutti gli utenti della piattaforma.

---

## 11. Sicurezza

- Password hashate con `password_hash` (bcrypt)
- Query parametrizzate ovunque (PDO prepared statements) contro SQL injection
- Token CSRF su tutti i form POST (`csrfField()`/`checkCsrf()`)
- Validazione estensioni sui file caricati (immagini e audio)
- Slug riservati (whitelist `RESERVED_SLUGS`) per evitare collisioni tra le pagine di sistema e
  gli slug scelti dagli utenti
- Cookie di sessione e "ricordami" con `httponly`, `samesite=Lax`, `secure` quando su HTTPS
- Password/secret (SMTP, Spotify) mai esposte in chiaro nei form (campo vuoto = "non modificare
  il valore esistente")
- `display_errors` disattivato in produzione (nessuna informazione tecnica esposta a visitatori)

---

## 12. Workflow di sviluppo e deploy

- Repository GitHub pubblico `posillipo/chifacosa`
- Deploy su Portainer collegato direttamente al repository (build method "Repository"),
  aggiornamento con un clic ("Pull and redeploy")
- Regola operativa stabilita: **ogni modifica al codice passa esclusivamente da GitHub +
  redeploy**, mai interventi manuali dentro i container in produzione — l'unica eccezione sono i
  comandi SQL sui *dati* (migrazioni), mai sul codice
- Ogni modifica allo schema del database è documentata in `MIGRAZIONI.md` (12 migrazioni
  applicate finora, in ordine cronologico) prima ancora di essere eseguita in produzione
- Reverse proxy Nginx Proxy Manager con certificato SSL Let's Encrypt automatico

## 13. Documentazione del progetto

10 file `.md` nella root del repository (ridotti da un totale di 33 durante una pulizia di
consolidamento, poi arricchiti con due nuove guide specifiche): `README.md` (indice +
panoramica), `LOCAL_TESTING.md`, `GITHUB.md`, `DEPLOY.md`, `ADMIN_SETUP.md`, `SMTP.md`,
`SPOTIFY_SETUP.md`, `COOKIE_PREFERENZE_SETUP.md`, `MIGRAZIONI.md`, `SCHEMA_DATABASE.md`.
